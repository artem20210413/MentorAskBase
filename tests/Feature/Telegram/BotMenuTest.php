<?php

namespace Tests\Feature\Telegram;

use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

class BotMenuTest extends TestCase
{
    use PostsTelegramUpdates;

    public function test_approved_user_opening_menu_sees_main_options(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => 900]);

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (int $chatId, string $text, ?array $markup) => $chatId === 900
                    && str_contains((string) $markup['keyboard'][0][0], 'Мова'));
        });

        $this->postTelegramUpdate($this->telegramMessage(900, 900, '/start'))->assertOk();
    }

    public function test_choosing_language_saves_it_on_profile(): void
    {
        $user = User::factory()->create(['telegram_chat_id' => 901, 'language' => 'uk']);

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')->twice();
        });

        $this->postTelegramUpdate($this->telegramMessage(901, 901, 'English'))->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'language' => 'en']);
    }
}
