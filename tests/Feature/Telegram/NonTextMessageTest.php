<?php

namespace Tests\Feature\Telegram;

use App\Models\User;
use App\Services\Rag\QuestionAnsweringService;
use App\Services\Telegram\TelegramClient;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

class NonTextMessageTest extends TestCase
{
    use PostsTelegramUpdates;

    public function test_sticker_is_not_sent_to_llm_and_user_gets_hint(): void
    {
        User::factory()->create(['telegram_chat_id' => 940, 'language' => 'uk']);

        $this->mock(QuestionAnsweringService::class, fn ($mock) => $mock->shouldNotReceive('ask'));

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (int $chatId, string $text) => $chatId === 940
                    && $text === __('telegram.text_only', [], 'uk'));
        });

        $this->postTelegramUpdate($this->telegramSticker(940, 940))->assertOk();

        $this->assertDatabaseCount('query_logs', 0);
    }

    public function test_whitespace_only_text_is_not_sent_to_llm(): void
    {
        User::factory()->create(['telegram_chat_id' => 941, 'language' => 'uk']);

        $this->mock(QuestionAnsweringService::class, fn ($mock) => $mock->shouldNotReceive('ask'));
        $this->mock(TelegramClient::class, fn ($mock) => $mock->shouldReceive('sendMessage')->once());

        $this->postTelegramUpdate($this->telegramMessage(941, 941, "   \n "))->assertOk();
    }
}
