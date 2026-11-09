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

    /**
     * Контакт, яким користувач поділився, але який належить іншій людині
     * (чужий user_id) або взагалі не прив'язаний до Telegram (user_id = null).
     */
    protected function telegramForeignContact(int $chatId, int $fromId, string $phone, ?int $contactUserId): array
    {
        $update = $this->telegramContact($chatId, $fromId, $phone);
        $update['message']['contact']['user_id'] = $contactUserId;

        if ($contactUserId === null) {
            unset($update['message']['contact']['user_id']);
        }

        return $update;
    }

    protected function telegramSticker(int $chatId, int $fromId): array
    {
        return [
            'update_id' => random_int(1, 1_000_000),
            'message' => [
                'message_id' => random_int(1, 1_000_000),
                'date' => time(),
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Test'],
                'sticker' => ['file_id' => 'sticker-file', 'file_unique_id' => 'u1', 'width' => 512, 'height' => 512, 'is_animated' => false, 'is_video' => false, 'type' => 'regular'],
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
