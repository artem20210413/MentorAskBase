<?php

namespace App\Jobs;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramUpdateHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Telegram\Bot\Objects\Update;
use Throwable;

/**
 * Обробка одного Telegram-update поза HTTP-запитом webhook. Webhook одразу
 * відповідає 200, а довгі операції (агентний цикл OpenAI, AI-резюме) не
 * блокують доставку наступних оновлень іншим користувачам бота.
 *
 * Окрема черга `telegram`, щоб відповіді бота не стояли за довгою
 * обробкою документів у черзі `default`.
 */
class ProcessTelegramUpdateJob implements ShouldQueue
{
    use Queueable;

    /**
     * Без автоматичних повторів: повтор після часткової обробки означав би
     * подвійну відповідь користувачу й подвійну витрату токенів.
     */
    public int $tries = 1;

    /** Агентний цикл (до rag.agent.max_tool_steps кроків + web_search). */
    public const TIMEOUT_SECONDS = 180;

    public int $timeout = self::TIMEOUT_SECONDS;

    public function __construct(public readonly array $payload)
    {
        $this->onQueue('telegram');
    }

    public function handle(TelegramUpdateHandler $handler, TelegramClient $telegram): void
    {
        // Режим без черги (dispatchAfterResponse) виконується у веб-процесі,
        // де max_execution_time зазвичай 30–60 с — агентному циклу мало.
        // У CLI-воркері ліміту й так немає, виклик нешкідливий.
        if (function_exists('set_time_limit')) {
            set_time_limit(self::TIMEOUT_SECONDS);
        }

        try {
            $handler->handle(new Update($this->payload));
        } catch (Throwable $e) {
            report($e);
            $this->notifyErrorChat($telegram, $e);
        }
    }

    /**
     * Спостережуваність: будь-яка необроблена помилка обробки update одразу
     * видна в окремому технічному чаті (не в чаті модерації — модераторам це
     * не потрібно бачити).
     */
    private function notifyErrorChat(TelegramClient $telegram, Throwable $e): void
    {
        $errorChatId = config('services.telegram.error_chat_id');

        if (! $errorChatId) {
            return;
        }

        $text = '⚠️ ['.config('app.env').'] Помилка в обробці Telegram-webhook'."\n"
            .get_class($e).': '.$e->getMessage()."\n"
            .$e->getFile().':'.$e->getLine();

        try {
            $telegram->sendMessage($errorChatId, mb_substr($text, 0, 4000));
        } catch (Throwable $notifyException) {
            report($notifyException);
        }
    }
}
