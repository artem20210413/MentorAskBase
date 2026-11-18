<?php

namespace App\Services\Telegram;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\User;
use Telegram\Bot\Objects\Update;

/**
 * Маршрутизація одного Telegram-update до відповідного сервісу бота.
 * Викликається з {@see ProcessTelegramUpdateJob} (у черзі), а не
 * прямо з webhook-контролера.
 */
class TelegramUpdateHandler
{
    public function __construct(
        private OnboardingService $onboarding,
        private ApprovalRequestService $approvalRequests,
        private BotMenuService $botMenu,
        private FreeChatService $freeChat,
        private TelegramClient $telegram,
    ) {}

    public function handle(Update $update): void
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

        // Бот працює лише в приватних чатах. Повідомлення з груп/каналів
        // (зокрема текст, набраний у чаті модерації) ігноруємо — там
        // взаємодія відбувається виключно через inline-кнопки (callback_query),
        // які обробляються окремо й від типу чату не залежать.
        if ($message->chat->type !== 'private') {
            return;
        }

        $chatId = (int) $message->chat->id;
        $telegramUserId = (int) $message->from->id;
        $languageCode = $message->from->get('language_code');

        $contact = $message->get('contact');
        if ($contact) {
            // Безпека: через вкладення можна надіслати будь-який контакт з
            // телефонної книги. Реєструємо лише власний номер відправника —
            // інакше можна закріпити за собою чужий телефон (зокрема номер
            // майбутнього адміна, на який потім підуть коди входу).
            if ((int) $contact->get('user_id') !== $telegramUserId) {
                $this->onboarding->rejectForeignContact($chatId, $languageCode);

                return;
            }

            $this->onboarding->handleContact(
                $telegramUserId,
                $chatId,
                (string) $contact->phoneNumber,
                $message->from->username ?? null,
                $languageCode,
            );

            return;
        }

        $user = User::where('telegram_chat_id', $telegramUserId)->first();

        if (! $user) {
            $this->onboarding->sendGreeting($chatId, $languageCode);

            return;
        }

        // FR-009: pending/rejected/deactivated — відмова в доступі на будь-яке повідомлення.
        if ($user->status !== 'approved') {
            $this->telegram->sendMessage($chatId, $this->accessDeniedReason($user->status, $user->language));

            return;
        }

        $text = trim((string) $message->get('text'));

        // Стікери, фото, голосові тощо не мають тексту — не відправляємо
        // порожнє питання в RAG (це платний виклик LLM без сенсу).
        if ($text === '') {
            $this->telegram->sendMessage($chatId, __('telegram.text_only', [], $user->language));

            return;
        }

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
