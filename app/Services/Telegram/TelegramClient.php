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
    /**
     * Telegram відхиляє повідомлення довші за 4096 символів. Тримаємо запас
     * (4096 рахується в UTF-16, де емодзі важать 2) — довгий текст (напр.
     * RAG-відповідь) ріжемо на кілька повідомлень.
     */
    public const MESSAGE_LIMIT = 4000;

    private Api $api;

    public function __construct()
    {
        $this->api = new Api(config('services.telegram.bot_token'));
    }

    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): Message
    {
        $chunks = $this->splitText($text);
        $lastIndex = count($chunks) - 1;
        $message = null;

        foreach ($chunks as $index => $chunk) {
            // Клавіатуру чіпляємо лише до останнього фрагмента, щоб вона не
            // дублювалася під кожним шматком розбитого повідомлення.
            $markup = $index === $lastIndex ? $replyMarkup : null;

            $message = $this->api->sendMessage($this->withMarkup([
                'chat_id' => $chatId,
                'text' => $chunk,
            ], $markup));
        }

        return $message;
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

    public function deleteMessage(int|string $chatId, int $messageId): bool
    {
        return $this->api->deleteMessage([
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    /**
     * Статус "друкує…" у шапці чату (Telegram показує його ~5 секунд або до
     * наступного повідомлення бота).
     */
    public function sendChatAction(int|string $chatId, string $action = 'typing'): bool
    {
        return $this->api->sendChatAction([
            'chat_id' => $chatId,
            'action' => $action,
        ]);
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

    /**
     * Ріже текст на фрагменти не довші за MESSAGE_LIMIT, намагаючись
     * розривати на межі рядка/пробілу, щоб не рвати слова посередині.
     *
     * @return array<int, string>
     */
    private function splitText(string $text): array
    {
        if (mb_strlen($text) <= self::MESSAGE_LIMIT) {
            return [$text];
        }

        $chunks = [];
        $remaining = $text;

        while (mb_strlen($remaining) > self::MESSAGE_LIMIT) {
            $slice = mb_substr($remaining, 0, self::MESSAGE_LIMIT);

            // Межа розриву: останній перенос рядка, інакше останній пробіл.
            $breakpoint = max(
                (int) mb_strrpos($slice, "\n"),
                (int) mb_strrpos($slice, ' '),
            );

            // Суцільний текст без пробілів — ріжемо жорстко по ліміту.
            if ($breakpoint === 0) {
                $breakpoint = self::MESSAGE_LIMIT;
            }

            $chunks[] = mb_substr($remaining, 0, $breakpoint);
            $remaining = ltrim(mb_substr($remaining, $breakpoint));
        }

        if ($remaining !== '') {
            $chunks[] = $remaining;
        }

        return $chunks;
    }
}
