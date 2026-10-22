<?php

namespace App\Services\Telegram;

use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;

/**
 * Тонка обгортка над irazasyed/telegram-bot-sdk (research.md #1).
 * Токен береться виключно з конфігурації (FR-004a), не з пакетного config/telegram.php.
 */
class TelegramClient
{
    private Api $api;

    public function __construct()
    {
        $this->api = new Api(config('services.telegram.bot_token'));
    }

    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): Message
    {
        return $this->api->sendMessage($this->withMarkup([
            'chat_id' => $chatId,
            'text' => $text,
        ], $replyMarkup));
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, ?array $replyMarkup = null): Message
    {
        return $this->api->editMessageText($this->withMarkup([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ], $replyMarkup));
    }

    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $replyMarkup = null): Message
    {
        return $this->api->editMessageReplyMarkup($this->withMarkup([
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ], $replyMarkup));
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): bool
    {
        return $this->api->answerCallbackQuery(array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ]));
    }

    public function setWebhook(string $url): bool
    {
        return $this->api->setWebhook(['url' => $url]);
    }

    private function withMarkup(array $params, ?array $replyMarkup): array
    {
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        return $params;
    }
}
