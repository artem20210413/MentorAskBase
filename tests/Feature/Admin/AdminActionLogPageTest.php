<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Resources\AdminActionLogResource;
use App\Models\AdminActionLog;
use App\Models\User;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

class AdminActionLogPageTest extends TestCase
{
    use SeedsRoles;

    public function test_super_admin_sees_approval_and_role_change_entries_in_journal(): void
    {
        $this->seedRoles();
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(UserRole::SuperAdmin);

        $moderator = User::factory()->create();
        $applicant = User::factory()->create();

        AdminActionLog::create([
            'actor_user_id' => $moderator->id,
            'target_user_id' => $applicant->id,
            'action' => 'approval_approved',
        ]);
        AdminActionLog::create([
            'actor_user_id' => $superAdmin->id,
            'target_user_id' => $applicant->id,
            'action' => 'role_changed',
            'metadata' => ['old_role' => UserRole::User->value, 'new_role' => UserRole::Moderator->value],
        ]);

        $response = $this->actingAs($superAdmin)->get(AdminActionLogResource::getUrl());

        $response->assertOk();
        $response->assertSee('Схвалено заявку');
        $response->assertSee('Змінено роль');
    }

    public function test_non_super_admin_cannot_access_journal(): void
    {
        $this->seedRoles();
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin);

        $this->actingAs($admin)
            ->get(AdminActionLogResource::getUrl())
            ->assertForbidden();
    }
}
