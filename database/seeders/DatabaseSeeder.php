<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $seeders = [
            RolePermissionSeeder::class,
            UserSeeder::class,
            ProjectSeeder::class,
            SystemSettingsSeeder::class,
        ];

        if (app()->environment(['local', 'testing'])) {
            $seeders[] = DemoProjectRoleDataSeeder::class;
        }

        $this->call($seeders);
    }
}
