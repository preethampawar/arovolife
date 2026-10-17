<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RankAogoGrant;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\AogoOfferService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function aogoQualify(int $distributorId, int $rank, string $monthStart, int $occurrence = 1): void
{
    RankQualification::create([
        'distributor_id' => $distributorId,
        'rank_number' => $rank,
        'month_start' => $monthStart,
        'occurrence_in_month' => $occurrence,
        'is_carry_forward' => false,
        'status' => RankQualification::STATUS_QUALIFIED,
    ]);
}

function aogoPastGrant(int $distributorId, string $monthStart, int $grantNumber, string $status = RankAogoGrant::STATUS_CREDITED): void
{
    RankAogoGrant::create([
        'distributor_id' => $distributorId,
        'month_start' => $monthStart,
        'grant_number' => $grantNumber,
        'points' => 5,
        'previous_rank_number' => 1,
        'status' => $status,
    ]);
}

/** A personal purchase inside the month (default 1,000 BV — Rank 1's monthly repurchase BV). */
function aogoMonthlyBv(int $distributorId, string $date, int $bvPaise = 100_000): void
{
    static $fakeOrderId = 980000;
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $fakeOrderId++,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => $date.' 10:00:00',
        'created_at' => $date.' 10:00:00',
        'updated_at' => $date.' 10:00:00',
    ]);
}

// AO-GO window rule — user decision 2026-10-17: only in the month right
// after a month with a genuine rank achievement; own purchase BV of THAT rank's
// repurchase obligation; a miss is final; no wallet condition; max 3 for life.

it('grants AO-GO in the month right after a rank achievement when that rank\'s BV is met', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-03-01');
    aogoQualify($dist->id, 3, '2026-05-01');
    aogoMonthlyBv($dist->id, '2026-06-10', 120_000); // Rank 3 → 1,200 BV

    $grants = app(AogoOfferService::class)->grantForMonth(Carbon::parse('2026-06-01'));

    expect($grants)->toHaveCount(1);
    $grant = $grants->first();
    expect($grant->distributor_id)->toBe($dist->id)
        ->and($grant->grant_number)->toBe(1)
        ->and($grant->points)->toBe(36) // comp.rank.aogo_points (client 2026-10-05)
        ->and($grant->previous_rank_number)->toBe(3) // the rank achieved last month
        ->and($grant->status)->toBe(RankAogoGrant::STATUS_GRANTED);
});

it('asks for the BV of the rank achieved last month, not always Rank 1\'s 1,000', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 3, '2026-05-01');
    aogoMonthlyBv($dist->id, '2026-06-10', 100_000); // 1,000 BV < Rank 3's 1,200

    expect(app(AogoOfferService::class)->grantForMonth(Carbon::parse('2026-06-01')))->toHaveCount(0);
});

it('does not grant to a distributor who is ranked this month', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-05-01');
    aogoQualify($dist->id, 1, '2026-06-01');
    aogoMonthlyBv($dist->id, '2026-06-10');

    expect(app(AogoOfferService::class)->grantForMonth(Carbon::parse('2026-06-01')))->toHaveCount(0);
});

it('does not grant when the rank was achieved two or more months ago', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-04-01');
    aogoMonthlyBv($dist->id, '2026-06-10');

    expect(app(AogoOfferService::class)->grantForMonth(Carbon::parse('2026-06-01')))->toHaveCount(0);
});

it('treats a missed month as final — the offer is not carried to the month after', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-05-01');
    aogoMonthlyBv($dist->id, '2026-06-10', 60_000); // June short of 1,000 BV
    aogoMonthlyBv($dist->id, '2026-07-10', 100_000); // July meets it

    $svc = app(AogoOfferService::class);

    expect($svc->grantForMonth(Carbon::parse('2026-06-01')))->toHaveCount(0)
        ->and($svc->grantForMonth(Carbon::parse('2026-07-01')))->toHaveCount(0)
        ->and(RankAogoGrant::count())->toBe(0);
});

