<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * FR-001: чотири ролі системи.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['user', 'moderator', 'admin', 'super_admin'] as $role) {
            Role::findOrCreate($role);
        }
    }
}
