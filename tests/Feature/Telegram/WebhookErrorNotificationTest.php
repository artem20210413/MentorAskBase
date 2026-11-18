<?php

namespace Tests\Feature\Telegram;

use App\Models\User;
use App\Services\Telegram\BotMenuService;
use App\Services\Telegram\TelegramClient;
use RuntimeException;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

class WebhookErrorNotificationTest extends TestCase
{
    use PostsTelegramUpdates;

    public function test_unhandled_error_is_sent_to_dedicated_error_chat_when_configured(): void
    {
        config(['services.telegram.error_chat_id' => -999]);

        $user = User::factory()->create(['telegram_chat_id' => 950]);

        $this->mock(BotMenuService::class, function ($mock) {
            $mock->shouldReceive('handle')->andThrow(new RuntimeException('boom'));
        });

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (int $chatId, string $text) => $chatId === -999
                    && str_contains($text, '['.config('app.env').']')
                    && str_contains($text, 'RuntimeException')
                    && str_contains($text, 'boom'));
        });

        $this->postTelegramUpdate($this->telegramMessage(950, 950, 'щось'))->assertOk();
    }

    public function test_unhandled_error_is_not_sent_anywhere_when_error_chat_not_configured(): void
    {
        config(['services.telegram.error_chat_id' => null]);

        $user = User::factory()->create(['telegram_chat_id' => 951]);

        $this->mock(BotMenuService::class, function ($mock) {
            $mock->shouldReceive('handle')->andThrow(new RuntimeException('boom'));
        });

        $this->mock(TelegramClient::class, fn ($mock) => $mock->shouldNotReceive('sendMessage'));

        $this->postTelegramUpdate($this->telegramMessage(951, 951, 'щось'))->assertOk();
    }
}