it('does not count a carry-forward row as last month\'s achievement', function (): void {
    $dist = Distributor::factory()->create();
    RankQualification::create([
        'distributor_id' => $dist->id,
        'rank_number' => 1,
        'month_start' => '2026-05-01',
        'occurrence_in_month' => 1,
        'is_carry_forward' => true,
        'status' => RankQualification::STATUS_QUALIFIED,
    ]);
    aogoMonthlyBv($dist->id, '2026-06-10');

    expect(app(AogoOfferService::class)->grantForMonth(Carbon::parse('2026-06-01')))->toHaveCount(0);
});

it('caps AO-GO at the lifetime maximum of 3 uses', function (): void {
    $dist = Distributor::factory()->create();
    aogoPastGrant($dist->id, '2026-01-01', 1);
    aogoPastGrant($dist->id, '2026-03-01', 2);
    aogoPastGrant($dist->id, '2026-05-01', 3);
    aogoQualify($dist->id, 1, '2026-06-01');
    aogoMonthlyBv($dist->id, '2026-07-10');

    expect(app(AogoOfferService::class)->grantForMonth(Carbon::parse('2026-07-01')))->toHaveCount(0);
});

it('grants a second use after a later rank achievement', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-03-01');
    aogoPastGrant($dist->id, '2026-04-01', 1);
    aogoQualify($dist->id, 2, '2026-05-01');
    aogoMonthlyBv($dist->id, '2026-06-10', 110_000); // Rank 2 → 1,100 BV

    $grants = app(AogoOfferService::class)->grantForMonth(Carbon::parse('2026-06-01'));

    expect($grants)->toHaveCount(1)
        ->and($grants->first()->grant_number)->toBe(2)
        ->and($grants->first()->previous_rank_number)->toBe(2);
});

it('is idempotent — a rerun returns the existing grant without duplicating it', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-05-01');
    aogoMonthlyBv($dist->id, '2026-06-10');

    $svc = app(AogoOfferService::class);
    $svc->grantForMonth(Carbon::parse('2026-06-01'));
    $grants = $svc->grantForMonth(Carbon::parse('2026-06-01'));

    expect($grants)->toHaveCount(1);
    expect(RankAogoGrant::count())->toBe(1);
});

it('grants with money still in the repurchase wallet at month end — there is no wallet condition', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-05-01');
    aogoMonthlyBv($dist->id, '2026-06-10');
    aogoRepurchaseWalletCredit($dist->id, 50_000, '2026-06-20 09:00:00');

    expect(app(AogoOfferService::class)->grantForMonth(Carbon::parse('2026-06-01')))->toHaveCount(1);
});

it('grants when the repurchase cycle is failed — only the BV of the month counts', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-05-01');
    aogoMonthlyBv($dist->id, '2026-06-10');

    RepurchaseCycle::create([
        'distributor_id' => $dist->id,
        'cycle_start_date' => '2026-05-05',
        'due_date' => '2026-06-03',
        'required_bv_paise' => 100_000,
        'completed_bv_paise' => 0,
        'status' => RepurchaseCycle::STATUS_SUSPENDED,
    ]);

    expect(app(AogoOfferService::class)->grantForMonth(Carbon::parse('2026-06-01')))->toHaveCount(1);
});

it('reports every AO-GO condition met in the month after a rank achievement', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-05-01');
    aogoMonthlyBv($dist->id, '2026-06-10');

    $status = app(AogoOfferService::class)->eligibilityFor((int) $dist->id, Carbon::parse('2026-06-15'));

    expect($status->everAchievedRank)->toBeTrue()
        ->and($status->usesUsed)->toBe(0)
        ->and($status->usesMax)->toBe(3)
        ->and($status->pointsPerGrant)->toBe(36)
        ->and($status->granted())->toBeFalse()
        ->and($status->conditionsMet)->toBeTrue()
        // Rank last month, no rank this month, uses left, the month's own BV.
        ->and($status->conditions)->toHaveCount(4)
        ->and($status->conditions[3]->label)->toContain('1,000 BV');
});

