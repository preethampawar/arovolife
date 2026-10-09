<?php

declare(strict_types=1);

use App\Modules\Commerce\Support\Bv;
use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankMonthlyPool;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\RankBonusService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
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

    // The AO-GO line shared the Rank 1 pool in a legacy month.
    $res->assertSee('AO-GO');
    $res->assertSee('2,800.00');               // 5 points × ₹560

    // Rank 2 equal split with one held re-qualifier.
    $res->assertSee('6,800.00');
    $res->assertSee('Held');

    // A rank with no rows in a legacy month is asterisked; the per-rank pool %
    // that once estimated its unspent pool is retired (client 2026-10-05).
    $res->assertSee('unspent *');
    $res->assertSee('priced before the two-pass rule');
    $res->assertDontSee('Pool %');

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

    $rows = XlsxReader::rows($res->streamedContent());

    expect(XlsxReader::anyCellContains($rows, 'Month Turnover'))->toBeTrue();
    expect(XlsxReader::anyCellContains($rows, '14000'))->toBeTrue();       // Rank 1 pool, ungrouped
    expect(XlsxReader::anyCellContains($rows, 'AO-GO (Rank 1 pool)'))->toBeTrue();   // legacy month
    expect(XlsxReader::anyCellContains($rows, 'AO-GO (pass 1)'))->toBeFalse();
    expect(XlsxReader::anyCellContains($rows, 'MONTH TOTAL'))->toBeTrue();
    expect(XlsxReader::anyCellContains($rows, 'Computed At'))->toBeTrue();
    expect(XlsxReader::anyCellContains($rows, '20800'))->toBeTrue();       // grand total income
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
    $res->assertSee('Silver Partner pool');
    $res->assertDontSee('of envelope');
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
    $res->assertSee('Silver Partner pool');
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

it('embeds the collapsible Rank 1 point-value formula strip inside each month block', function () {
    rbIoSeedWorkedMonth('2026-06-01');
    rbIoSeedWorkedMonth('2026-07-01');

    $res = $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index'))
        ->assertOk();
    $res->assertSee('point value was calculated');
    $res->assertSee('Total points = (Qualifiers × RAP points) + AO-GO points');
    $res->assertSee('(2 × 10) + 5 = <strong>25</strong>', false);
    $res->assertSee('<strong>₹5,600</strong> per qualifier', false);
    $res->assertDontSee('border-gray-200" open>', false);

    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index', ['month' => '2026-07']))
        ->assertOk()
        ->assertSee('border-gray-200" open>', false);
});

it('omits the formula strip for a month with no Rank 1 rows', function () {
    rbIoResult(104, '2026-06-01', 2, 680_000, 1, 680_000);

    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index'))
        ->assertOk()
        ->assertSee('June 2026')
        ->assertDontSee('point value was calculated');
});

it('shows gross, the credit-time repurchase deduction and the credited amount on the rank bonus month screen — never TDS or admin charge', function () {
    $dist = Distributor::factory()->create();
    $row = rbIoResult($dist->id, '2026-07-01', 1, 1_400_000, 1, 1_400_000);
    $row->update(['repurchase_deduction_paise' => 140_000, 'net_paise' => 1_260_000]);

    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rank-bonus.show', ['month' => '2026-07']))
        ->assertOk()
        ->assertSee('Repurchase deduction')
        ->assertSee('Credited to wallet')
        ->assertSee('-₹1,400.00')
        ->assertSee('₹12,600.00')
        ->assertDontSee('TDS (5%)')
        ->assertDontSee('₹25,000 per monthly batch');
});

it('shows the credit-time repurchase deduction and credited amount per rank on the I&O report', function () {
    $dist = Distributor::factory()->create();
    $row = rbIoResult($dist->id, '2026-07-01', 2, 1_400_000, 1, 1_400_000);
    $row->update(['repurchase_deduction_paise' => 140_000, 'net_paise' => 1_260_000]);

    $res = $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index', ['month' => '2026-07']))
        ->assertOk();

    $res->assertSee('Repurchase deduction');
    $res->assertSee('Credited to wallet');
    $res->assertSee('-₹1,400.00');
    $res->assertSee('12,600.00');
});

