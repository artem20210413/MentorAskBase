<?php

namespace Tests\Feature\Telegram;

use App\Services\Telegram\TelegramClient;
use Telegram\Bot\Objects\Message;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

class ApprovalRequestCreatedTest extends TestCase
{
    use PostsTelegramUpdates;

    public function test_sharing_contact_creates_pending_user_and_sends_card_to_moderation_chat(): void
    {
        $moderationChatId = (int) config('services.telegram.moderation_chat_id');

        $this->mock(TelegramClient::class, function ($mock) use ($moderationChatId) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(function (int $chatId, string $text, ?array $replyMarkup) use ($moderationChatId) {
                    return $chatId === $moderationChatId
                        && str_contains($text, 'Нова заявка')
                        && str_starts_with((string) $replyMarkup['inline_keyboard'][0][0]['callback_data'], 'approval:')
                        && str_ends_with((string) $replyMarkup['inline_keyboard'][0][0]['callback_data'], ':approve');
                })
                ->andReturn(new Message(['message_id' => 4242, 'date' => time(), 'chat' => ['id' => $moderationChatId]]));

            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (int $chatId, string $text) => $chatId === 333 && str_contains($text, 'розгляд'))
                ->andReturn(new Message(['message_id' => 1, 'date' => time(), 'chat' => ['id' => 333]]));
        });

        $response = $this->postTelegramUpdate(
            $this->telegramContact(333, 333, '+15559998888', 'newbie'),
        );

        $response->assertOk();

        $this->assertDatabaseHas('users', [
            'telegram_chat_id' => 333,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('user_approval_requests', [
            'status' => 'pending',
            'moderation_message_id' => 4242,
        ]);
    }
}
