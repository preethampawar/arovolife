<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\FortuneBonusParticipant;
use App\Modules\Compensation\Models\FortuneBonusResult;
use App\Modules\Compensation\Models\FortuneMonthlyPool;
use App\Modules\Compensation\Models\FortuneMonthlyPoolLevel;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\FortuneBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    Feature::for(null)->activate(FortuneBonusFeature::class);
});

function fbReportAdmin(): User
{
    $user = User::create([
        'full_name' => 'Fb Report Admin',
        'email' => 'fb-report-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

function fbReportDistributor(string $adn, string $name): int
{
    $user = User::create([
        'full_name' => $name,
        'email' => 'fb-rep-'.uniqid().'@test.com',
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

function makeFbParticipant(int $distributorId, int $position, int $level, string $monthStart, string $firstGsbDate): void
{
    FortuneBonusParticipant::create([
        'distributor_id' => $distributorId,
        'month_start' => $monthStart,
        'position' => $position,
        'matrix_level' => $level,
        'eligibility_tier' => 'non_ranked',
        'first_gsb_date' => $firstGsbDate,
        'enrolled_at' => now(),
    ]);
}

function makeFbResult(int $distributorId, ?int $points, ?int $pointValuePaise, int $grossPaise, string $status, string $monthStart, int $level = 0, int $position = 1): void
{
    FortuneBonusResult::create([
        'distributor_id' => $distributorId,
        'month_start' => $monthStart,
        'position' => $position,
        'matrix_level' => $level,
        'points' => $points,
        'point_value_paise' => $pointValuePaise,
        'gross_paise' => $grossPaise,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_paise' => $grossPaise,
        'status' => $status,
    ]);
}

it("renders KP's FB calculation columns with points, value and income", function () {
    $alice = fbReportDistributor('FBAAA1', 'Alice');
    $legacy = fbReportDistributor('FBBBB2', 'Bob');

    makeFbParticipant($alice, 1, 0, '2026-07-01', '2026-07-14');
    makeFbResult($alice, 36, 200, 7_200, FortuneBonusResult::STATUS_CREDITED, '2026-07-01');
    // Written before the pool + points rework: no points, no point value.
    makeFbResult($legacy, null, null, 5_100, FortuneBonusResult::STATUS_CREDITED, '2026-07-01', level: 1, position: 2);

    RankQualification::create([
        'distributor_id' => $alice,
        'rank_number' => 2,
        'month_start' => '2026-07-01',
        'occurrence_in_month' => 1,
        'status' => RankQualification::STATUS_QUALIFIED,
    ]);

    $res = $this->actingAs(fbReportAdmin())
        ->get(route('admin.compensation.fb-calculation.index'))
        ->assertOk();

    $res->assertSee('S.No')
        ->assertSee('Arete Center')
        ->assertSee('FB Points')
        ->assertSee('Income')
        ->assertSee('FBAAA1')
        ->assertSee('14/07/26')   // enrolment date, d/m/y
        ->assertSee('₹2.00')      // the month's point value
        ->assertSee('₹72.00')     // 36 points × ₹2
        ->assertSee('—');         // legacy row's points/value, and the ADC center

    // Rank comes from the month's qualification, not the enrolment tier, and
    // renders the ladder's NAME rather than the bare number (KP's mock).
    $res->assertSee('PEARL PARTNER');
    $res->assertDontSee('non_ranked');
});

it('searches the FB calculation report by ADN or name', function () {
    $alice = fbReportDistributor('FBAAA1', 'Alice Kumar');
    $bob = fbReportDistributor('FBBBB2', 'Bob Rao');
    makeFbResult($alice, 36, 200, 7_200, FortuneBonusResult::STATUS_CREDITED, '2026-07-01');
    makeFbResult($bob, 9, 200, 1_800, FortuneBonusResult::STATUS_CREDITED, '2026-07-01', position: 2);

    $admin = fbReportAdmin();

    $this->actingAs($admin)
        ->get(route('admin.compensation.fb-calculation.index', ['q' => 'FBAAA1']))
        ->assertOk()
        ->assertSee('FBAAA1')
        ->assertDontSee('FBBBB2');

    $this->actingAs($admin)
        ->get(route('admin.compensation.fb-calculation.index', ['q' => 'Bob']))
        ->assertOk()
        ->assertSee('FBBBB2')
        ->assertDontSee('FBAAA1');
});

it('exports the FB calculation report with the points columns ungrouped', function () {
    $alice = fbReportDistributor('FBAAA1', 'Alice');
    $legacy = fbReportDistributor('FBBBB2', 'Bob');
    makeFbParticipant($alice, 1, 0, '2026-07-01', '2026-07-14');
    makeFbResult($alice, 36, 200, 7_200, FortuneBonusResult::STATUS_CREDITED, '2026-07-01');
    makeFbResult($legacy, null, null, 5_100, FortuneBonusResult::STATUS_CREDITED, '2026-07-01', position: 2);

    RankQualification::create([
        'distributor_id' => $alice,
        'rank_number' => 1,
        'month_start' => '2026-07-01',
        'occurrence_in_month' => 1,
        'status' => RankQualification::STATUS_QUALIFIED,
    ]);

    $res = $this->actingAs(fbReportAdmin())
        ->get(route('admin.compensation.fb-calculation.export'));

    $res->assertOk();
    $csv = $res->getContent();

    expect($csv)->toContain('SNo,ADN,Arete Center,Name,Title,Rank,Date,Level,FB Points,Value (Rs),Income (Rs),Status');
    expect($csv)->toContain('"SILVER PARTNER",14/07/26,0,36,2.00,72.00,'); // rank name, then points × value → income
    expect($csv)->toContain(',"",,0,,,51.00,');                             // legacy row: no rank, date, points or value
});

it('surfaces the frozen fortune pool on the month screen', function () {
    $alice = fbReportDistributor('FBAAA1', 'Alice');
    makeFbParticipant($alice, 1, 0, '2026-07-01', '2026-07-14');
    makeFbResult($alice, 36, 200, 7_200, FortuneBonusResult::STATUS_CREDITED, '2026-07-01');

    FortuneMonthlyPool::create([
        'month_start' => '2026-07-01',
        'company_bv_paise' => 400_000_000,
        'pool_rate_bp' => 500,
        'pool_paise' => 20_000_000,
        'total_points' => 100_000,
        'point_value_paise' => 200,
        'payout_paise' => 20_000_000,
        'leftover_paise' => 0,
    ]);

    $this->actingAs(fbReportAdmin())
        ->get(route('admin.compensation.fortune-bonus.show', ['month' => '2026-07']))
        ->assertOk()
        ->assertSee('Frozen month economics')
        ->assertSee('Total FB points')
        // Literal Indian-grouped strings: this doubles as the display-format
        // guard — Illuminate\Support\Number would render 4,000,000.00 here
        // because modern ICU dropped lakh grouping from the Indian locales.
        ->assertSee('₹40,00,000.00')  // company BV
        ->assertSee('5%')             // pool rate
        ->assertSee('₹2,00,000.00')   // pool and payout
        ->assertSee('1,00,000')       // total FB points
        ->assertSee('₹2.00');         // point value
});

it('degrades to a dash when a fortune month has no frozen pool row', function () {
    $alice = fbReportDistributor('FBAAA1', 'Alice');
    makeFbParticipant($alice, 1, 0, '2026-06-01', '2026-06-14');
    makeFbResult($alice, null, null, 5_100, FortuneBonusResult::STATUS_CREDITED, '2026-06-01');

    $this->actingAs(fbReportAdmin())
        ->get(route('admin.compensation.fortune-bonus.show', ['month' => '2026-06']))
        ->assertOk()
        ->assertSee('Frozen month economics')
        ->assertSee('No frozen pool row for this month')
        ->assertSee('—');
});

it('lists the pool and point value per month on the fortune batch list', function () {
    $alice = fbReportDistributor('FBAAA1', 'Alice');
    makeFbResult($alice, 36, 200, 7_200, FortuneBonusResult::STATUS_CREDITED, '2026-07-01');

    FortuneMonthlyPool::create([
        'month_start' => '2026-07-01',
        'company_bv_paise' => 400_000_000,
        'pool_rate_bp' => 500,
        'pool_paise' => 20_000_000,
        'total_points' => 100_000,
        'point_value_paise' => 200,
        'payout_paise' => 20_000_000,
        'leftover_paise' => 0,
    ]);

    $this->actingAs(fbReportAdmin())
        ->get(route('admin.compensation.fortune-bonus.index'))
        ->assertOk()
        ->assertSee('Total points')
        ->assertSee('₹2,00,000.00')
        ->assertSee('1,00,000')
        ->assertSee('₹2.00');
});

it('shows the distributor their own FB points times the frozen point value', function () {
    $alice = fbReportDistributor('FBAAA1', 'Alice');
    makeFbResult($alice, 36, 200, 7_200, FortuneBonusResult::STATUS_CREDITED, '2026-07-01');
    $user = User::find(DB::table('distributors')->where('id', $alice)->value('user_id'));

    $this->actingAs($user)
        ->get(route('income.fortune-bonus'))
        ->assertOk()
        ->assertSee('36 × ₹2.00')
        ->assertSee('Credited')
        // Historical fact only — never a projection of what they might earn.
        ->assertDontSee('could earn')
        ->assertDontSee('will earn');
});

it('renders the frozen per-level cascade economics for a cascade month', function () {
    $alice = fbReportDistributor('FBAAA1', 'Alice');
    makeFbParticipant($alice, 1, 0, '2026-08-01', '2026-08-14');
    makeFbResult($alice, 35, 113_200, 3_000_000, FortuneBonusResult::STATUS_CREDITED, '2026-08-01');

    $pool = FortuneMonthlyPool::create([
        'month_start' => '2026-08-01',
        'company_bv_paise' => 100_000_000,
        'pool_rate_bp' => 500,
        'pool_paise' => 5_000_000,
        'total_points' => 44,
        'point_value_paise' => null,
        'payout_paise' => 4_999_200,
        'leftover_paise' => 800,
        'min_commission_paise' => 3000,
        'guaranteed_total_paise' => 15_000,
        'is_shortfall' => false,
        'shortfall_per_head_paise' => null,
    ]);

    FortuneMonthlyPoolLevel::create([
        'fortune_monthly_pool_id' => $pool->id,
        'matrix_level' => 0,
        'payout_mode' => 'capped',
        'cap_paise' => 3_000_000,
        'participants' => 1,
        'points' => 35,
        'point_value_paise' => 113_200,
        'paid_paise' => 3_000_000,
    ]);
    FortuneMonthlyPoolLevel::create([
        'fortune_monthly_pool_id' => $pool->id,
        'matrix_level' => 1,
        'payout_mode' => 'residual',
        'cap_paise' => null,
        'participants' => 3,
        'points' => 9,
        'point_value_paise' => 220_800,
        'paid_paise' => 1_996_200,
    ]);

    $this->actingAs(fbReportAdmin())
        ->get(route('admin.compensation.fortune-bonus.show', ['month' => '2026-08']))
        ->assertOk()
        ->assertSee('Frozen per-level economics')
        ->assertSee('Per level')            // header stat instead of a single value
        ->assertSee('Minimum guarantee')
        ->assertSee('₹150.00')              // 5 × ₹30 reserved
        ->assertSee('₹1,132.00')            // L0 point value
        ->assertSee('₹2,208.00')            // L1 point value
        ->assertSee('₹30,000.00')           // the L0 cap
        ->assertSee('Residual');
});

it('flags a shortfall month where the ₹30 minimum was pro-rated', function () {
    $alice = fbReportDistributor('FBAAA1', 'Alice');
    makeFbParticipant($alice, 1, 0, '2026-08-01', '2026-08-14');
    makeFbResult($alice, 0, 0, 200, FortuneBonusResult::STATUS_CREDITED, '2026-08-01');

    FortuneMonthlyPool::create([
        'month_start' => '2026-08-01',
        'company_bv_paise' => 10_000,
        'pool_rate_bp' => 500,
        'pool_paise' => 500,
        'total_points' => 0,
        'point_value_paise' => null,
        'payout_paise' => 400,
        'leftover_paise' => 100,
        'min_commission_paise' => 3000,
        'guaranteed_total_paise' => 6000,
        'is_shortfall' => true,
        'shortfall_per_head_paise' => 200,
    ]);

    $this->actingAs(fbReportAdmin())
        ->get(route('admin.compensation.fortune-bonus.show', ['month' => '2026-08']))
        ->assertOk()
        ->assertSee('Shortfall month')
        ->assertSee('₹2.00');
});

it('hides the FB calculation report while the feature is off', function (): void {
    Feature::for(null)->deactivate(FortuneBonusFeature::class);

    $admin = fbReportAdmin();

    $this->actingAs($admin)
        ->get(route('admin.compensation.fb-calculation.index'))
        ->assertNotFound();

    $this->actingAs($admin)
        ->get(route('admin.compensation.fb-calculation.export'))
        ->assertNotFound();
});
