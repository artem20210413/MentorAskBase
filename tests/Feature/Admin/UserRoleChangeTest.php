<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource\Pages\ManageUsers;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

class UserRoleChangeTest extends TestCase
{
    use SeedsRoles;

    public function test_super_admin_can_change_role_which_invalidates_session_and_logs_action(): void
    {
        $this->seedRoles();
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(UserRole::SuperAdmin);

        $target = User::factory()->create(['sessions_invalidated_at' => null]);
        $target->assignRole(UserRole::Moderator);

        $this->actingAs($superAdmin);

        Livewire::test(ManageUsers::class)
            ->callTableAction('changeRole', $target, data: ['role' => 'admin']);

        $target->refresh();
        $this->assertTrue($target->hasRole(UserRole::Admin));
        $this->assertFalse($target->hasRole(UserRole::Moderator));
        $this->assertNotNull($target->sessions_invalidated_at);

        $this->assertDatabaseHas('admin_action_logs', [
            'actor_user_id' => $superAdmin->id,
            'target_user_id' => $target->id,
            'action' => 'role_changed',
        ]);
    }

    public function test_super_admin_can_deactivate_and_reactivate_user(): void
    {
        $this->seedRoles();
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(UserRole::SuperAdmin);

        $target = User::factory()->create(['status' => 'approved']);
        $target->assignRole(UserRole::User);

        $this->actingAs($superAdmin);

        Livewire::test(ManageUsers::class)
            ->callTableAction('toggleStatus', $target);

        $this->assertDatabaseHas('users', ['id' => $target->id, 'status' => 'deactivated']);
        $this->assertDatabaseHas('admin_action_logs', [
            'actor_user_id' => $superAdmin->id,
            'target_user_id' => $target->id,
            'action' => 'user_deactivated',
        ]);
    }
}