it('carries the deduction and credited columns into the rank bonus I&O CSV', function () {
    $dist = Distributor::factory()->create();
    $row = rbIoResult($dist->id, '2026-07-01', 2, 1_400_000, 1, 1_400_000);
    $row->update(['repurchase_deduction_paise' => 140_000, 'net_paise' => 1_260_000]);

    $rows = XlsxReader::rows($this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.export'))
        ->assertOk()
        ->streamedContent());

    expect($rows[0])->toContain('Income (Rs)')
        ->and($rows[0])->toContain('Repurchase Deduction (Rs)')
        ->and($rows[0])->toContain('Credited to Wallet (Rs)');
    expect(XlsxReader::anyCellContains($rows, '1400'))->toBeTrue();
    expect(XlsxReader::anyCellContains($rows, '12600'))->toBeTrue();
});

it('counts qualifiers blocked by the repurchase wallet gate so their unspent share is explained', function () {
    $paid = Distributor::factory()->create();
    $blocked = Distributor::factory()->create();

    rbIoResult($paid->id, '2026-07-01', 2, 1_400_000, 2, 700_000);
    rbIoResult($blocked->id, '2026-07-01', 2, 1_400_000, 2, 0, [
        'status' => RankBonusResult::STATUS_REPURCHASE_WALLET_BLOCKED,
    ]);

    $res = $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index', ['month' => '2026-07']))
        ->assertOk();

    $res->assertSee('Blocked');
    $res->assertSee('Qualifiers whose repurchase wallet was not at ₹0 at month end', false);
    // Half the pool went unpaid, and the Blocked count is the only thing on the
    // page that says why.
    $res->assertSee('7,000.00');
});

it('carries the blocked count into the rank bonus I&O CSV', function () {
    $blocked = Distributor::factory()->create();
    rbIoResult($blocked->id, '2026-07-01', 2, 1_400_000, 1, 0, [
        'status' => RankBonusResult::STATUS_REPURCHASE_WALLET_BLOCKED,
    ]);

    $rows = XlsxReader::rows($this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.export'))
        ->assertOk()
        ->streamedContent());

    expect($rows[0])->toContain('Qualifiers')
        ->and($rows[0])->toContain('Held')
        ->and($rows[0])->toContain('Repurchase Blocked')
        ->and($rows[0])->toContain('Total Points');

    $qualifiersCol = array_search('Qualifiers', $rows[0], true);
    $heldCol = array_search('Held', $rows[0], true);
    $blockedCol = array_search('Repurchase Blocked', $rows[0], true);
    $totalCol = array_search('Total Points', $rows[0], true);

    // qualifiers, held, blocked — the rank-2 row.
    $rank2Row = collect($rows)->first(fn (array $row): bool => ($row[$qualifiersCol] ?? null) === '1'
        && ($row[$heldCol] ?? null) === '0'
        && ($row[$blockedCol] ?? null) === '1');
    expect($rank2Row)->not->toBeNull();
    expect($totalCol)->not->toBeFalse();
});

it('F91: rank-bonus tiles use the stored qualifier count and never truncate the pool/credited paise', function () {
    // Two RAP qualifiers plus one AO-GO grantee: the engine writes the same
    // frozen qualifier_count (2) onto every row for the rank+month. Before
    // the fix, the tile recomputed COUNT(*) over the rows (3) and disagreed
    // with the "Qualifiers 2" formula strip above it, on the same screen.
    foreach ([201, 202] as $id) {
        rbIoResult($id, '2026-09-01', 1, 2_504_320, 2, 900_900);
    }
    rbIoResult(203, '2026-09-01', 1, 2_504_320, 2, 450_450);

    $res = $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rank-bonus.show', ['month' => '2026-09']))
        ->assertOk();

    $res->assertSee('2 qualifiers', false);
    $res->assertDontSee('3 qualifiers', false);
    // Pool ₹25,043.20 and credited ₹22,522.50 must render to the paisa,
    // never truncated to whole rupees.
    $res->assertSee('₹25,043.20');
    $res->assertSee('₹22,522.50');
});

/**
 * A distributor who reaches a rank after the month's pool was frozen is refused
 * by the engine — a divided pool is never re-divided. The admin report is where
 * that refusal has to become visible, or the gap is silent.
 */
