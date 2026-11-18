<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Services\Auth\OneTimeCodeService;
use App\Services\Telegram\TelegramClient;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

class OtpInvalidatesPreviousTest extends TestCase
{
    use SeedsRoles;

    public function test_requesting_new_code_invalidates_previous_one(): void
    {
        $this->seedRoles();

        $admin = User::factory()->create(['phone' => '+15559990020']);
        $admin->assignRole(UserRole::Admin);

        $this->mock(TelegramClient::class, fn ($mock) => $mock->shouldReceive('sendMessage')->twice());

        $service = app(OneTimeCodeService::class);

        $service->requestCode('+15559990020');
        $firstCode = OneTimeCode::where('user_id', $admin->id)->latest('id')->first();

        $service->requestCode('+15559990020');

        $this->assertNotNull($firstCode->refresh()->invalidated_at);
        $this->assertDatabaseHas('one_time_codes', [
            'user_id' => $admin->id,
            'invalidated_at' => null,
        ]);
        $this->assertSame(2, OneTimeCode::where('user_id', $admin->id)->count());
    }
}
