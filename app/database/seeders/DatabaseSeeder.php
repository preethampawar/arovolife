<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
            SettingsSeeder::class,
            ContentPageSeeder::class,
            LedgerAccountSeeder::class,
            ProductCategorySeeder::class,
            ProductCatalogSeeder::class,
            CommerceFeatureFlagSeeder::class,
            GsbSlabsSeeder::class,
            RankTiersSeeder::class,
            FortuneBonusLevelsSeeder::class,
            FortuneBonusTiersSeeder::class,
            LifetimeAwardRewardsSeeder::class,
        ]);
    }
}
