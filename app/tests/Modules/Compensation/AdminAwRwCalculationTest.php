<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    Feature::for(null)->activate(LifetimeAwardsFeature::class);
});

function awRwReportAdmin(): User
{
    $user = User::create([
        'full_name' => 'AW RW Admin',
        'email' => 'awrw-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

function awRwReportDistributor(string $adn, string $name): int
{
    $user = User::create([
        'full_name' => $name,
        'email' => 'awrw-d-'.uniqid().'@test.com',
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

it('shows the month header and how an award is valued, with the month\'s per-rank, per-tranche figures', function (): void {
    $alice = awRwReportDistributor('AWRAAA', 'Alice');
    $bob = awRwReportDistributor('AWRBBB', 'Bob');

    LifetimeAwardMilestone::create([
        'distributor_id' => $alice,
        'rank_number' => 3,
        'tranche' => 1,
        'amount_paise' => 4_860_000,
        'triggered_month' => '2026-07-01',
        'qualification_count' => 1,
        'award_description' => 'Emerald Partner — tranche A, merchandise per plan',
        'status' => LifetimeAwardMilestone::STATUS_PENDING,
    ]);
    LifetimeAwardMilestone::create([
        'distributor_id' => $bob,
        'rank_number' => 3,
        'tranche' => 2,
        'amount_paise' => 5_940_000,
        'triggered_month' => '2026-07-01',
        'qualification_count' => 2,
        'award_description' => 'Emerald Partner — tranche B, merchandise per plan',
        'status' => LifetimeAwardMilestone::STATUS_DELIVERED,
        'delivered_at' => now(),
    ]);

    $res = $this->actingAs(awRwReportAdmin())
        ->get(route('admin.compensation.aw-rw-calculation.index', ['month' => '2026-07']))
        ->assertOk();

    $res->assertSee('July 2026');
    $res->assertSee('Milestones triggered');
    $res->assertSee('How an award is valued');
    $res->assertSee('Merchandise only — never paid in cash');
    $res->assertSee('Tranche');
    $res->assertSee('₹48,600');
    $res->assertSee('₹59,400');
    // Σ of the two rows' tranche amounts.
    $res->assertSee('₹1,08,000');
    $res->assertSee('AWRAAA');
    $res->assertSee('AWRBBB');
    $res->assertDontSee('Cash reward');
    $res->assertDontSee('Cash (Rewards)');
});

it('has no cash/goods type filter: a type query is ignored', function (): void {
    $alice = awRwReportDistributor('AWRCCC', 'Carol');

    LifetimeAwardMilestone::create([
        'distributor_id' => $alice,
        'rank_number' => 1,
        'tranche' => 1,
        'amount_paise' => 1_540_000,
        'triggered_month' => '2026-07-01',
        'qualification_count' => 1,
        'award_description' => 'Silver Partner — tranche A, merchandise per plan',
        'status' => LifetimeAwardMilestone::STATUS_PENDING,
    ]);

    $this->actingAs(awRwReportAdmin())
        ->get(route('admin.compensation.aw-rw-calculation.index', ['type' => 'cash']))
        ->assertOk()
        ->assertSee('AWRCCC');
});

it('omits the header when no milestone was triggered in the filtered month', function (): void {
    $this->actingAs(awRwReportAdmin())
        ->get(route('admin.compensation.aw-rw-calculation.index', ['month' => '2026-07']))
        ->assertOk()
        ->assertDontSee('How an award');
});
