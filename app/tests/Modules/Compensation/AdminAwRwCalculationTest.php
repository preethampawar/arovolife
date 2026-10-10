<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Services\BonusCalculationSnapshots;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

function awRwSeedCompanyBv(int $bvPaise, string $date): void
{
    static $fakeOrderId = 950000;

    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => 1,
        'order_id' => $fakeOrderId++,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => $date.' 12:00:00',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function awRwMilestone(int $distributorId, int $amountPaise, string $month, string $status = LifetimeAwardMilestone::STATUS_PENDING, int $rank = 1): void
{
    LifetimeAwardMilestone::create([
        'distributor_id' => $distributorId,
        'rank_number' => $rank,
        'tranche' => 1,
        'amount_paise' => $amountPaise,
        'triggered_month' => $month,
        'qualification_count' => 1,
        'award_description' => 'Silver Partner — tranche A, merchandise per plan',
        'status' => $status,
    ]);
}

it('tracks the awards fund month by month: 20% of BV in, award worth out, balance carried (client 2026-10-09, Q1)', function (): void {
    $alice = awRwReportDistributor('AWRFUN', 'Alice');
    $bob = awRwReportDistributor('AWRFUO', 'Bob');

    awRwSeedCompanyBv(10_000_000, '2026-07-10');   // 1,00,000 BV → fund ₹20,000
    awRwSeedCompanyBv(5_000_000, '2026-08-10');    // 50,000 BV → fund ₹10,000
    awRwSeedCompanyBv(-1_000_000, '2026-09-10');   // refund-heavy month → fund ₹0, never negative
    awRwMilestone($alice, 1_540_000, '2026-07-01');                                     // ₹15,400
    awRwMilestone($bob, 3_600_000, '2026-08-01');                                       // ₹36,000
    awRwMilestone($bob, 9_999_900, '2026-08-01', LifetimeAwardMilestone::STATUS_CANCELLED, rank: 3); // never counted

    $fund = app(BonusCalculationSnapshots::class)
        ->awardsFund(Carbon::parse('2026-09-15'));

    expect($fund)->toBe([
        ['month_start' => '2026-07-01', 'bv_paise' => 10_000_000, 'fund_paise' => 2_000_000, 'awarded_paise' => 1_540_000, 'balance_paise' => 460_000],
        ['month_start' => '2026-08-01', 'bv_paise' => 5_000_000, 'fund_paise' => 1_000_000, 'awarded_paise' => 3_600_000, 'balance_paise' => -2_140_000],
        ['month_start' => '2026-09-01', 'bv_paise' => -1_000_000, 'fund_paise' => 0, 'awarded_paise' => 0, 'balance_paise' => -2_140_000],
    ]);
});

it('shows the awards fund on the report and warns when awards ran ahead of it, without holding any award', function (): void {
    $alice = awRwReportDistributor('AWRFUP', 'Alice');
    awRwSeedCompanyBv(5_000_000, '2026-07-10');    // fund ₹10,000
    awRwMilestone($alice, 1_540_000, '2026-07-01'); // ₹15,400 earned

    $this->actingAs(awRwReportAdmin())
        ->get(route('admin.compensation.aw-rw-calculation.index'))
        ->assertOk()
        ->assertSee('Awards fund')
        ->assertSee('20%')
        ->assertSee('₹10,000')
        ->assertSee('-₹5,400')
        ->assertSee('The award worth earned has run ahead of the awards fund in')
        ->assertSee('Funded from the awards fund');

    expect(LifetimeAwardMilestone::where('distributor_id', $alice)->value('status'))->toBe(LifetimeAwardMilestone::STATUS_PENDING);
});

it('prices the awards fund at the configured rate', function (): void {
    DB::table('settings')->updateOrInsert(['key' => 'comp.awards.fund_rate_bp'], ['value' => '1500']);
    awRwSeedCompanyBv(10_000_000, '2026-07-10');

    $fund = app(BonusCalculationSnapshots::class)
        ->awardsFund(Carbon::parse('2026-07-20'));

    expect($fund[0]['fund_paise'])->toBe(1_500_000);
});
