<?php

namespace Tests\Feature\Auth;

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
        $admin->assignRole('admin');

        $loggedInAt = now()->subMinutes(10);
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
        $admin->assignRole('admin');
        $admin->update(['sessions_invalidated_at' => now()->subMinutes(10)]);

        $loggedInAt = now();

        $response = $this
            ->actingAs($admin)
            ->withSession(['logged_in_at' => $loggedInAt])
            ->get('/admin');

        $response->assertOk();
    }
}
