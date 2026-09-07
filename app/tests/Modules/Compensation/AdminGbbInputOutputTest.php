<?php

declare(strict_types=1);

use App\Modules\Commerce\Support\Bv;
use App\Modules\Compensation\Models\GbbMonthlyPool;
use App\Modules\Compensation\Models\GbbMonthlyResult;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function gbbIoAdmin(string $role = 'admin'): User
{
    $user = User::create([
        'full_name' => 'GBB IO Admin',
        'email' => 'gbb-io-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

/** A 1,00,000 BV month whose 5% pool (₹5,000) spreads over $totalAgp AGP. */
function gbbIoPool(string $monthStart, int $totalAgp, int $valuePaise): GbbMonthlyPool
{
    return GbbMonthlyPool::create([
        'month_start' => $monthStart,
        'company_bv_paise' => 10_000_000,
        'pool_rate_bp' => 500,
        'pool_paise' => 500_000,
        'total_agp' => $totalAgp,
        'point_value_paise' => $valuePaise,
        'payout_paise' => $totalAgp * $valuePaise,
        'leftover_paise' => 500_000 - $totalAgp * $valuePaise,
    ]);
}

/** One distributor's GBB row for the month: $agp at $valuePaise per point. */
function gbbIoRow(string $adn, string $name, int $agp, int $valuePaise, string $monthStart, string $status): Distributor
{
    $distributor = Distributor::factory()->create(['adn' => $adn]);
    User::whereKey($distributor->user_id)->update(['full_name' => $name]);

    GbbMonthlyResult::create([
        'distributor_id' => $distributor->id,
        'year_month' => $monthStart,
        'agp_earned' => $agp,
        'company_turnover_paise' => 10_000_000,
        'pool_paise' => 500_000,
        'total_pool_agp' => 100,
        'point_value_paise' => $valuePaise,
        'gbb_gross_paise' => in_array($status, GbbMonthlyResult::POOL_EXCLUDED_STATUSES, true) ? 0 : $agp * $valuePaise,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'gbb_net_paise' => in_array($status, GbbMonthlyResult::POOL_EXCLUDED_STATUSES, true) ? 0 : $agp * $valuePaise,
        'status' => $status,
    ]);

    return $distributor;
}

/** A credited slab-1 GSB cut-off — 12 AGP for the month it falls in. */
function gbbIoLateCutoff(int $distributorId, string $date): void
{
    GsbCutoffResult::create([
        'distributor_id' => $distributorId,
        'cutoff_date' => $date,
        'left_bv_paise' => 1_500_000,
        'right_bv_paise' => 1_500_000,
        'slab' => 1,
        'gross_gsb_paise' => 100_000,
        'admin_charge_paise' => 3_000,
        'tds_paise' => 4_850,
        'net_gsb_paise' => 92_150,
        'power_cf_after_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'power_side_after' => 'L',
        'status' => GsbCutoffResult::STATUS_CREDITED,
    ]);
}

it('renders a month block with the frozen pool figures and per-earner rows', function () {
    $monthStart = '2026-07-01';
    gbbIoPool($monthStart, 100, 5_000);

    gbbIoRow('200000030', 'Credited Earner', 60, 5_000, $monthStart, GbbMonthlyResult::STATUS_CREDITED);
    gbbIoRow('200000031', 'Held Earner', 40, 5_000, $monthStart, GbbMonthlyResult::STATUS_REPURCHASE_HELD);
    gbbIoRow('200000032', 'Suspended Earner', 25, 5_000, $monthStart, GbbMonthlyResult::STATUS_REPURCHASE_SUSPENDED);

    $res = $this->actingAs(gbbIoAdmin())
        ->get(route('admin.compensation.gbb-input-output.index'))
        ->assertOk();

    // Month header: total BV, the 5% pool, the frozen denominator and value.
    $res->assertSee('July 2026');
    $res->assertSee(Bv::format(10_000_000));
    $res->assertSee('GBB pool (5%)');
    $res->assertSee('5,000.00');
    $res->assertSee('₹50.00');

    // Per-earner incomes and statuses.
    $res->assertSee('Credited Earner');
    $res->assertSee('3,000.00');   // 60 × ₹50
    $res->assertSee('2,000.00');   // 40 × ₹50, held
    $res->assertSee('Held');
    $res->assertSee('Suspended');
    $res->assertSee('AGP excluded');

    // Legacy held rows were priced into the pool, so they are still called out
    // — but as stranded rows to escalate, not as money awaiting a release.
    $res->assertSee('legacy');
    $res->assertSee('nothing credits them now');

    // Footer: the frozen denominator and the month's income.
    $res->assertSee('Total AGP');
    $res->assertSee('leftover');

    // The header stamps when the pool row was frozen.
    $res->assertSee('Computed');
    $res->assertSee(GbbMonthlyPool::query()->sole()->created_at?->format('d M Y H:i'));
});

it('names the distributors whose AGP landed after the month was frozen', function () {
    $monthStart = '2026-07-01';
    gbbIoPool($monthStart, 100, 5_000);
    gbbIoRow('200000040', 'Credited Earner', 100, 5_000, $monthStart, GbbMonthlyResult::STATUS_CREDITED);

    // A credited cut-off inside the frozen month for someone with no roster
    // row: the engine refuses them, so the report has to name them.
    $late = Distributor::factory()->create(['adn' => '200000041']);
    gbbIoLateCutoff($late->id, '2026-07-20');

    $this->actingAs(gbbIoAdmin())
        ->get(route('admin.compensation.gbb-input-output.index'))
        ->assertOk()
        ->assertSee('Earned AGP after the pool was frozen')
        ->assertSee('200000041');
});

it('shows a month whose pool went unspent, with a note explaining the ₹0 value', function () {
    gbbIoPool('2026-06-01', 0, 0);

    $this->actingAs(gbbIoAdmin())
        ->get(route('admin.compensation.gbb-input-output.index'))
        ->assertOk()
        ->assertSee('No payable AGP was earned this month')
        ->assertSee('No Growth Booster Bonus earners this month.');
});

it('filters by month and by month range', function () {
    gbbIoPool('2026-06-01', 80, 5_000);
    gbbIoPool('2026-07-01', 100, 5_000);

    $admin = gbbIoAdmin();

    $this->actingAs($admin)
        ->get(route('admin.compensation.gbb-input-output.index', ['month' => '2026-06']))
        ->assertOk()
        ->assertSee('June 2026')
        ->assertDontSee('July 2026');

    $this->actingAs($admin)
        ->get(route('admin.compensation.gbb-input-output.index', ['from' => '2026-07']))
        ->assertOk()
        ->assertSee('July 2026')
        ->assertDontSee('June 2026');
});

it('exports a CSV with per-earner rows and a month total', function () {
    $monthStart = '2026-07-01';
    gbbIoPool($monthStart, 60, 5_000);
    gbbIoRow('200000040', 'Csv Earner', 60, 5_000, $monthStart, GbbMonthlyResult::STATUS_CREDITED);

    $res = $this->actingAs(gbbIoAdmin())
        ->get(route('admin.compensation.gbb-input-output.export'))
        ->assertOk();

    $csv = $res->getContent();

    expect($csv)->toContain('Month Total BV');
    expect($csv)->toContain('"200000040"');
    expect($csv)->toContain('3000.00');       // 60 × ₹50, ungrouped in CSV
    expect($csv)->toContain('"MONTH TOTAL"');
    expect($csv)->toContain('Computed At');
});

it('is hidden behind the Growth Booster flag', function () {
    Feature::for(null)->deactivate(GrowthBoosterBonusFeature::class);

    $this->actingAs(gbbIoAdmin())
        ->get(route('admin.compensation.gbb-input-output.index'))
        ->assertNotFound();
});

it('is reachable by the business admin roles but not by an unprivileged user', function () {
    gbbIoPool('2026-07-01', 60, 5_000);

    $this->actingAs(gbbIoAdmin('admin-finance'))
        ->get(route('admin.compensation.gbb-input-output.index'))
        ->assertOk();

    $plain = User::create([
        'full_name' => 'No Role',
        'email' => 'no-role-gbb-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($plain)
        ->get(route('admin.compensation.gbb-input-output.index'))
        ->assertForbidden();
});

it('embeds the collapsible point-value formula strip inside each month block', function () {
    gbbIoPool('2026-06-01', 100, 5_000);
    gbbIoPool('2026-07-01', 100, 5_000);

    $res = $this->actingAs(gbbIoAdmin())
        ->get(route('admin.compensation.gbb-input-output.index'))
        ->assertOk();
    $res->assertSee("How this month's AGP point value was calculated", false);
    $res->assertSee('How the AGP point value is calculated');
    $res->assertSee('⌊ ₹5,000.00 ÷ 100 ⌋', false);
    $res->assertDontSee('border-gray-200" open>', false);

    $this->actingAs(gbbIoAdmin())
        ->get(route('admin.compensation.gbb-input-output.index', ['month' => '2026-07']))
        ->assertOk()
        ->assertSee('border-gray-200" open>', false);
});

it('shows the credit-time repurchase deduction and credited amount per earner and in the month total', function () {
    $monthStart = '2026-07-01';
    gbbIoPool($monthStart, 100, 5_000);
    gbbIoRow('200000040', 'Deducted Earner', 60, 5_000, $monthStart, GbbMonthlyResult::STATUS_CREDITED);

    GbbMonthlyResult::query()->update([
        'repurchase_deduction_paise' => 30_000,
        'gbb_net_paise' => 270_000,
    ]);

    $res = $this->actingAs(gbbIoAdmin())
        ->get(route('admin.compensation.gbb-input-output.index', ['month' => '2026-07']))
        ->assertOk();

    $res->assertSee('Repurchase deduction');
    $res->assertSee('Credited to wallet');
    $res->assertSee('-₹300.00');   // deduction
    $res->assertSee('2,700.00');   // credited: ₹3,000 − ₹300
});

it('carries the deduction and credited columns into the GBB per-month CSV', function () {
    $monthStart = '2026-07-01';
    gbbIoPool($monthStart, 100, 5_000);
    gbbIoRow('200000041', 'CSV Earner', 60, 5_000, $monthStart, GbbMonthlyResult::STATUS_CREDITED);

    GbbMonthlyResult::query()->update([
        'repurchase_deduction_paise' => 30_000,
        'gbb_net_paise' => 270_000,
    ]);

    $csv = $this->actingAs(gbbIoAdmin())
        ->get(route('admin.compensation.gbb-input-output.export'))
        ->assertOk()
        ->getContent();

    expect($csv)->toContain('Income (Rs),Repurchase Deduction (Rs),Credited to Wallet (Rs)');
    expect($csv)->toContain('300.00');
    expect($csv)->toContain('2700.00');
});

it('lists a repurchase-wallet-blocked earner so the blocked AGP is visible on the month', function () {
    $monthStart = '2026-07-01';
    gbbIoPool($monthStart, 60, 5_000);
    gbbIoRow('AD-IO-BLK1', 'Paid Earner', 60, 5_000, $monthStart, GbbMonthlyResult::STATUS_CREDITED);
    gbbIoRow('AD-IO-BLK2', 'Blocked Earner', 15, 5_000, $monthStart, GbbMonthlyResult::STATUS_REPURCHASE_WALLET_BLOCKED);

    $this->actingAs(gbbIoAdmin())
        ->get(route('admin.compensation.gbb-input-output.index'))
        ->assertOk()
        ->assertSee('Blocked Earner')
        ->assertSee('AD-IO-BLK2')
        ->assertSee('Blocked — repurchase wallet not ₹0', false);
});
