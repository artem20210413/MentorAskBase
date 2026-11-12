<?php

namespace Tests\Feature\Telegram;

use App\Models\User;
use App\Services\Telegram\TelegramClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeTelegramClient;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

/**
 * FR-002: до реєстрації мова береться з мови Telegram-клієнта
 * (from.language_code), якщо бот її підтримує; інакше — українська.
 */
class OnboardingLanguageTest extends TestCase
{
    use PostsTelegramUpdates;

    private FakeTelegramClient $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.moderation_chat_id' => -100]);
        $this->telegram = new FakeTelegramClient;
        $this->app->instance(TelegramClient::class, $this->telegram);
    }

    public static function languageCodes(): array
    {
        return [
            'українська' => ['uk', 'uk'],
            'російська' => ['ru', 'ru'],
            'англійська' => ['en', 'en'],
            'регіональний варіант' => ['en-GB', 'en'],
            'непідтримувана → uk' => ['de', 'uk'],
            'не передано → uk' => [null, 'uk'],
        ];
    }

    #[DataProvider('languageCodes')]
    public function test_greeting_uses_telegram_client_language(?string $languageCode, string $expectedLocale): void
    {
        $this->postTelegramUpdate($this->telegramMessage(990, 990, '/start', languageCode: $languageCode))->assertOk();

        $this->assertSame([__('telegram.greeting', [], $expectedLocale)], $this->telegram->texts());
    }

    public function test_new_user_gets_language_of_telegram_client(): void
    {
        $this->postTelegramUpdate($this->telegramContact(991, 991, '+15550003000', 'neo', 'en'))->assertOk();

        $user = User::where('telegram_chat_id', 991)->firstOrFail();
        $this->assertSame('en', $user->language);
        $this->assertSame(__('telegram.application_submitted', [], 'en'), last($this->telegram->texts()));
    }

    public function test_foreign_contact_warning_uses_telegram_client_language(): void
    {
        $update = $this->telegramForeignContact(992, 992, '+15550003001', 123);
        $update['message']['from']['language_code'] = 'ru';

        $this->postTelegramUpdate($update)->assertOk();

        $this->assertSame([__('telegram.contact_not_own', [], 'ru')], $this->telegram->texts());
    }
}
