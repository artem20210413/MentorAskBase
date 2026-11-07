<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

class UserResourceAccessTest extends TestCase
{
    use SeedsRoles;

    public function test_super_admin_can_access_user_management_page(): void
    {
        $this->seedRoles();
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(UserRole::SuperAdmin);

        $this->actingAs($superAdmin)
            ->get(UserResource::getUrl())
            ->assertOk();
    }

    public function test_admin_cannot_access_user_management_page(): void
    {
        $this->seedRoles();
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin);

        $this->actingAs($admin)
            ->get(UserResource::getUrl())
            ->assertForbidden();
    }
}
