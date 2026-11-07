<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\OneTimeCodeService;
use App\Services\Telegram\TelegramClient;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

class OtpRateLimitTest extends TestCase
{
    use SeedsRoles;

    public function test_more_than_three_requests_per_minute_are_rejected(): void
    {
        $this->seedRoles();

        $admin = User::factory()->create(['phone' => '+15559990010']);
        $admin->assignRole(UserRole::Admin);

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')->times(3);
        });

        $service = app(OneTimeCodeService::class);

        $outcomes = [];
        for ($i = 0; $i < 4; $i++) {
            $outcomes[] = $service->requestCode('+15559990010')['outcome'];
        }

        $this->assertSame(['sent', 'sent', 'sent', 'rate_limited'], $outcomes);
    }
}
