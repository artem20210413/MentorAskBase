<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

class DocumentPageAccessTest extends TestCase
{
    use SeedsRoles;

    public function test_documents_page_requires_login(): void
    {
        // FR-017 (feature 004): панель більше не доступна без авторизації.
        $response = $this->get('/admin/documents');

        $response->assertRedirect();
        $this->assertGuest();
    }

    public function test_approved_admin_can_access_documents_page(): void
    {
        $this->seedRoles();
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::Admin);

        $this->actingAs($admin)
            ->get('/admin/documents')
            ->assertOk();
    }
}