it('names distributors who qualified after the rank pool was frozen', function () {
    $onTime = Distributor::factory()->create();
    $late = Distributor::factory()->create();

    RankMonthlyPool::create([
        'month_start' => '2026-07-01',
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

    rbIoResult($onTime->id, '2026-07-01', 1, 1_400_000, 1, 1_400_000, [
        'rap_points' => 10, 'total_points' => 10, 'point_value_paise' => 140_000,
    ]);

    // Recorded after the freeze: on the roster of qualifiers, not of the pool.
    RankQualification::create([
        'distributor_id' => $late->id,
        'rank_number' => 1,
        'month_start' => '2026-07-01',
        'occurrence_in_month' => 1,
        'is_carry_forward' => false,
        'status' => RankQualification::STATUS_QUALIFIED,
    ]);

    $res = $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rank-bonus.show', ['month' => '2026-07']))
        ->assertOk();

    $res->assertSee('Qualified after the pool was frozen');
    $res->assertSee($late->adn);
});

/**
 * Runs the real engine for July 2026 on a 10,00,000 BV month (envelope
 * ₹2,00,000) with one achiever qualified at the given rank, or nobody at all
 * when $rank is null.
 */
function rbIoRunTwoPassMonth(?int $rank): void
{
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => 999001, 'order_id' => 980001, 'bv_paise' => 100_000_000, 'type' => 'accrual',
        'effective_at' => '2026-07-10 10:00:00', 'created_at' => '2026-07-10 10:00:00', 'updated_at' => '2026-07-10 10:00:00',
    ]);
    if ($rank !== null) {
        RankQualification::create([
            'distributor_id' => Distributor::factory()->create()->id, 'rank_number' => $rank, 'month_start' => '2026-07-01',
            'occurrence_in_month' => 1, 'is_carry_forward' => false, 'status' => RankQualification::STATUS_QUALIFIED,
        ]);
    }
    app(RankBonusService::class)->runForMonth(Carbon::parse('2026-07-01'));
}

/**
 * A month frozen under the client's 2026-10-05 two-pass rule: one Rank-1
 * achiever paid 72 × min(₹200, ⌊₹2,00,000 ÷ 72⌋) = ₹14,400 in pass 1; pass 2
 * has ₹1,85,600 and nobody to share it, so all of it is the month's leftover.
 */
