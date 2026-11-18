<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

class AdminSessionInvalidationTest extends TestCase
{
    use SeedsRoles;

    public function test_session_logged_in_before_invalidation_timestamp_is_rejected(): void
    {
        $this->seedRoles();
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin);

        // Рядок, не Carbon-об'єкт — у продакшні сесія серіалізується в JSON
        // (config('session.serialization') === 'json'), тож logged_in_at
        // реально приходить як рядок, не як Carbon-інстанс.
        $loggedInAt = now()->subMinutes(10)->toJSON();
        $admin->update(['sessions_invalidated_at' => now()]);

        $response = $this
            ->actingAs($admin)
            ->withSession(['logged_in_at' => $loggedInAt])
            ->get('/admin');

        $response->assertRedirect();
        $this->assertGuest();
    }

    public function test_session_logged_in_after_invalidation_timestamp_is_accepted(): void
    {
        $this->seedRoles();
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin);
        $admin->update(['sessions_invalidated_at' => now()->subMinutes(10)]);

        $loggedInAt = now()->toJSON();

        $response = $this
            ->actingAs($admin)
            ->withSession(['logged_in_at' => $loggedInAt])
            ->get('/admin');

        $response->assertOk();
    }
}
