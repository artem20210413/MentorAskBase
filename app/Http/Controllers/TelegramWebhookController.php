<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Telegram\ApprovalRequestService;
use App\Services\Telegram\BotMenuService;
use App\Services\Telegram\FreeChatService;
use App\Services\Telegram\OnboardingService;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Telegram\Bot\Objects\Update;
use Throwable;

/**
 * contracts/telegram-webhook.md: єдина точка входу webhook.
 * Завжди відповідає 200 — Telegram повторює доставку при інших кодах.
 */
class TelegramWebhookController extends Controller
{
    public function __construct(
        private OnboardingService $onboarding,
        private ApprovalRequestService $approvalRequests,
        private BotMenuService $botMenu,
        private FreeChatService $freeChat,
        private TelegramClient $telegram,
    ) {}

    public function __invoke(Request $request, string $secret): Response
    {
        if (! hash_equals((string) config('services.telegram.webhook_secret'), $secret)) {
            abort(404);
        }

        $update = new Update($request->json()->all() ?? []);

        try {
            $this->dispatch($update);
        } catch (Throwable $e) {
            report($e);
            $this->notifyErrorChat($e);
        }

        return response('', 200);
    }

    /**
     * Спостережуваність: будь-яка необроблена помилка webhook-обробки одразу
     * видна в окремому технічному чаті (не в чаті модерації — модераторам це
     * не потрібно бачити).
     */
    private function notifyErrorChat(Throwable $e): void
    {
        $errorChatId = config('services.telegram.error_chat_id');

        if (! $errorChatId) {
            return;
        }

        $text = '⚠️ ['.config('app.env').'] Помилка в обробці Telegram-webhook'."\n"
            .get_class($e).': '.$e->getMessage()."\n"
            .$e->getFile().':'.$e->getLine();

        try {
            // Telegram обмежує повідомлення 4096 символами.
            $this->telegram->sendMessage($errorChatId, mb_substr($text, 0, 4000));
        } catch (Throwable $notifyException) {
            report($notifyException);
        }
    }

    private function dispatch(Update $update): void
    {
        match ($update->objectType()) {
            'callback_query' => $this->handleCallbackQuery($update),
            'message' => $this->handleMessage($update),
            default => null,
        };
    }

    private function handleCallbackQuery(Update $update): void
    {
        $callbackQuery = $update->callbackQuery;
        $data = (string) $callbackQuery->get('data');

        if (str_starts_with($data, 'approval:')) {
            [, $requestId, $decision] = explode(':', $data);

            $this->approvalRequests->handleDecision(
                (int) $callbackQuery->from->id,
                (int) $requestId,
                $decision,
                (string) $callbackQuery->id,
            );

            return;
        }

        if (str_starts_with($data, 'docs_page:')) {
            $user = User::where('telegram_chat_id', (int) $callbackQuery->from->id)->first();

            // FR-009: пагінація документів доступна лише approved-користувачам.
            if (! $user || $user->status !== 'approved') {
                return;
            }

            [, $page] = explode(':', $data);
            $this->botMenu->editDocumentsPage(
                (int) $callbackQuery->message->chat->id,
                (int) $callbackQuery->message->messageId,
                (int) $page,
                $user->language,
            );
        }
    }

    private function handleMessage(Update $update): void
    {
        $message = $update->message;
        $chatId = (int) $message->chat->id;
        $telegramUserId = (int) $message->from->id;

        $contact = $message->get('contact');
        if ($contact) {
            $this->onboarding->handleContact(
                $telegramUserId,
                $chatId,
                (string) $contact->phoneNumber,
                $message->from->username ?? null,
            );

            return;
        }

        $user = User::where('telegram_chat_id', $telegramUserId)->first();

        if (! $user) {
            $this->onboarding->sendGreeting($chatId);

            return;
        }

        // FR-009: pending/rejected/deactivated — відмова в доступі на будь-яке повідомлення.
        if ($user->status !== 'approved') {
            $this->telegram->sendMessage($chatId, $this->accessDeniedReason($user->status, $user->language));

            return;
        }

        $text = (string) $message->get('text');

        if ($this->botMenu->handle($user, $text)) {
            return;
        }

        $this->freeChat->handle($user, $text);
    }

    private function accessDeniedReason(string $status, string $locale): string
    {
        $key = match ($status) {
            'pending' => 'telegram.access_denied_pending',
            'rejected' => 'telegram.access_denied_rejected',
            'deactivated' => 'telegram.access_denied_deactivated',
            default => 'telegram.access_denied_default',
        };

        return __($key, [], $locale);
    }
}
