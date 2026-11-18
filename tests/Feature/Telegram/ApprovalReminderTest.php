<?php

namespace Tests\Feature\Telegram;

use App\Models\User;
use App\Models\UserApprovalRequest;
use App\Services\Telegram\TelegramClient;
use Telegram\Bot\Objects\Message;
use Tests\TestCase;

class ApprovalReminderTest extends TestCase
{
    public function test_reminder_sent_for_pending_request_older_than_12_hours(): void
    {
        $applicant = User::factory()->pending()->create();
        $request = UserApprovalRequest::create([
            'user_id' => $applicant->id,
            'status' => 'pending',
            'moderation_chat_id' => -1000,
            'moderation_message_id' => 10,
        ]);
        $request->created_at = now()->subHours(13);
        $request->save();

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('editMessageReplyMarkup')->once();
            $mock->shouldReceive('editMessageText')
                ->once()
                ->withArgs(fn (int $chatId, int $messageId, string $text) => str_contains($text, 'повторно'));
            $mock->shouldReceive('sendMessage')
                ->once()
                ->andReturn(new Message(['message_id' => 99, 'date' => time(), 'chat' => ['id' => -1000]]));
        });

        $this->artisan('telegram:send-approval-reminders')->assertExitCode(0);

        $this->assertDatabaseHas('user_approval_requests', [
            'id' => $request->id,
            'moderation_message_id' => 99,
        ]);
    }

    public function test_no_reminder_for_request_younger_than_12_hours(): void
    {
        $applicant = User::factory()->pending()->create();
        $request = UserApprovalRequest::create([
            'user_id' => $applicant->id,
            'status' => 'pending',
            'moderation_chat_id' => -1000,
            'moderation_message_id' => 11,
        ]);
        $request->created_at = now()->subHours(3);
        $request->save();

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldNotReceive('sendMessage');
            $mock->shouldNotReceive('editMessageText');
        });

        $this->artisan('telegram:send-approval-reminders')->assertExitCode(0);
    }
}