it('names the unmet AO-GO condition — no rank achieved last month', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-04-01');
    aogoMonthlyBv($dist->id, '2026-06-10');

    $status = app(AogoOfferService::class)->eligibilityFor((int) $dist->id, Carbon::parse('2026-06-15'));

    expect($status->conditionsMet)->toBeFalse()
        ->and($status->conditions)->toHaveCount(3)
        ->and(array_map(fn ($c) => $c->label, $status->unmetConditions()))
        ->toBe(['A rank achieved last month']);
});

it('shows the grant once the monthly run has created it', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-05-01');
    aogoMonthlyBv($dist->id, '2026-06-10');

    $svc = app(AogoOfferService::class);
    $svc->grantForMonth(Carbon::parse('2026-06-01'));

    $status = $svc->eligibilityFor((int) $dist->id, Carbon::parse('2026-06-15'));

    expect($status->granted())->toBeTrue()
        ->and($status->grantedPoints)->toBe(36)
        ->and($status->grantedStatus)->toBe(RankAogoGrant::STATUS_GRANTED)
        ->and($status->usesUsed)->toBe(1);
});

it('previews exactly what the monthly run decides, across every blocking rule', function (): void {
    // One distributor per rule, all evaluated for the same month: whatever
    // eligibilityFor() reports must match what grantForMonth() actually does.
    $eligible = Distributor::factory()->create();
    aogoQualify($eligible->id, 1, '2026-05-01');
    aogoMonthlyBv($eligible->id, '2026-06-10');

    $rankedNow = Distributor::factory()->create();
    aogoQualify($rankedNow->id, 1, '2026-05-01');
    aogoQualify($rankedNow->id, 1, '2026-06-01');
    aogoMonthlyBv($rankedNow->id, '2026-06-10');

    $noBv = Distributor::factory()->create();
    aogoQualify($noBv->id, 1, '2026-05-01');

    $shortForRank = Distributor::factory()->create();
    aogoQualify($shortForRank->id, 3, '2026-05-01');
    aogoMonthlyBv($shortForRank->id, '2026-06-10', 100_000);

    $capped = Distributor::factory()->create();
    aogoPastGrant($capped->id, '2026-01-01', 1);
    aogoPastGrant($capped->id, '2026-02-01', 2);
    aogoPastGrant($capped->id, '2026-03-01', 3);
    aogoQualify($capped->id, 1, '2026-05-01');
    aogoMonthlyBv($capped->id, '2026-06-10');

    $tooOld = Distributor::factory()->create();
    aogoQualify($tooOld->id, 1, '2026-04-01');
    aogoMonthlyBv($tooOld->id, '2026-06-10');

    $svc = app(AogoOfferService::class);
    $month = Carbon::parse('2026-06-01');

    $previewed = collect([$eligible, $rankedNow, $noBv, $shortForRank, $capped, $tooOld])
        ->mapWithKeys(fn (Distributor $d): array => [
            $d->id => $svc->eligibilityFor((int) $d->id, $month)->conditionsMet,
        ]);

    $svc->grantForMonth($month);

    $granted = RankAogoGrant::where('month_start', '2026-06-01')
        ->pluck('distributor_id')
        ->map(fn ($id): int => (int) $id)
        ->flip();

    foreach ($previewed as $distributorId => $conditionsMet) {
        expect($conditionsMet)->toBe($granted->has($distributorId));
    }

    expect($previewed->filter()->keys()->all())->toBe([$eligible->id]);
});

/** A repurchase-wallet movement in the ledger. */
function aogoRepurchaseWalletCredit(int $distributorId, int $amountPaise, string $createdAt, string $type = 'repurchase_deduction'): void
{
    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'type' => $type,
        'amount_paise' => $type === 'repurchase_wallet_used' ? -abs($amountPaise) : abs($amountPaise),
        'reference_id' => null,
        'reference_type' => null,
        'memo' => 'test',
        'created_at' => $createdAt,
    ]);
}
