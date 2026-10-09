<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankMonthlyPool;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\RankBonusService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Database\Seeders\RankTiersSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\Support\XlsxReader;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function rbCalcReportAdmin(): User
{
    $user = User::create([
        'full_name' => 'Rb Report Admin',
        'email' => 'rb-report-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

it('hides the RB calculation report while the feature is off', function (): void {
    Feature::for(null)->deactivate(RankBonusFeature::class);

    $admin = rbCalcReportAdmin();

    $this->actingAs($admin)
        ->get(route('admin.compensation.rb-calculation.index'))
        ->assertNotFound();

    $this->actingAs($admin)
        ->get(route('admin.compensation.rb-calculation.export'))
        ->assertNotFound();
});

it('shows the RB calculation report while the feature is on', function (): void {
    Feature::for(null)->activate(RankBonusFeature::class);

    $this->actingAs(rbCalcReportAdmin())
        ->get(route('admin.compensation.rb-calculation.index'))
        ->assertOk();
});

it('hides the Arete Center column on page and CSV while the ADC flag is off', function (): void {
    Feature::for(null)->activate(RankBonusFeature::class);

    // ADC flag defaults to off — no Arete Center column anywhere.
    $this->actingAs(rbCalcReportAdmin())
        ->get(route('admin.compensation.rb-calculation.index'))
        ->assertOk()
        ->assertDontSee('Arete Center');
    $withoutAdc = $this->actingAs(rbCalcReportAdmin())
        ->get(route('admin.compensation.rb-calculation.export'))
        ->assertOk()
        ->streamedContent();
    expect(XlsxReader::anyCellContains(XlsxReader::rows($withoutAdc), 'Arete Center'))->toBeFalse();

    // ADC flag on — the column returns (asserted on the XLSX header, which
    // renders even with zero result rows; the HTML tables don't).
    Feature::for(null)->activate(AreteDevelopmentCenterBonusFeature::class);

    $withAdc = $this->actingAs(rbCalcReportAdmin())
        ->get(route('admin.compensation.rb-calculation.export'))
        ->assertOk()
        ->streamedContent();
    expect(XlsxReader::anyCellContains(XlsxReader::rows($withAdc), 'Arete Center'))->toBeTrue();
});

/**
 * Runs the real engine for one month on a 10,00,000 BV turnover (envelope
 * ₹2,00,000) with one achiever qualified at the given rank, or nobody at all
 * when $rank is null.
 */
function rbCalcRunTwoPassMonth(string $monthStart, ?int $rank): void
{
    $at = Carbon::parse($monthStart)->setDay(10)->setTime(10, 0)->toDateTimeString();
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => 999001, 'order_id' => random_int(900_000, 999_999), 'bv_paise' => 100_000_000, 'type' => 'accrual',
        'effective_at' => $at, 'created_at' => $at, 'updated_at' => $at,
    ]);
    if ($rank !== null) {
        RankQualification::create([
            'distributor_id' => Distributor::factory()->create()->id, 'rank_number' => $rank, 'month_start' => $monthStart,
            'occurrence_in_month' => 1, 'is_carry_forward' => false, 'status' => RankQualification::STATUS_QUALIFIED,
        ]);
    }
    app(RankBonusService::class)->runForMonth(Carbon::parse($monthStart));
}

/**
 * Task 14 L7: the unfiltered page shows a formula strip for every frozen month
 * on view, not only the months with Rank-1 rows. June's only achiever is Rank 4
 * (pass 2: ⌊₹2,00,000 ÷ 1,125⌋ = ₹177); July's is Rank 1 (pass 1: ₹200 cap).
 */
it('shows the formula strip of a pass-2-only month on the unfiltered page', function (): void {
    Feature::for(null)->activate(RankBonusFeature::class);
    $this->seed(RankTiersSeeder::class);

    rbCalcRunTwoPassMonth('2026-06-01', 4);
    rbCalcRunTwoPassMonth('2026-07-01', 1);

    expect(DB::table('rank_bonus_results')->where('month_start', '2026-06-01')->where('rank_number', 1)->count())->toBe(0);

    $this->actingAs(rbCalcReportAdmin())
        ->get(route('admin.compensation.rb-calculation.index'))
        ->assertOk()
        ->assertSee('June 2026')
        ->assertSee('min(₹200, ⌊ ₹2,00,000.00 ÷ 1,125 ⌋)', false)
        ->assertSee('July 2026')
        ->assertSee('min(₹200, ⌊ ₹2,00,000.00 ÷ 72 ⌋)', false);
});

it('shows the formula strip of a two-pass month frozen with no achievers on the unfiltered page', function (): void {
    Feature::for(null)->activate(RankBonusFeature::class);
    $this->seed(RankTiersSeeder::class);

    rbCalcRunTwoPassMonth('2026-08-01', null);

    expect(DB::table('rank_bonus_results')->count())->toBe(0);

    $this->actingAs(rbCalcReportAdmin())
        ->get(route('admin.compensation.rb-calculation.index'))
        ->assertOk()
        ->assertSee('August 2026')
        ->assertSee('No pass-1 points this month');
});

/**
 * F-7: a month frozen before the two-pass rule (a legacy pool row with the
 * migrated default pass 1, no pass rows) keeps its legacy label unfiltered.
 */
it('keeps the legacy label for a month priced before the two-pass rule on the unfiltered page', function (): void {
    Feature::for(null)->activate(RankBonusFeature::class);
    $this->seed(RankTiersSeeder::class);

    RankMonthlyPool::create([
        'month_start' => '2026-05-01',
        'rank_number' => 1,
        'company_turnover_paise' => 100_000_000,
        'envelope_bp' => 2_000,
        'pool_paise' => 1_400_000,
        'rap_points' => 10,
        'payable_count' => 1,
        'aogo_points' => 0,
        'total_points' => 10,
        'point_value_paise' => 140_000,
        'gross_per_qualifier_paise' => 1_400_000,
        'payout_paise' => 1_400_000,
        'leftover_paise' => 0,
    ]);
    RankBonusResult::create([
        'distributor_id' => Distributor::factory()->create()->id,
        'month_start' => '2026-05-01',
        'rank_number' => 1,
        'company_turnover_paise' => 100_000_000,
        'pool_paise' => 1_400_000,
        'qualifier_count' => 1,
        'rap_points' => 10,
        'aogo_points' => null,
        'total_points' => 10,
        'point_value_paise' => 140_000,
        'gross_paise' => 1_400_000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_paise' => 1_400_000,
        'status' => RankBonusResult::STATUS_CREDITED,
        'credited_at' => now(),
    ]);

    expect(DB::table('rank_monthly_passes')->count())->toBe(0);

    $this->actingAs(rbCalcReportAdmin())
        ->get(route('admin.compensation.rb-calculation.index'))
        ->assertOk()
        ->assertSee('May 2026')
        ->assertSee('This month was priced under the per-rank pool rule in force before the two-pass rule (client 2026-10-05); it has no pass summary.');
});
