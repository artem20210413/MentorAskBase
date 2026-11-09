<?php

namespace Tests\Feature\Telegram;

use App\Services\Telegram\TelegramClient;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

/**
 * Безпека: користувач може надіслати в бот будь-який контакт зі своєї
 * телефонної книги. Реєструвати дозволено лише власний номер — інакше можна
 * "захопити" чужий телефон (зокрема номер майбутнього адміна).
 */
class OnboardingForeignContactTest extends TestCase
{
    use PostsTelegramUpdates;

    public function test_contact_of_another_telegram_user_is_rejected(): void
    {
        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (int $chatId, string $text, ?array $markup = null) => $chatId === 700
                    && $text === __('telegram.contact_not_own', [], 'uk')
                    && $markup['keyboard'][0][0]['request_contact'] === true);
        });

        $this->postTelegramUpdate($this->telegramForeignContact(700, 700, '+15550001111', 999999))->assertOk();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_approval_requests', 0);
    }

    public function test_contact_without_telegram_user_id_is_rejected(): void
    {
        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (int $chatId, string $text) => $chatId === 701
                    && $text === __('telegram.contact_not_own', [], 'uk'));
        });

        $this->postTelegramUpdate($this->telegramForeignContact(701, 701, '+15550001112', null))->assertOk();

        $this->assertDatabaseCount('users', 0);
    }
}
