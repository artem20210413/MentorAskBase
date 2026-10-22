<?php

namespace Tests\Feature\Telegram;

use App\Models\User;
use App\Models\UserApprovalRequest;
use App\Services\Telegram\TelegramClient;
use Tests\Support\PostsTelegramUpdates;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

class ApprovalDecisionTest extends TestCase
{
    use PostsTelegramUpdates, SeedsRoles;

    public function test_moderator_approving_request_grants_access_and_notifies_applicant(): void
    {
        $this->seedRoles();

        $moderator = User::factory()->create(['telegram_chat_id' => 700]);
        $moderator->assignRole('moderator');

        $applicant = User::factory()->pending()->create(['telegram_chat_id' => 701]);
        $request = UserApprovalRequest::create([
            'user_id' => $applicant->id,
            'status' => 'pending',
            'moderation_chat_id' => -1000,
            'moderation_message_id' => 55,
        ]);

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('editMessageText')->once();
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (int $chatId, string $text) => $chatId === 701 && str_contains($text, 'схвалено'));
            $mock->shouldReceive('answerCallbackQuery')->once();
        });

        $response = $this->postTelegramUpdate(
            $this->telegramCallbackQuery(700, "approval:{$request->id}:approve"),
        );

        $response->assertOk();

        $this->assertDatabaseHas('users', ['id' => $applicant->id, 'status' => 'approved']);
        $this->assertTrue($applicant->refresh()->hasRole('user'));
        $this->assertDatabaseHas('user_approval_requests', ['id' => $request->id, 'status' => 'approved']);
        $this->assertDatabaseHas('admin_action_logs', [
            'actor_user_id' => $moderator->id,
            'target_user_id' => $applicant->id,
            'action' => 'approval_approved',
        ]);
    }

    public function test_second_click_on_already_processed_request_is_ignored(): void
    {
        $this->seedRoles();

        $moderator = User::factory()->create(['telegram_chat_id' => 710]);
        $moderator->assignRole('moderator');

        $applicant = User::factory()->pending()->create(['telegram_chat_id' => 711]);
        $request = UserApprovalRequest::create([
            'user_id' => $applicant->id,
            'status' => 'approved',
            'decided_by_user_id' => $moderator->id,
            'decided_at' => now(),
            'moderation_chat_id' => -1000,
            'moderation_message_id' => 56,
        ]);

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('answerCallbackQuery')
                ->once()
                ->withArgs(fn (string $id, ?string $text) => str_contains((string) $text, 'вже обробена'));
            $mock->shouldNotReceive('editMessageText');
            $mock->shouldNotReceive('sendMessage');
        });

        $response = $this->postTelegramUpdate(
            $this->telegramCallbackQuery(710, "approval:{$request->id}:reject"),
        );

        $response->assertOk();
        $this->assertDatabaseHas('user_approval_requests', ['id' => $request->id, 'status' => 'approved']);
    }

    public function test_click_from_account_without_required_role_is_ignored(): void
    {
        $this->seedRoles();

        $plainUser = User::factory()->create(['telegram_chat_id' => 720]);

        $applicant = User::factory()->pending()->create(['telegram_chat_id' => 721]);
        $request = UserApprovalRequest::create([
            'user_id' => $applicant->id,
            'status' => 'pending',
            'moderation_chat_id' => -1000,
            'moderation_message_id' => 57,
        ]);

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('answerCallbackQuery')->once();
            $mock->shouldNotReceive('editMessageText');
            $mock->shouldNotReceive('sendMessage');
        });

        $response = $this->postTelegramUpdate(
            $this->telegramCallbackQuery(720, "approval:{$request->id}:approve"),
        );

        $response->assertOk();
        $this->assertDatabaseHas('user_approval_requests', ['id' => $request->id, 'status' => 'pending']);
    }
}
