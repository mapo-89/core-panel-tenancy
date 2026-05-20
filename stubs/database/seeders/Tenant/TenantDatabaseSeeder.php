<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use CorePanel\Database\Seeders\CorePanelSettingsSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

final class TenantDatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            TenantRolePermissionSeeder::class,
            CorePanelSettingsSeeder::class,
        ]);
    }
}
