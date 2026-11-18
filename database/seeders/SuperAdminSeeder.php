<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * FR-025: створює одного випадкового користувача з роллю super_admin
 * для локальної розробки (`php artisan db:seed --class=SuperAdminSeeder`).
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::SuperAdmin);

        $this->command?->info("Super admin створено: {$user->phone} (user_id={$user->id})");
    }
}
