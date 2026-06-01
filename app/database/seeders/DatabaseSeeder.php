<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            SettingsSeeder::class,
            ContentPageSeeder::class,
            LedgerAccountSeeder::class,
            ProductCatalogSeeder::class,
            ProductCategorySeeder::class,
            CommerceFeatureFlagSeeder::class,
        ]);
    }
}
