<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\OneTimeCodeService;
use App\Services\Telegram\TelegramClient;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

/**
 * Обмеження за IP поверх обмеження за номером: не дає одному клієнту
 * перебирати номери (enumeration / спам кодами адмінам) і підбирати код.
 */
class OtpIpRateLimitTest extends TestCase
{
    use SeedsRoles;

    public function test_code_requests_from_one_ip_are_limited_across_different_phones(): void
    {
        config(['services.telegram.otp_requests_per_ip_per_minute' => 2]);
        $this->mock(TelegramClient::class, fn ($mock) => $mock->shouldNotReceive('sendMessage'));

        $service = app(OneTimeCodeService::class);

        $outcomes = [
            $service->requestCode('+15559990101', '10.0.0.1')['outcome'],
            $service->requestCode('+15559990102', '10.0.0.1')['outcome'],
            $service->requestCode('+15559990103', '10.0.0.1')['outcome'],
            // інший IP — власний лічильник
            $service->requestCode('+15559990104', '10.0.0.2')['outcome'],
        ];

        $this->assertSame(['denied', 'denied', 'rate_limited', 'denied'], $outcomes);
    }

    public function test_code_verification_from_one_ip_is_limited(): void
    {
        config([
            'services.telegram.otp_verify_per_ip_per_minute' => 2,
            'services.telegram.otp_max_attempts' => 100,
        ]);

        $this->seedRoles();
        $admin = User::factory()->create(['phone' => '+15559990110', 'telegram_chat_id' => 77]);
        $admin->assignRole(UserRole::Admin);

        $sentCode = null;
        $this->mock(TelegramClient::class, function ($mock) use (&$sentCode) {
            $mock->shouldReceive('sendMessage')->once()->withArgs(function (int $chatId, string $text) use (&$sentCode) {
                preg_match('/\d{6}/', $text, $m);
                $sentCode = $m[0];

                return true;
            });
        });

        $service = app(OneTimeCodeService::class);
        $service->requestCode('+15559990110', '10.0.0.5');

        $this->assertSame('invalid', $service->verifyCode('+15559990110', '000000', '10.0.0.5')['outcome']);
        $this->assertSame('invalid', $service->verifyCode('+15559990110', '000001', '10.0.0.5')['outcome']);
        // Ліміт вичерпано — навіть правильний код не перевіряється
        $this->assertSame('rate_limited', $service->verifyCode('+15559990110', $sentCode, '10.0.0.5')['outcome']);
    }
}
