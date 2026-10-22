<?php

namespace Tests\Feature\Telegram;

use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Telegram\Bot\Objects\Message;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

class ReapplicationCooldownTest extends TestCase
{
    use PostsTelegramUpdates;

    public function test_rejected_user_cannot_reapply_before_cooldown_elapses(): void
    {
        $user = User::factory()->rejected()->create([
            'telegram_chat_id' => 800,
            'phone' => '+15550001111',
            'rejection_count' => 1,
            'last_rejected_at' => now()->subHours(10),
        ]);

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (int $chatId, string $text) => $chatId === 800 && str_contains($text, 'повторно після'));
        });

        $response = $this->postTelegramUpdate(
            $this->telegramContact(800, 800, '+15550001111'),
        );

        $response->assertOk();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'rejected']);
    }

    public function test_rejected_user_can_reapply_after_cooldown_elapses(): void
    {
        $user = User::factory()->rejected()->create([
            'telegram_chat_id' => 801,
            'phone' => '+15550002222',
            'rejection_count' => 1,
            'last_rejected_at' => now()->subHours(25),
        ]);

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->twice()
                ->andReturn(new Message(['message_id' => 1, 'date' => time(), 'chat' => ['id' => 1]]));
        });

        $response = $this->postTelegramUpdate(
            $this->telegramContact(801, 801, '+15550002222'),
        );

        $response->assertOk();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'pending']);
        $this->assertDatabaseHas('user_approval_requests', ['user_id' => $user->id, 'status' => 'pending']);
    }
}
