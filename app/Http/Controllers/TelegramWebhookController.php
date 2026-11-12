<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramWebhookLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Telegram\Bot\Objects\Update;
use Throwable;

/**
 * contracts/telegram-webhook.md: єдина точка входу webhook.
 * Завжди відповідає 200 — Telegram повторює доставку при інших кодах.
 *
 * Контролер лише перевіряє секрет, дедуплікує й передає update в
 * {@see ProcessTelegramUpdateJob} — у чергу або на виконання після відповіді
 * (services.telegram.updates_via_queue), щоб довгі виклики OpenAI не
 * тримали відкритим HTTP-запит Telegram.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret): Response
    {
        if (! hash_equals((string) config('services.telegram.webhook_secret'), $secret)) {
            abort(404);
        }

        $payload = $request->json()->all() ?? [];

        // Дедуплікація: Telegram повторює доставку того самого update_id при
        // таймаутах. Журнал із unique(update_id) слугує атомарним бар'єром —
        // повторну доставку не обробляємо вдруге (інакше подвійна відповідь
        // користувачу й подвійна витрата токенів OpenAI).
        if (! $this->logWebhook(new Update($payload), $payload)) {
            return response('', 200);
        }

        // У режимі без черги обробка йде після відправлення відповіді
        // (fastcgi_finish_request) — Telegram однаково одразу отримує 200.
        if (config('services.telegram.updates_via_queue')) {
            ProcessTelegramUpdateJob::dispatch($payload);
        } else {
            ProcessTelegramUpdateJob::dispatchAfterResponse($payload);
        }

        return response('', 200);
    }

    /**
     * Діагностика + дедуплікація: зберігаємо сирий payload кожного валідного
     * запиту, щоб розбирати, що саме надіслав Telegram. Повертає false, якщо
     * цей update_id уже був залогований (повторна доставка — обробку
     * пропускаємо). Інші збої журналювання не блокують обробку webhook —
     * лише репортяться, обробка триває (true).
     */
    private function logWebhook(Update $update, array $payload): bool
    {
        try {
            TelegramWebhookLog::record($update, $payload);

            return true;
        } catch (UniqueConstraintViolationException $e) {
            return false;
        } catch (Throwable $e) {
            report($e);

            return true;
        }
    }
}
