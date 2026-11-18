<?php

namespace Tests\Support;

use App\Services\Telegram\TelegramClient;
use RuntimeException;
use Telegram\Bot\Objects\Message;

/**
 * Записує всі виклики Telegram API без мережі — для сценаріїв, де важлива
 * послідовність дій (заглушка → редагування → відповідь), а не один виклик.
 */
class FakeTelegramClient extends TelegramClient
{
    /** @var array<int, array{method: string, chat_id: int|string, message_id?: int, text?: string, action?: string}> */
    public array $calls = [];

    /** Методи, виклик яких має кинути виняток (імітація збою Telegram API). */
    public array $failing = [];

    private int $nextMessageId = 1000;

    public function __construct() {}

    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): Message
    {
        $this->failIfNeeded('sendMessage');
        $messageId = $this->nextMessageId++;
        $this->calls[] = ['method' => 'sendMessage', 'chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];

        return new Message(['message_id' => $messageId, 'chat' => ['id' => $chatId], 'text' => $text]);
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, ?array $replyMarkup = null): Message
    {
        $this->failIfNeeded('editMessageText');
        $this->calls[] = ['method' => 'editMessageText', 'chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];

        return new Message(['message_id' => $messageId, 'chat' => ['id' => $chatId], 'text' => $text]);
    }

    public function deleteMessage(int|string $chatId, int $messageId): bool
    {
        $this->failIfNeeded('deleteMessage');
        $this->calls[] = ['method' => 'deleteMessage', 'chat_id' => $chatId, 'message_id' => $messageId];

        return true;
    }

    public function sendChatAction(int|string $chatId, string $action = 'typing'): bool
    {
        $this->failIfNeeded('sendChatAction');
        $this->calls[] = ['method' => 'sendChatAction', 'chat_id' => $chatId, 'action' => $action];

        return true;
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): bool
    {
        $this->calls[] = ['method' => 'answerCallbackQuery', 'chat_id' => 0, 'text' => (string) $text];

        return true;
    }

    /** @return array<int, array<string, mixed>> */
    public function callsOf(string $method): array
    {
        return array_values(array_filter($this->calls, fn (array $c) => $c['method'] === $method));
    }

    /** Тексти, які користувач бачить останніми: надіслані та відредаговані повідомлення по порядку. */
    public function texts(): array
    {
        return array_values(array_map(
            fn (array $c) => $c['text'],
            array_filter($this->calls, fn (array $c) => in_array($c['method'], ['sendMessage', 'editMessageText'], true)),
        ));
    }

    private function failIfNeeded(string $method): void
    {
        if (in_array($method, $this->failing, true)) {
            throw new RuntimeException("Telegram {$method} failed");
        }
    }
}
