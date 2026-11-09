<?php

namespace App\Services\Telegram;

use App\Models\User;
use App\Services\Rag\QuestionAnsweringService;
use Illuminate\Support\Facades\RateLimiter;
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
        if (! $this->passesLimits($user, $question)) {
            return;
        }

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

    /**
     * Захист бюджету OpenAI: довжина питання й кількість питань від одного
     * користувача (за хвилину та за добу). Задовге питання не рахується в
     * ліміти — користувач лише отримує підказку скоротити його.
     */
    private function passesLimits(User $user, string $question): bool
    {
        $chatId = (int) $user->telegram_chat_id;
        $maxLength = (int) config('services.telegram.question_max_length');

        if (mb_strlen($question) > $maxLength) {
            $this->telegram->sendMessage($chatId, __('telegram.question_too_long', ['max' => $maxLength], $user->language));

            return false;
        }

        $limits = [
            ['telegram-free-chat-minute:'.$user->id, (int) config('services.telegram.free_chat_per_minute'), 60, 'telegram.free_chat_rate_limited_minute'],
            ['telegram-free-chat-day:'.$user->id, (int) config('services.telegram.free_chat_per_day'), 86400, 'telegram.free_chat_rate_limited_day'],
        ];

        foreach ($limits as [$key, $max, , $messageKey]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $this->telegram->sendMessage($chatId, __($messageKey, [], $user->language));

                return false;
            }
        }

        foreach ($limits as [$key, , $decaySeconds]) {
            RateLimiter::hit($key, $decaySeconds);
        }

        return true;
    }
}
