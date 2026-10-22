<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\UserResource\Pages\ManageUsers;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\SeedsRoles;
use Tests\TestCase;

class SelfProtectionTest extends TestCase
{
    use SeedsRoles;

    public function test_super_admin_cannot_change_own_role_or_deactivate_self(): void
    {
        $this->seedRoles();
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin);

        Livewire::test(ManageUsers::class)
            ->assertTableActionHidden('changeRole', $superAdmin)
            ->assertTableActionHidden('toggleStatus', $superAdmin);

        $this->assertTrue($superAdmin->refresh()->hasRole('super_admin'));
        $this->assertSame('approved', $superAdmin->status);
    }
}
