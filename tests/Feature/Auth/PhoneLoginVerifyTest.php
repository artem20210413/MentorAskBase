<?php

namespace Tests\Feature\Auth;

use App\Models\OneTimeCode;
use App\Models\User;
use App\Services\Auth\OneTimeCodeService;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

class PhoneLoginVerifyTest extends TestCase
{
    use SeedsRoles;

    public function test_correct_code_succeeds(): void
    {
        $this->seedRoles();
        $admin = User::factory()->create(['phone' => '+15559990030']);
        $admin->assignRole('admin');

        OneTimeCode::create([
            'user_id' => $admin->id,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
        ]);

        $result = app(OneTimeCodeService::class)->verifyCode('+15559990030', '123456');

        $this->assertSame('success', $result['outcome']);
        $this->assertNotNull(OneTimeCode::where('user_id', $admin->id)->first()->consumed_at);
    }

    public function test_code_rejected_after_three_wrong_attempts(): void
    {
        $this->seedRoles();
        $admin = User::factory()->create(['phone' => '+15559990031']);
        $admin->assignRole('admin');

        OneTimeCode::create([
            'user_id' => $admin->id,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
        ]);

        $service = app(OneTimeCodeService::class);
        $outcomes = [
            $service->verifyCode('+15559990031', '000000')['outcome'],
            $service->verifyCode('+15559990031', '000000')['outcome'],
            $service->verifyCode('+15559990031', '000000')['outcome'],
            $service->verifyCode('+15559990031', '123456')['outcome'],
        ];

        $this->assertSame(['invalid', 'invalid', 'invalid', 'invalid'], $outcomes);
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->seedRoles();
        $admin = User::factory()->create(['phone' => '+15559990032']);
        $admin->assignRole('admin');

        OneTimeCode::create([
            'user_id' => $admin->id,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->subMinute(),
        ]);

        $result = app(OneTimeCodeService::class)->verifyCode('+15559990032', '123456');

        $this->assertSame('invalid', $result['outcome']);
    }
}
