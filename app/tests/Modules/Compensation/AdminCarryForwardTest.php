<?php

declare(strict_types=1);

/**
 * F90: every weaker/power/carry-forward BV figure on the carry-forwards page
 * must carry an explicit Left/Right label from the stored `power_side`,
 * never left for the reader to infer.
 */

use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function cfAdmin(): User
{
    $user = User::create([
        'full_name' => 'CF Report Admin',
        'email' => 'cf-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

function cfDistributor(string $adn): int
{
    $user = User::create([
        'full_name' => 'CF Distributor',
        'email' => 'cf-dist-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    $id = DB::table('distributors')->insertGetId([
        'user_id' => $user->id,
        'adn' => $adn,
        'pan_hash' => random_bytes(32),
        'pan_last4' => '1234',
        'sponsor_id' => 0,
        'placement_parent_id' => 0,
        'side_chosen_by' => 'referral_default',
        'depth' => 0,
        'effective_date' => now()->format('Y-m-d H:i:s.v'),
        'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
        'state' => 'TS',
        'is_primary_couple' => 0,
        'created_at' => now()->format('Y-m-d H:i:s.v'),
        'updated_at' => now()->format('Y-m-d H:i:s.v'),
    ]);
    DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);

    return $id;
}

it('F90: labels both the power-side and the slab-1 weaker CF with an explicit Left/Right side', function () {
    $distributorId = cfDistributor('CFAAA1');

    GsbCarryforward::create([
        'distributor_id' => $distributorId,
        'power_side_bv_paise' => 30_000_000,
        'power_side' => 'R',
        'slab1_weaker_bv_paise' => 500_000,
    ]);

    $this->actingAs(cfAdmin())
        ->get(route('admin.compensation.carry-forwards.index'))
        ->assertOk()
        ->assertSee('CFAAA1')
        // The power-side CF (30,000,000 paise = 3,00,000 BV) belongs to
        // Right; the slab-1 weaker CF is therefore on Left.
        ->assertSee('Right')
        ->assertSee('Left');
});
