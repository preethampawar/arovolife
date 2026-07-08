<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Compensation\Models\AreteCenter;
use Illuminate\Database\Seeder;

final class AreteCenterSeeder extends Seeder
{
    public function run(): void
    {
        AreteCenter::firstOrCreate(
            ['is_company_default' => true],
            [
                'name' => 'Arovolife Company Centre',
                'location' => 'Hyderabad, Telangana',
                'assigned_distributor_id' => null,
                'status' => AreteCenter::STATUS_ACTIVE,
                'is_company_default' => true,
            ],
        );
    }
}
