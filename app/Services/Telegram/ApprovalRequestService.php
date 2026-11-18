<?php

namespace App\Services\Telegram;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserApprovalRequest;
use App\Services\Admin\AdminActionLogger;
use Illuminate\Support\Facades\DB;

/**
 * FR-004-FR-006, FR-004b: заявка на апрув у груповому чаті модерації.
 */
class ApprovalRequestService
{
    public function __construct(
        private TelegramClient $telegram,
        private AdminActionLogger $logger,
    ) {}

    public function createAndNotify(User $applicant): UserApprovalRequest
    {
        $existing = UserApprovalRequest::where('user_id', $applicant->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return $existing;
        }

        $moderationChatId = config('services.telegram.moderation_chat_id');

        $request = UserApprovalRequest::create([
            'user_id' => $applicant->id,
            'status' => 'pending',
            'moderation_chat_id' => $moderationChatId,
        ]);

        $message = $this->telegram->sendMessage(
            $moderationChatId,
            $this->cardText($applicant),
            $this->keyboard($request->id),
        );

        $request->update(['moderation_message_id' => $message->messageId]);

        return $request;
    }

    /**
     * FR-004b, FR-005, FR-006: обробка натискання "Одобрити"/"Відхилити".
     *
     * @return array{outcome: string}
     */
    public function handleDecision(int $clickerTelegramId, int $requestId, string $decision, string $callbackQueryId): array
    {
        $moderationLocale = config('services.telegram.moderation_language');
        $clicker = User::where('telegram_chat_id', $clickerTelegramId)->first();

        if (! $clicker || ! $clicker->hasAnyRole([UserRole::Moderator, UserRole::Admin, UserRole::SuperAdmin])) {
            $this->telegram->answerCallbackQuery($callbackQueryId, __('telegram.moderation_unauthorized', [], $moderationLocale));

            return ['outcome' => 'unauthorized'];
        }

        return DB::transaction(function () use ($clicker, $requestId, $decision, $callbackQueryId, $moderationLocale) {
            $request = UserApprovalRequest::whereKey($requestId)->lockForUpdate()->first();

            if (! $request) {
                return ['outcome' => 'not_found'];
            }

            if ($request->status !== 'pending') {
                $this->telegram->answerCallbackQuery($callbackQueryId, __('telegram.moderation_already_processed', [], $moderationLocale));

                return ['outcome' => 'already_processed'];
            }

            $applicant = $request->user;
            $approved = $decision === 'approve';
            $newStatus = $approved ? 'approved' : 'rejected';

            $request->update([
                'status' => $newStatus,
                'decided_by_user_id' => $clicker->id,
                'decided_at' => now(),
            ]);

            if ($approved) {
                $applicant->status = 'approved';
                $applicant->assignRole(UserRole::User);
            } else {
                $applicant->status = 'rejected';
                $applicant->rejection_count += 1;
                $applicant->last_rejected_at = now();
            }
            $applicant->save();

            $labelKey = $approved ? 'telegram.moderation_decided_approved' : 'telegram.moderation_decided_rejected';
            $label = __($labelKey, ['actor' => $clicker->name], $moderationLocale);

            $this->telegram->editMessageText(
                $request->moderation_chat_id,
                $request->moderation_message_id,
                $this->cardText($applicant)."\n\n{$label}",
            );

            $this->telegram->sendMessage(
                $applicant->telegram_chat_id,
                __($approved ? 'telegram.applicant_approved' : 'telegram.applicant_rejected', [], $applicant->language),
            );

            $this->logger->log(
                $clicker,
                $applicant,
                $approved ? 'approval_approved' : 'approval_rejected',
            );

            $this->telegram->answerCallbackQuery($callbackQueryId, __('telegram.moderation_decision_thanks', [], $moderationLocale));

            return ['outcome' => 'processed'];
        });
    }

    public function cardText(User $applicant): string
    {
        return __('telegram.moderation_card', [
            'name' => $applicant->name,
            'phone' => $applicant->phone,
            'username' => $applicant->telegram_username ?? '—',
            'date' => $applicant->created_at?->format('d.m.Y H:i'),
        ], config('services.telegram.moderation_language'));
    }

    private function keyboard(int $requestId): array
    {
        $locale = config('services.telegram.moderation_language');

        return [
            'inline_keyboard' => [[
                ['text' => __('telegram.moderation_approve_button', [], $locale), 'callback_data' => "approval:{$requestId}:approve"],
                ['text' => __('telegram.moderation_reject_button', [], $locale), 'callback_data' => "approval:{$requestId}:reject"],
            ]],
        ];
    }
}
