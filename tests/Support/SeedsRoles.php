<?php

namespace Tests\Support;

use Database\Seeders\RoleSeeder;

trait SeedsRoles
{
    protected function seedRoles(): void
    {
        $this->seed(RoleSeeder::class);
    }
}
