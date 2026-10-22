<?php

namespace Tests\Feature\Telegram;

use App\Services\Telegram\TelegramClient;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use PostsTelegramUpdates;

    public function test_start_from_unregistered_user_sends_greeting_with_contact_button(): void
    {
        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(function (int $chatId, string $text, ?array $replyMarkup) {
                    return $chatId === 555
                        && str_contains($text, 'Привіт')
                        && $replyMarkup['keyboard'][0][0]['request_contact'] === true;
                });
        });

        $response = $this->postTelegramUpdate($this->telegramMessage(555, 555, '/start'));

        $response->assertOk();
    }
}
