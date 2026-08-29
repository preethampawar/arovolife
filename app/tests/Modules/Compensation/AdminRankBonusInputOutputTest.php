<?php

declare(strict_types=1);

use App\Modules\Commerce\Support\Bv;
use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\RankBonusFeature;
use Database\Seeders\RankTiersSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::for(null)->activate(RankBonusFeature::class);
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RankTiersSeeder::class);
});

function rbIoAdmin(string $role = 'admin'): User
{
    $user = User::create([
        'full_name' => 'RB IO Admin',
        'email' => 'rb-io-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

/**
 * One rank_bonus_results row carrying the engine's frozen month snapshot.
 * FKs are disabled in these tests, so bare distributor ids are fine — the
 * report aggregates rows without joining distributors.
 *
 * @param  array{rap_points?: ?int, total_points?: ?int, point_value_paise?: ?int, status?: string}  $overrides
 */
function rbIoResult(int $distributorId, string $monthStart, int $rank, int $poolPaise, int $qualifierCount, int $grossPaise, array $overrides = []): RankBonusResult
{
    $status = $overrides['status'] ?? RankBonusResult::STATUS_CREDITED;

    return RankBonusResult::create([
        'distributor_id' => $distributorId,
        'month_start' => $monthStart,
        'rank_number' => $rank,
        'company_turnover_paise' => 100_000_000,   // 10,00,000 BV month
        'pool_paise' => $poolPaise,
        'qualifier_count' => $qualifierCount,
        'rap_points' => $overrides['rap_points'] ?? null,
        'aogo_points' => null,
        'total_points' => $overrides['total_points'] ?? null,
        'point_value_paise' => $overrides['point_value_paise'] ?? null,
        'gross_paise' => $grossPaise,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_paise' => $grossPaise,
        'status' => $status,
        'credited_at' => $status === RankBonusResult::STATUS_CREDITED ? now() : null,
    ]);
}

/**
 * The worked month: 10,00,000 BV turnover → 20% envelope ₹2,00,000.
 * Rank 1 (7% → ₹14,000) pays 2 qualifiers × 10 RAP + one 5-point AO-GO grant
 * at the ₹560 point value (14,000 ÷ 25). Rank 2 (3.4% → ₹6,800) pays one
 * qualifier the full pool, with a second re-qualifier held.
 */
function rbIoSeedWorkedMonth(string $monthStart): void
{
    foreach ([101, 102] as $id) {
        rbIoResult($id, $monthStart, 1, 1_400_000, 2, 560_000, [
            'rap_points' => 10, 'total_points' => 25, 'point_value_paise' => 56_000,
        ]);
    }

    RankAogoGrant::create([
        'distributor_id' => 103,
        'month_start' => $monthStart,
        'grant_number' => 1,
        'points' => 5,
        'previous_rank_number' => 2,
        'point_value_paise' => 56_000,
        'income_paise' => 280_000,
        'status' => RankAogoGrant::STATUS_CREDITED,
        'credited_at' => now(),
    ]);

    // The engine also writes a credited Rank-1 result row for the grantee
    // (aogo_points set, rap_points null) — the report must not count it into
    // the achievers' income on top of the AO-GO line.
    rbIoResult(103, $monthStart, 1, 1_400_000, 2, 280_000, [
        'total_points' => 25, 'point_value_paise' => 56_000,
    ]);
    RankBonusResult::where('distributor_id', 103)
        ->where('month_start', $monthStart)
        ->update(['aogo_points' => 5]);

    rbIoResult(104, $monthStart, 2, 680_000, 1, 680_000);
    rbIoResult(105, $monthStart, 2, 680_000, 1, 0, [
        'status' => RankBonusResult::STATUS_REQUALIFICATION_HELD,
    ]);
}

it('renders a month block with per-rank frozen economics, the AO-GO line and derived leftovers', function () {
    rbIoSeedWorkedMonth('2026-07-01');

    $res = $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index'))
        ->assertOk();

    // Month header: turnover and the envelope amount.
    $res->assertSee('July 2026');
    $res->assertSee(Bv::format(100_000_000));
    $res->assertSee('2,00,000.00');            // 20% envelope of the turnover

    // Rank 1 points model: pool, point value, per-qualifier income.
    $res->assertSee('14,000.00');
    $res->assertSee('₹560.00');
    $res->assertSee('11,200.00');              // 2 qualifiers × 10 RAP × ₹560

    // The AO-GO line shares the Rank 1 pool.
    $res->assertSee('AO-GO');
    $res->assertSee('2,800.00');               // 5 points × ₹560

    // Rank 2 equal split with one held re-qualifier.
    $res->assertSee('6,800.00');
    $res->assertSee('Held');

    // A rank with no rows shows the estimated, asterisked unspent pool
    // (Rank 3 = 2.7% of the envelope).
    $res->assertSee('5,400.00');
    $res->assertSee('unspent *');
    $res->assertSee('estimated from the month');

    // Grand total: 2 × 5,600 + 2,800 + 6,800 = ₹20,800; every frozen rank
    // reconciled to zero leftover.
    $res->assertSee('20,800.00');

    // The header stamps when the month's rows were written.
    $res->assertSee('Computed');
    $res->assertSee(now()->format('d M Y H:i'));
});

it('filters by month', function () {
    rbIoSeedWorkedMonth('2026-06-01');
    rbIoSeedWorkedMonth('2026-07-01');

    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index', ['month' => '2026-06']))
        ->assertOk()
        ->assertSee('June 2026')
        ->assertDontSee('July 2026');
});

it('lists a month whose only Rank-1 spend was an AO-GO grant', function () {
    RankAogoGrant::create([
        'distributor_id' => 110,
        'month_start' => '2026-05-01',
        'grant_number' => 1,
        'points' => 5,
        'previous_rank_number' => 3,
        'point_value_paise' => 10_000,
        'income_paise' => 50_000,
        'status' => RankAogoGrant::STATUS_CREDITED,
        'credited_at' => now(),
    ]);

    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index'))
        ->assertOk()
        ->assertSee('May 2026')
        ->assertSee('AO-GO')
        ->assertSee('500.00');
});

it('exports a CSV with per-rank rows, the AO-GO line and a month total', function () {
    rbIoSeedWorkedMonth('2026-07-01');

    $res = $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.export'))
        ->assertOk();

    $csv = $res->getContent();

    expect($csv)->toContain('Month Turnover');
    expect($csv)->toContain('14000.00');       // Rank 1 pool, ungrouped in CSV
    expect($csv)->toContain('AO-GO (Rank 1 pool)');
    expect($csv)->toContain('"MONTH TOTAL"');
    expect($csv)->toContain('Computed At');
    expect($csv)->toContain('20800.00');       // grand total income
});

it('shows the empty state before any rank month exists', function () {
    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index'))
        ->assertOk()
        ->assertSee('No Rank Bonus months yet');
});

it('is hidden behind the Rank Bonus flag', function () {
    Feature::for(null)->deactivate(RankBonusFeature::class);

    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index'))
        ->assertNotFound();
});

it('shows the Rank 1 month header and the point-value formula with this month\'s values on the monthly page', function () {
    rbIoSeedWorkedMonth('2026-07-01');

    $res = $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rank-bonus.show', '2026-07'))
        ->assertOk();

    // Header: turnover, envelope, Rank 1 pool, points, point value.
    $res->assertSee('Month turnover');
    $res->assertSee(Bv::format(100_000_000));
    $res->assertSee('Rank envelope (20%)');
    $res->assertSee('₹2,00,000.00');
    $res->assertSee('pool (7% of envelope)');
    $res->assertSee('₹14,000.00');
    $res->assertSee('₹560');

    // Formula, symbolic then substituted.
    $res->assertSee('Total points = (Qualifiers × RAP points) + AO-GO points');
    $res->assertSee('(2 × 10) + 5 = <strong>25</strong>', false);
    $res->assertSee('÷ 25 ⌋', false);
    $res->assertSee('<strong>₹5,600</strong> per qualifier', false);
});

it('omits the Rank 1 header when the month has no Rank 1 rows', function () {
    rbIoResult(104, '2026-06-01', 2, 680_000, 1, 680_000);

    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rank-bonus.show', '2026-06'))
        ->assertOk()
        ->assertDontSee('How the');
});

it('shows the Rank 1 month header and formula on the monthly calculation report', function () {
    rbIoSeedWorkedMonth('2026-07-01');

    $res = $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-calculation.index', ['month' => '2026-07']))
        ->assertOk();

    $res->assertSee('July 2026');
    $res->assertSee('Rank envelope (20%)');
    $res->assertSee('₹2,00,000.00');
    $res->assertSee('pool (7% of envelope)');
    $res->assertSee('How the');
    $res->assertSee('(2 × 10) + 5 = <strong>25</strong>', false);
    $res->assertSee('<strong>₹5,600</strong> per qualifier', false);
    // A single filtered month renders its block expanded.
    $res->assertSee('mb-6" open>', false);

    // Unfiltered: one block per month present among the Rank-1 rows on the
    // page — the table joins distributors, so the fixture ids need real rows.
    // With more than one month on view the blocks stay collapsed.
    rbIoSeedWorkedMonth('2026-06-01');
    foreach ([101, 102, 103] as $id) {
        Distributor::factory()->create(['id' => $id]);
    }
    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-calculation.index'))
        ->assertOk()
        ->assertSee('How the')
        ->assertSee('June 2026')
        ->assertDontSee('mb-6" open>', false);
});
