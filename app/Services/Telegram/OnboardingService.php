<?php

namespace App\Services\Telegram;

use App\Models\User;
use Carbon\CarbonInterface;
use Throwable;

/**
 * FR-002/FR-003/FR-003a/FR-006b: онбординг нового користувача бота.
 */
class OnboardingService
{
    public function __construct(
        private ApprovalRequestService $approvalRequests,
        private TelegramClient $telegram,
        private ReapplicationCooldownService $cooldown,
    ) {}

    public function sendGreeting(int $chatId): void
    {
        // FR-002: до реєстрації мова ще не вибрана — замовчування 'uk'.
        $locale = 'uk';

        $this->telegram->sendMessage($chatId, __('telegram.greeting', [], $locale), $this->shareContactKeyboard($locale));
    }

    /**
     * Надіслано чужий контакт (або контакт без Telegram-акаунта) — просимо
     * поділитися саме своїм номером через кнопку.
     */
    public function rejectForeignContact(int $chatId): void
    {
        $locale = 'uk';

        $this->telegram->sendMessage($chatId, __('telegram.contact_not_own', [], $locale), $this->shareContactKeyboard($locale));
    }

    private function shareContactKeyboard(string $locale): array
    {
        return [
            'keyboard' => [[
                ['text' => __('telegram.btn_share_contact', [], $locale), 'request_contact' => true],
            ]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    /**
     * @return array{outcome: string, user?: User, next_allowed_at?: CarbonInterface}
     */
    public function handleContact(int $telegramUserId, int $chatId, string $rawPhone, ?string $username): array
    {
        $locale = 'uk';
        $normalized = $this->normalizePhone($rawPhone);

        if ($normalized === null) {
            $this->telegram->sendMessage($chatId, __('telegram.invalid_phone', [], $locale));

            return ['outcome' => 'invalid_phone'];
        }

        $existingByChat = User::where('telegram_chat_id', $telegramUserId)->first();

        if ($existingByChat) {
            return $this->handleExistingUser($existingByChat);
        }

        $existingByPhone = User::where('phone', $normalized)->first();

        if ($existingByPhone) {
            $this->telegram->sendMessage($chatId, __('telegram.duplicate_phone', [], $locale));

            return ['outcome' => 'duplicate_phone'];
        }

        $user = User::create([
            'name' => $username ?? ('tg_'.$telegramUserId),
            'email' => $telegramUserId.'@telegram.local',
            'password' => bcrypt(bin2hex(random_bytes(16))),
            'phone' => $normalized,
            'status' => 'pending',
            'telegram_chat_id' => $telegramUserId,
            'telegram_username' => $username,
            // FR-002: мова за замовчуванням — uk (явно, щоб не залежати від
            // того, чи підхопить in-memory модель DB-дефолт одразу після create()).
            'language' => $locale,
        ]);

        $this->approvalRequests->createAndNotify($user);
        $this->telegram->sendMessage($chatId, __('telegram.application_submitted', [], $user->language));

        return ['outcome' => 'created', 'user' => $user];
    }

    /**
     * @return array{outcome: string, user?: User, next_allowed_at?: CarbonInterface}
     */
    private function handleExistingUser(User $user): array
    {
        if ($user->status === 'pending') {
            $this->telegram->sendMessage(
                (int) $user->telegram_chat_id,
                __('telegram.application_already_pending', [], $user->language),
            );

            return ['outcome' => 'already_pending', 'user' => $user];
        }

        if ($user->status === 'rejected') {
            if (! $this->cooldown->canReapplyNow($user)) {
                $nextAllowedAt = $this->cooldown->nextAllowedAt($user);
                $this->telegram->sendMessage(
                    (int) $user->telegram_chat_id,
                    __('telegram.reapply_after', ['date' => $nextAllowedAt->format('d.m.Y H:i')], $user->language),
                );

                return ['outcome' => 'cooldown_active', 'user' => $user, 'next_allowed_at' => $nextAllowedAt];
            }

            $user->status = 'pending';
            $user->save();
            $this->approvalRequests->createAndNotify($user);
            $this->telegram->sendMessage(
                (int) $user->telegram_chat_id,
                __('telegram.application_submitted', [], $user->language),
            );

            return ['outcome' => 'reapplied', 'user' => $user];
        }

        return ['outcome' => 'already_registered', 'user' => $user];
    }

    private function normalizePhone(string $raw): ?string
    {
        try {
            return phone(str_starts_with($raw, '+') ? $raw : '+'.$raw)->formatE164();
        } catch (Throwable) {
            return null;
        }
    }
}
