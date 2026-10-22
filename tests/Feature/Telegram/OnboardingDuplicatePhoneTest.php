<?php

namespace Tests\Feature\Telegram;

use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Tests\Support\PostsTelegramUpdates;
use Tests\TestCase;

class OnboardingDuplicatePhoneTest extends TestCase
{
    use PostsTelegramUpdates;

    public function test_onboarding_rejected_when_phone_already_belongs_to_another_chat_id(): void
    {
        $existing = User::factory()->create([
            'phone' => '+15551234567',
            'telegram_chat_id' => 111,
        ]);

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (int $chatId, string $text) => $chatId === 222 && str_contains($text, 'вже зареєстровано'));
        });

        $response = $this->postTelegramUpdate(
            $this->telegramContact(222, 222, '+15551234567'),
        );

        $response->assertOk();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $existing->id, 'telegram_chat_id' => 111]);
        $this->assertDatabaseMissing('users', ['telegram_chat_id' => 222]);
    }
}
