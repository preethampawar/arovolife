<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);
});

function gbbReportAdmin(): User
{
    $user = User::create([
        'full_name' => 'Gbb Report Admin',
        'email' => 'gbb-report-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

function gbbReportDistributor(string $adn, string $name): int
{
    $user = User::create([
        'full_name' => $name,
        'email' => 'gbb-rep-'.uniqid().'@test.com',
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

function makeGbbRow(int $distributorId, int $agp, ?int $pointValuePaise, int $grossPaise, string $status, string $monthStart): GbbMonthlyResult
{
    return GbbMonthlyResult::create([
        'distributor_id' => $distributorId,
        'year_month' => $monthStart,
        'agp_earned' => $agp,
        'company_turnover_paise' => 10_000_000,
        'pool_paise' => 500_000,
        'total_pool_agp' => 200,
        'point_value_paise' => $pointValuePaise,
        'gbb_gross_paise' => $grossPaise,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'gbb_net_paise' => $grossPaise,
        'status' => $status,
    ]);
}

function seedGbbReportBvEntry(int $distributorId, int $bvPaise, string $type): void
{
    static $orderId = 970000;
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $orderId++,
        'bv_paise' => $bvPaise,
        'type' => $type,
        'effective_at' => '2026-07-10 12:00:00',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

// Regression: the Title column summed accruals only, so a distributor whose
// orders were refunded kept the title their gross purchases bought. The
// signed SUM must net the reversal out — 7,000 BV bought then 4,000 BV
// refunded is 3,000 BV net, i.e. Retailer and not Dealer.
it('titles a distributor from personal BV net of reversals', function () {
    $distributorId = gbbReportDistributor('GBBNET', 'Nethra');
    makeGbbRow($distributorId, 12, 25_000, 300_000, GbbMonthlyResult::STATUS_CREDITED, '2026-07-01');
    seedGbbReportBvEntry($distributorId, 700_000, 'accrual');
    seedGbbReportBvEntry($distributorId, -400_000, 'reversal');

    $this->actingAs(gbbReportAdmin())
        ->get(route('admin.compensation.gbb-calculation.index'))
        ->assertOk()
        ->assertSee('GBBNET')
        ->assertSee('Retailer')
        ->assertDontSee('Dealer');
});

it('shows the frozen point value and renders a dash for legacy rows', function () {
    $withValue = gbbReportDistributor('GBBAAA', 'Alice');
    $legacy = gbbReportDistributor('GBBBBB', 'Bob');
    makeGbbRow($withValue, 12, 25_000, 300_000, GbbMonthlyResult::STATUS_CREDITED, '2026-07-01');
    makeGbbRow($legacy, 5, null, 100_000, GbbMonthlyResult::STATUS_CREDITED, '2026-07-01');

    $this->actingAs(gbbReportAdmin())
        ->get(route('admin.compensation.gbb-calculation.index'))
        ->assertOk()
        ->assertSee('Point Value')
        ->assertSee('GBBAAA')
        ->assertSee('GBBBBB')
        ->assertSee('₹250.00')   // frozen point value
        ->assertSee('—');        // legacy row has no snapshot
});

it('badges the repurchase held and suspended statuses', function () {
    $held = gbbReportDistributor('GBBHLD', 'Hema');
    $susp = gbbReportDistributor('GBBSUS', 'Suresh');
    makeGbbRow($held, 12, 25_000, 300_000, GbbMonthlyResult::STATUS_REPURCHASE_HELD, '2026-07-01');
    makeGbbRow($susp, 12, 25_000, 0, GbbMonthlyResult::STATUS_REPURCHASE_SUSPENDED, '2026-07-01');

    $this->actingAs(gbbReportAdmin())
        ->get(route('admin.compensation.gbb-calculation.index'))
        ->assertOk()
        ->assertSee('repurchase held')
        ->assertSee('repurchase suspended')
        ->assertSee('bg-orange-100 text-orange-700', false);
});

it('filters by the repurchase held and suspended statuses', function () {
    $held = gbbReportDistributor('GBBHLD', 'Hema');
    $susp = gbbReportDistributor('GBBSUS', 'Suresh');
    $credited = gbbReportDistributor('GBBOK1', 'Kiran');
    makeGbbRow($held, 12, 25_000, 300_000, GbbMonthlyResult::STATUS_REPURCHASE_HELD, '2026-07-01');
    makeGbbRow($susp, 12, 25_000, 0, GbbMonthlyResult::STATUS_REPURCHASE_SUSPENDED, '2026-07-01');
    makeGbbRow($credited, 12, 25_000, 300_000, GbbMonthlyResult::STATUS_CREDITED, '2026-07-01');

    $admin = gbbReportAdmin();

    $this->actingAs($admin)
        ->get(route('admin.compensation.gbb-calculation.index', ['status' => 'repurchase_held']))
        ->assertOk()
        ->assertSee('GBBHLD')
        ->assertDontSee('GBBSUS')
        ->assertDontSee('GBBOK1');

    $this->actingAs($admin)
        ->get(route('admin.compensation.gbb-calculation.index', ['status' => 'repurchase_suspended']))
        ->assertOk()
        ->assertSee('GBBSUS')
        ->assertDontSee('GBBHLD');
});

it('rejects an unknown status filter', function () {
    $this->actingAs(gbbReportAdmin())
        ->get(route('admin.compensation.gbb-calculation.index', ['status' => 'nonsense']))
        ->assertSessionHasErrors('status');
});

it('exports the point value column', function () {
    $withValue = gbbReportDistributor('GBBAAA', 'Alice');
    $legacy = gbbReportDistributor('GBBBBB', 'Bob');
    makeGbbRow($withValue, 12, 25_000, 300_000, GbbMonthlyResult::STATUS_CREDITED, '2026-07-01');
    makeGbbRow($legacy, 5, null, 100_000, GbbMonthlyResult::STATUS_CREDITED, '2026-07-01');

    $res = $this->actingAs(gbbReportAdmin())
        ->get(route('admin.compensation.gbb-calculation.export'));

    $res->assertOk();
    $csv = $res->getContent();
    expect($csv)->toContain('AGP Points,Point Value (Rs),AGP Value Per Point (Rs)');
    expect($csv)->toContain(',250.00,250.00,');   // frozen value then realised value
    expect($csv)->toContain(',5,,200.00,');       // legacy row: empty frozen value
});

it('surfaces the frozen monthly pool on the GBB month screen', function () {
    $distributorId = gbbReportDistributor('GBBAAA', 'Alice');
    makeGbbRow($distributorId, 12, 25_000, 300_000, GbbMonthlyResult::STATUS_CREDITED, '2026-07-01');

    GbbMonthlyPool::create([
        'month_start' => '2026-07-01',
        'company_bv_paise' => 20_000_000,
        'pool_rate_bp' => 500,
        'pool_paise' => 1_000_000,
        'total_agp' => 40,
        'point_value_paise' => 25_000,
        'payout_paise' => 1_000_000,
        'leftover_paise' => 0,
    ]);

    $this->actingAs(gbbReportAdmin())
        ->get(route('admin.compensation.gbb.show', ['month' => '2026-07']))
        ->assertOk()
        ->assertSee('Frozen month economics')
        // Literal Indian-grouped strings: this doubles as the display-format
        // guard — Illuminate\Support\Number would render 200,000.00 here
        // because modern ICU dropped lakh grouping from the Indian locales.
        ->assertSee('₹2,00,000.00')   // company BV
        ->assertSee('5%')             // pool rate
        ->assertSee('₹10,000.00')     // pool and payout
        ->assertSee('₹250.00');       // point value
});

it('shows the distributor their own AGP times the frozen point value', function () {
    $distributorId = gbbReportDistributor('GBBAAA', 'Alice');
    makeGbbRow($distributorId, 12, 25_000, 300_000, GbbMonthlyResult::STATUS_CREDITED, '2026-07-01');
    $user = User::find(DB::table('distributors')->where('id', $distributorId)->value('user_id'));

    $this->actingAs($user)
        ->get(route('income.growth-booster'))
        ->assertOk()
        ->assertSee('12 AGP × ₹'.Number::format(250, 2))
        ->assertSee('Credited')
        // Historical fact only — never a projection of what they might earn.
        ->assertDontSee('could earn')
        ->assertDontSee('will earn');
});

it('degrades to a dash when a month has no frozen pool row', function () {
    $distributorId = gbbReportDistributor('GBBAAA', 'Alice');
    makeGbbRow($distributorId, 12, null, 300_000, GbbMonthlyResult::STATUS_CREDITED, '2026-06-01');

    $this->actingAs(gbbReportAdmin())
        ->get(route('admin.compensation.gbb.show', ['month' => '2026-06']))
        ->assertOk()
        ->assertSee('Frozen month economics')
        ->assertSee('No frozen pool row for this month')
        ->assertSee('—');
});

// Regression: the batch list aggregates with selectRaw, and YEAR_MONTH is a
// reserved interval unit in MySQL 8 — an unquoted `year_month` there is a
// syntax error that took the whole page down. Only bites on MySQL (SQLite
// accepts the bare word), so this guards the canonical arovolife_test target.
it('lists credited GBB batches without tripping the reserved YEAR_MONTH keyword', function () {
    $alice = gbbReportDistributor('GBBAAA', 'Alice');
    $bob = gbbReportDistributor('GBBBBB', 'Bob');

    makeGbbRow($alice, 12, 25_000, 300_000, GbbMonthlyResult::STATUS_CREDITED, '2026-06-01');
    makeGbbRow($bob, 5, 25_000, 125_000, GbbMonthlyResult::STATUS_CREDITED, '2026-06-01');
    // A held row must not be counted as a credited batch.
    makeGbbRow($bob, 5, 25_000, 125_000, GbbMonthlyResult::STATUS_REPURCHASE_HELD, '2026-07-01');

    $this->actingAs(gbbReportAdmin())
        ->get(route('admin.compensation.gbb.index'))
        ->assertOk()
        ->assertSee('Growth Booster Bonus')
        ->assertDontSee('No GBB batches yet');
});

it('hides the GBB calculation report while the feature is off', function (): void {
    Feature::for(null)->deactivate(GrowthBoosterBonusFeature::class);

    $admin = gbbReportAdmin();

    $this->actingAs($admin)
        ->get(route('admin.compensation.gbb-calculation.index'))
        ->assertNotFound();

    $this->actingAs($admin)
        ->get(route('admin.compensation.gbb-calculation.export'))
        ->assertNotFound();
});
