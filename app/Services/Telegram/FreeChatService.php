<?php

namespace App\Services\Telegram;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\User;
use App\Services\Rag\QuestionAnsweringService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Telegram\Bot\Objects\Message;
use Throwable;

/**
 * FR-015/FR-015a/FR-016: вільний чат з AI через існуючий RAG-пайплайн.
 *
 * Поки модель відповідає, користувач бачить заглушку з випадковою фразою
 * («Думаю…», «Шукаю в документах…»), яка змінюється на кожному етапі
 * агентного циклу й наприкінці замінюється відповіддю. Одночасно в роботі
 * лише одне питання користувача — повторні отримують «зачекайте».
 */
class FreeChatService
{
    /**
     * Lock не коротший за таймаут задачі обробки update — щоб не спливав
     * посеред відповіді, але й завислий воркер не блокував користувача навічно.
     */
    private const LOCK_SECONDS = ProcessTelegramUpdateJob::TIMEOUT_SECONDS + 20;

    public function __construct(
        private QuestionAnsweringService $questionAnswering,
        private TelegramClient $telegram,
    ) {}

    public static function lockKey(User $user): string
    {
        return 'telegram-free-chat:'.$user->id;
    }

    public function handle(User $user, string $question): void
    {
        $chatId = (int) $user->telegram_chat_id;
        $maxLength = (int) config('services.telegram.question_max_length');

        // Задовге питання не займає lock і не рахується в ліміти —
        // користувач лише отримує підказку скоротити його.
        if (mb_strlen($question) > $maxLength) {
            $this->telegram->sendMessage($chatId, __('telegram.question_too_long', ['max' => $maxLength], $user->language));

            return;
        }

        $lock = Cache::lock(self::lockKey($user), self::LOCK_SECONDS);

        // Попереднє питання ще в роботі — не запускаємо другий платний
        // агентний цикл і не рахуємо це питання в ліміти.
        if (! $lock->get()) {
            $this->telegram->sendMessage($chatId, __('telegram.free_chat_busy', [], $user->language));

            return;
        }

        try {
            if (! $this->passesRateLimits($user)) {
                return;
            }

            $this->answer($user, $question);
        } finally {
            $lock->release();
        }
    }

    private function answer(User $user, string $question): void
    {
        $chatId = (int) $user->telegram_chat_id;
        $placeholder = $this->sendPlaceholder($chatId, $user->language);
        $currentStage = 'thinking';

        $onProgress = function (string $stage) use ($chatId, $placeholder, &$currentStage, $user): void {
            if ($placeholder === null || $stage === $currentStage) {
                return;
            }

            $currentStage = $stage;
            $this->safely(function () use ($chatId, $placeholder, $stage, $user) {
                $this->telegram->editMessageText($chatId, $placeholder->messageId, $this->randomPhrase($stage, $user->language));
                $this->telegram->sendChatAction($chatId);
            });
        };

        try {
            $log = $this->questionAnswering->ask($question, $user->conversation_session_id, $onProgress);

            if ($user->conversation_session_id !== $log->conversation_session_id) {
                $user->update(['conversation_session_id' => $log->conversation_session_id]);
            }

            $reply = $log->answer;
        } catch (Throwable $e) {
            report($e);
            $reply = __('telegram.free_chat_error', [], $user->language);
        }

        $this->replacePlaceholder($chatId, $placeholder, $reply);
    }

    /**
     * Заглушка "думаю…" + статус "друкує…". Збій Telegram тут не має
     * зірвати відповідь — тоді просто надішлемо її окремим повідомленням.
     */
    private function sendPlaceholder(int $chatId, string $locale): ?Message
    {
        $this->safely(fn () => $this->telegram->sendChatAction($chatId));

        return $this->safely(fn () => $this->telegram->sendMessage($chatId, $this->randomPhrase('thinking', $locale)));
    }

    /**
     * Відповідь, що влазить в одне повідомлення, замінює заглушку на місці.
     * Довшу editMessageText не прийме — тоді прибираємо заглушку й
     * надсилаємо відповідь звичайним sendMessage (він ріже на частини).
     */
    private function replacePlaceholder(int $chatId, ?Message $placeholder, string $reply): void
    {
        if ($placeholder !== null && mb_strlen($reply) <= TelegramClient::MESSAGE_LIMIT) {
            $edited = $this->safely(fn () => $this->telegram->editMessageText($chatId, $placeholder->messageId, $reply));

            if ($edited !== null) {
                return;
            }
        }

        if ($placeholder !== null) {
            $this->safely(fn () => $this->telegram->deleteMessage($chatId, $placeholder->messageId));
        }

        $this->telegram->sendMessage($chatId, $reply);
    }

    /**
     * Захист бюджету OpenAI: кількість питань від одного користувача за
     * хвилину та за добу.
     */
    private function passesRateLimits(User $user): bool
    {
        $limits = [
            ['telegram-free-chat-minute:'.$user->id, (int) config('services.telegram.free_chat_per_minute'), 60, 'telegram.free_chat_rate_limited_minute'],
            ['telegram-free-chat-day:'.$user->id, (int) config('services.telegram.free_chat_per_day'), 86400, 'telegram.free_chat_rate_limited_day'],
        ];

        foreach ($limits as [$key, $max, , $messageKey]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $this->telegram->sendMessage((int) $user->telegram_chat_id, __($messageKey, [], $user->language));

                return false;
            }
        }

        foreach ($limits as [$key, , $decaySeconds]) {
            RateLimiter::hit($key, $decaySeconds);
        }

        return true;
    }

    private function randomPhrase(string $stage, string $locale): string
    {
        $phrases = __("telegram.thinking_phrases.{$stage}", [], $locale);

        return is_array($phrases) && $phrases !== [] ? $phrases[array_rand($phrases)] : '…';
    }

    /**
     * Допоміжні виклики Telegram (індикатор прогресу) не мають права зламати
     * основну відповідь: збій лише репортимо.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T|null
     */
    private function safely(callable $call): mixed
    {
        try {
            return $call();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
