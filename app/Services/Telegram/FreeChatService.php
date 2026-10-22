<?php

namespace App\Services\Telegram;

use App\Models\User;
use App\Services\Rag\QuestionAnsweringService;
use Throwable;

/**
 * FR-015/FR-015a/FR-016: вільний чат з AI через існуючий RAG-пайплайн.
 */
class FreeChatService
{
    public function __construct(
        private QuestionAnsweringService $questionAnswering,
        private TelegramClient $telegram,
    ) {}

    public function handle(User $user, string $question): void
    {
        try {
            $log = $this->questionAnswering->ask($question, $user->conversation_session_id);

            if ($user->conversation_session_id !== $log->conversation_session_id) {
                $user->update(['conversation_session_id' => $log->conversation_session_id]);
            }

            $this->telegram->sendMessage((int) $user->telegram_chat_id, $log->answer);
        } catch (Throwable $e) {
            report($e);
            $this->telegram->sendMessage(
                (int) $user->telegram_chat_id,
                __('telegram.free_chat_error', [], $user->language),
            );
        }
    }
}
