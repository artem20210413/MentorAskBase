<?php

namespace App\Console\Commands;

use App\Models\UserApprovalRequest;
use App\Services\Telegram\ApprovalRequestService;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

/**
 * FR-007/FR-008: щоденне нагадування про необроблені заявки на апрув.
 */
class SendApprovalRemindersCommand extends Command
{
    protected $signature = 'telegram:send-approval-reminders';

    protected $description = 'Надіслати нагадування про pending-заявки, старші 12 годин (FR-007)';

    public function handle(TelegramClient $telegram, ApprovalRequestService $approvalRequests): int
    {
        $requests = UserApprovalRequest::query()
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subHours(12))
            ->where(function ($query) {
                $query->whereNull('last_reminder_at')
                    ->orWhere('last_reminder_at', '<', now()->startOfDay());
            })
            ->with('user')
            ->get();

        $moderationLocale = config('services.telegram.moderation_language');

        foreach ($requests as $request) {
            // Деактивувати попереднє повідомлення (FR-008)
            $telegram->editMessageReplyMarkup($request->moderation_chat_id, $request->moderation_message_id);
            $telegram->editMessageText(
                $request->moderation_chat_id,
                $request->moderation_message_id,
                $approvalRequests->cardText($request->user)."\n\n".__('telegram.moderation_reminder_label', [], $moderationLocale),
            );

            $message = $telegram->sendMessage(
                $request->moderation_chat_id,
                $approvalRequests->cardText($request->user),
                [
                    'inline_keyboard' => [[
                        ['text' => __('telegram.moderation_approve_button', [], $moderationLocale), 'callback_data' => "approval:{$request->id}:approve"],
                        ['text' => __('telegram.moderation_reject_button', [], $moderationLocale), 'callback_data' => "approval:{$request->id}:reject"],
                    ]],
                ],
            );

            $request->update([
                'moderation_message_id' => $message->messageId,
                'last_reminder_at' => now(),
            ]);
        }

        $this->info("Надіслано нагадувань: {$requests->count()}");

        return self::SUCCESS;
    }
}
