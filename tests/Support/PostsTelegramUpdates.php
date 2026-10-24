<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

trait PostsTelegramUpdates
{
    protected function postTelegramUpdate(array $update): TestResponse
    {
        return $this->postJson('/api/telegram/webhook/'.config('services.telegram.webhook_secret'), $update);
    }

    protected function telegramMessage(int $chatId, int $fromId, string $text, ?string $username = null): array
    {
        return [
            'update_id' => random_int(1, 1_000_000),
            'message' => [
                'message_id' => random_int(1, 1_000_000),
                'date' => time(),
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Test', 'username' => $username],
                'text' => $text,
            ],
        ];
    }

    protected function telegramContact(int $chatId, int $fromId, string $phone, ?string $username = null): array
    {
        return [
            'update_id' => random_int(1, 1_000_000),
            'message' => [
                'message_id' => random_int(1, 1_000_000),
                'date' => time(),
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Test', 'username' => $username],
                'contact' => ['phone_number' => $phone, 'first_name' => 'Test', 'user_id' => $fromId],
            ],
        ];
    }

    protected function telegramCallbackQuery(int $fromId, string $data, int $messageId = 999, int $chatId = 111): array
    {
        return [
            'update_id' => random_int(1, 1_000_000),
            'callback_query' => [
                'id' => (string) random_int(1, 1_000_000),
                'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Test'],
                'message' => [
                    'message_id' => $messageId,
                    'date' => time(),
                    'chat' => ['id' => $chatId, 'type' => 'supergroup'],
                ],
                'chat_instance' => 'abc',
                'data' => $data,
            ],
        ];
    }
}
