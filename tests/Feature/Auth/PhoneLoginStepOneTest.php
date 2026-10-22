<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\Auth\PhoneLogin;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Livewire\Livewire;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

class PhoneLoginStepOneTest extends TestCase
{
    use SeedsRoles;

    public function test_unknown_phone_shows_generic_access_denied(): void
    {
        $this->mock(TelegramClient::class, fn ($mock) => $mock->shouldNotReceive('sendMessage'));

        Livewire::test(PhoneLogin::class)
            ->set('data.phone', '+15559990000')
            ->call('submit')
            ->assertHasFormErrors(['phone'])
            ->assertSet('step', 'phone');
    }

    public function test_phone_with_insufficient_role_shows_same_generic_error(): void
    {
        $this->seedRoles();

        $moderator = User::factory()->create(['phone' => '+15559990001']);
        $moderator->assignRole('moderator');

        $this->mock(TelegramClient::class, fn ($mock) => $mock->shouldNotReceive('sendMessage'));

        Livewire::test(PhoneLogin::class)
            ->set('data.phone', '+15559990001')
            ->call('submit')
            ->assertHasFormErrors(['phone'])
            ->assertSet('step', 'phone');
    }

    public function test_valid_admin_phone_advances_to_code_step_and_sends_code(): void
    {
        $this->seedRoles();

        $admin = User::factory()->create(['phone' => '+15559990002']);
        $admin->assignRole('admin');

        $this->mock(TelegramClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->withArgs(fn (int $chatId, string $text) => str_contains($text, 'код'));
        });

        Livewire::test(PhoneLogin::class)
            ->set('data.phone', '+15559990002')
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertSet('step', 'code');
    }
}
