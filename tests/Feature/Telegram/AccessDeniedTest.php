<?php

namespace Tests\Feature\Telegram;

use App\Models\User;
use App\Services\Telegram\TelegramClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

class AccessDeniedTest extends TestCase
{
    use PostsTelegramUpdates;

    /**
     * @return array<string, array{0: string}>
     */
    public static function statuses(): array
    {
        return [
            'pending' => ['pending'],
            'rejected' => ['rejected'],
            'deactivated' => ['deactivated'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_non_approved_user_gets_access_denied_message(string $status): void
    {
        User::factory()->create(['telegram_chat_id' => 940, 'status' => $status]);

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (int $chatId, string $text) => $chatId === 940 && $text !== '');
        });

        $this->postTelegramUpdate($this->telegramMessage(940, 940, 'Привіт'))->assertOk();
    }
}
