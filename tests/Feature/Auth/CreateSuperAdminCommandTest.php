<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

class CreateSuperAdminCommandTest extends TestCase
{
    use SeedsRoles;

    public function test_creates_new_super_admin_when_phone_is_free(): void
    {
        $this->seedRoles();

        $this->artisan('telegram:create-super-admin', ['--phone' => '+15550002000'])->assertSuccessful();

        $user = User::where('phone', '+15550002000')->firstOrFail();
        $this->assertTrue($user->hasRole(UserRole::SuperAdmin));
        $this->assertSame('approved', $user->status);
    }

    /**
     * Безпека: акаунт з цим номером міг зареєструвати хтось інший через бот.
     * Мовчки підвищувати його до super_admin не можна — код входу піде на
     * telegram_chat_id цього акаунта.
     */
    public function test_refuses_to_promote_existing_user_without_confirmation(): void
    {
        $this->seedRoles();

        $existing = User::factory()->create([
            'phone' => '+15550002001',
            'telegram_chat_id' => 4242,
            'status' => 'pending',
        ]);

        $this->artisan('telegram:create-super-admin', ['--phone' => '+15550002001'])
            ->expectsConfirmation('Підвищити цей акаунт до super admin?', 'no')
            ->assertFailed();

        $existing->refresh();
        $this->assertFalse($existing->hasRole(UserRole::SuperAdmin));
        $this->assertSame('pending', $existing->status);
    }

    public function test_promotes_existing_user_after_confirmation(): void
    {
        $this->seedRoles();

        $existing = User::factory()->create(['phone' => '+15550002002', 'telegram_chat_id' => 4243]);

        $this->artisan('telegram:create-super-admin', ['--phone' => '+15550002002'])
            ->expectsConfirmation('Підвищити цей акаунт до super admin?', 'yes')
            ->assertSuccessful();

        $this->assertTrue($existing->refresh()->hasRole(UserRole::SuperAdmin));
    }
}