it('renders a two-pass frozen month on the rank bonus reports', function () {
    rbIoRunTwoPassMonth(1);

    $res = $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index'))
        ->assertOk()
        ->assertSee('14,400.00')
        ->assertSee('leftover ₹1,85,600.00', false);

    // Pass summary above the rank table, and a Pass column instead of a pool %.
    $res->assertSee('Pass 1')->assertSee('Pass 2')->assertDontSee('Pool %');
    $res->assertSee('₹200.00');                 // pass-1 point value
    $res->assertSee('₹1,85,600.00');            // pass-2 pool and leftover
    $res->assertSee('Which pass priced this rank');
    $res->assertSee('Two-pass formula');
    $res->assertDontSee('per-rank pool rule in force before the two-pass rule');
    $res->assertDontSee('freeze is incomplete');

    // The envelope % and ₹ are the pass row's frozen figures, not the current
    // setting: moving the setting after the freeze changes nothing on the page.
    DB::table('settings')->insert(['key' => 'comp.rank.envelope_bp', 'value' => '1000', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index'))
        ->assertOk()
        ->assertSee('Rank envelope (20%)')
        ->assertSee('₹2,00,000.00')
        ->assertDontSee('Rank envelope (10%)');

    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rank-bonus.show', '2026-07'))
        ->assertOk()
        ->assertSee('Pass 1 value')
        ->assertSee('Pass 1 points = Σ (payable × RAP) of the pass-1 ranks + AO-GO points')
        ->assertSee('min(₹200, ⌊ ₹2,00,000.00 ÷ 72 ⌋)', false)
        ->assertSee('72 × ₹200 = <strong>₹14,400.00</strong>', false)
        ->assertSee('₹2,00,000.00 − ₹14,400.00 = <strong>₹1,85,600.00</strong>', false)
        ->assertDontSee('Silver Partner pool');

    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-calculation.index', ['month' => '2026-07']))
        ->assertOk()
        ->assertSee('Pass 2 value')
        ->assertSee('min(₹200, ⌊ ₹2,00,000.00 ÷ 72 ⌋)', false);
});

it('exports the Pass column and one summary row per pass for a two-pass month', function () {
    rbIoRunTwoPassMonth(1);

    $rows = XlsxReader::rows($this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.export'))
        ->assertOk()
        ->streamedContent());

    $passCol = array_search('Pass', $rows[0], true);
    $nameCol = array_search('Rank Name', $rows[0], true);
    $poolCol = array_search('Pool (Rs)', $rows[0], true);
    $valueCol = array_search('Point Value / Share (Rs)', $rows[0], true);
    $leftoverCol = array_search('Leftover (Rs)', $rows[0], true);
    expect($passCol)->toBeInt()
        ->and($nameCol)->toBeInt()
        ->and($poolCol)->toBeInt()
        ->and($valueCol)->toBeInt()
        ->and($leftoverCol)->toBeInt();
    assert(is_int($passCol) && is_int($nameCol) && is_int($poolCol) && is_int($valueCol) && is_int($leftoverCol));
    expect($passCol)->toBe($nameCol + 1);

    $byName = collect($rows)->keyBy(fn (array $row): string => (string) ($row[$nameCol] ?? ''));

    expect($byName->get('Silver Partner')[$passCol])->toBe('1');
    expect($byName->get('Gold Partner')[$passCol])->toBe('2');
    expect($byName->get('PASS 1')[$poolCol])->toBe('200000');
    expect($byName->get('PASS 1')[$valueCol])->toBe('200');
    expect($byName->get('PASS 2')[$poolCol])->toBe('185600');
    expect($byName->get('PASS 2')[$leftoverCol])->toBe('185600');
    expect(XlsxReader::anyCellContains($rows, 'MONTH TOTAL'))->toBeTrue();
});

/**
 * Task 10 hand-off: a month priced by the real engine with a Rank-1 achiever,
 * one AO-GO grant (36 points, pass 1) and a Rank-4 achiever (pass 2). The page
 * shows a Pass cell of 2 and labels the AO-GO line as priced in pass 1; the
 * export names it `AO-GO (pass 1)`.
 */
it('labels the AO-GO line as pass 1 and shows a Pass cell of 2 for a month priced by the engine', function () {
    $grantee = Distributor::factory()->create()->id;
    RankAogoGrant::create([
        'distributor_id' => $grantee,
        'month_start' => '2026-07-01',
        'grant_number' => 1,
        'points' => 36,
        'previous_rank_number' => 1,
        'status' => RankAogoGrant::STATUS_GRANTED,
    ]);
    RankQualification::create([
        'distributor_id' => Distributor::factory()->create()->id, 'rank_number' => 1, 'month_start' => '2026-07-01',
        'occurrence_in_month' => 1, 'is_carry_forward' => false, 'status' => RankQualification::STATUS_QUALIFIED,
    ]);
    rbIoRunTwoPassMonth(4);

    // The grant was priced through the engine, in pass 1.
    expect(RankAogoGrant::where('distributor_id', $grantee)->value('status'))->toBe(RankAogoGrant::STATUS_CREDITED)
        ->and(RankMonthlyPool::where('month_start', '2026-07-01')->where('rank_number', 4)->value('pass'))->toBe(2);

    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index'))
        ->assertOk()
        ->assertSee('data-pass-cell>1<', false)
        ->assertSee('data-pass-cell>2<', false)
        ->assertSee('priced in pass 1 with Rank 1')
        ->assertDontSee('shared the Rank 1 pool');

    $rows = XlsxReader::rows($this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.export'))
        ->assertOk()
        ->streamedContent());

    $passCol = array_search('Pass', $rows[0], true);
    $nameCol = array_search('Rank Name', $rows[0], true);
    expect($passCol)->toBeInt()->and($nameCol)->toBeInt();
    assert(is_int($passCol) && is_int($nameCol));

    $byName = collect($rows)->keyBy(fn (array $row): string => (string) ($row[$nameCol] ?? ''));

    expect(XlsxReader::anyCellContains($rows, 'AO-GO (pass 1)'))->toBeTrue()
        ->and(XlsxReader::anyCellContains($rows, 'AO-GO (Rank 1 pool)'))->toBeFalse()
        ->and($byName->get('AO-GO (pass 1)')[$passCol] ?? null)->toBe('1')
        ->and($byName->get('Gold Partner')[$passCol] ?? null)->toBe('2');
});

/**
 * Review Focus 2: pass 2 divides the whole envelope when pass 1 has nobody, and
 * the month has no Rank-1 rows at all — the formula strip must still render.
 * 1,125 Gold points → ⌊₹2,00,000 ÷ 1,125⌋ = ₹177 a point, ₹1,99,125 paid, ₹875 left.
 */
it('shows the two-pass formula for a month whose only achiever is priced in pass 2', function () {
    rbIoRunTwoPassMonth(4);

    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index'))
        ->assertOk()
        ->assertSee('two-pass point values were calculated')
        ->assertSee('Pass 1 points =')
        ->assertSee('= <strong>0</strong>', false)
        ->assertSee('min(₹200, ⌊ ₹2,00,000.00 ÷ 1,125 ⌋)', false)
        ->assertSee('<strong>₹177</strong>', false)
        ->assertSee('leftover ₹875.00', false);

    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rank-bonus.show', '2026-07'))
        ->assertOk()
        ->assertSee('Pass 2 value')
        ->assertSee('min(₹200, ⌊ ₹2,00,000.00 ÷ 1,125 ⌋)', false);
});

/**
 * A frozen two-pass month with nobody to pay has result rows for no one: its
 * two pass rows (the whole envelope left over) are what lists it.
 */
it('lists a two-pass month frozen with no achievers, with the whole envelope as leftover', function () {
    rbIoRunTwoPassMonth(null);

    expect(DB::table('rank_bonus_results')->count())->toBe(0);

    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index'))
        ->assertOk()
        ->assertSee('July 2026')
        ->assertSee('Two-pass formula')
        ->assertSee('leftover ₹2,00,000.00', false)
        ->assertDontSee('No Rank Bonus months yet.');
});

/**
 * Principle 5: the engine writes both pass rows in one transaction, so a month
 * with one is a corrupted freeze — the page says so instead of reading the
 * missing pass-2 row as "leftover ₹0".
 */
it('flags a two-pass month whose pass-2 row is missing instead of showing leftover zero', function () {
    rbIoRunTwoPassMonth(1);
    DB::table('rank_monthly_passes')->where('month_start', '2026-07-01')->where('pass', 2)->delete();

    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index'))
        ->assertOk()
        ->assertSee('freeze is incomplete')
        ->assertSee('leftover unknown (pass row missing)')
        ->assertDontSee('leftover ₹0.00', false);

    $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rank-bonus.show', '2026-07'))
        ->assertOk()
        ->assertSee('Pass 2 row missing')
        ->assertSee('2–8. Not shown — a pass row is missing.');
});

it('labels a month priced before the two-pass rule and gives it no pass summary', function () {
    rbIoSeedWorkedMonth('2026-07-01');
    // A pool row frozen under the old rule carries the migrated column default
    // pass = 1; the report must not read it as "priced in pass 1".
    RankMonthlyPool::create([
        'month_start' => '2026-07-01',
        'rank_number' => 1,
        'company_turnover_paise' => 100_000_000,
        'envelope_bp' => 2_000,
        'pool_paise' => 1_400_000,
        'rap_points' => 10,
        'payable_count' => 2,
        'aogo_points' => 5,
        'total_points' => 25,
        'point_value_paise' => 56_000,
        'gross_per_qualifier_paise' => 560_000,
        'payout_paise' => 1_400_000,
        'leftover_paise' => 0,
    ]);
    expect(RankMonthlyPool::query()->value('pass'))->toBe(1);

    $res = $this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.index'))
        ->assertOk();

    $res->assertSee('This month was priced under the per-rank pool rule in force before the two-pass rule (client 2026-10-05); it has no pass summary.');
    $res->assertDontSee('Pass 1');
    $res->assertDontSee('Two-pass formula');
    // The Pass cell of a legacy rank is a dash, never the migrated default 1.
    $res->assertSee('data-pass-cell>—<', false);
    $res->assertDontSee('data-pass-cell>1<', false);

    $rows = XlsxReader::rows($this->actingAs(rbIoAdmin())
        ->get(route('admin.compensation.rb-input-output.export'))
        ->assertOk()
        ->streamedContent());
    expect(XlsxReader::anyCellContains($rows, 'PASS 1'))->toBeFalse();
});
