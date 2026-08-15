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

/** 1,000 BV personal purchase inside the month — the AO-GO monthly condition. */
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

it('grants AO-GO points to a degraded ex-rank-holder meeting the monthly conditions', function (): void {
    $dist = Distributor::factory()->create();
    // Held ranks 1 and 3 historically; unranked in June.
    aogoQualify($dist->id, 1, '2026-03-01');
    aogoQualify($dist->id, 3, '2026-04-01');
    aogoMonthlyBv($dist->id, '2026-06-10');

    $grants = app(AogoOfferService::class)->grantForMonth(Carbon::parse('2026-06-01'));

    expect($grants)->toHaveCount(1);
    $grant = $grants->first();
    expect($grant->distributor_id)->toBe($dist->id)
        ->and($grant->grant_number)->toBe(1)
        ->and($grant->points)->toBe(5)
        ->and($grant->previous_rank_number)->toBe(3) // highest rank ever held
        ->and($grant->status)->toBe(RankAogoGrant::STATUS_GRANTED);
});

it('does not grant to a distributor who is ranked this month', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-04-01');
    aogoQualify($dist->id, 1, '2026-06-01'); // still ranked in June
    aogoMonthlyBv($dist->id, '2026-06-10');

    $grants = app(AogoOfferService::class)->grantForMonth(Carbon::parse('2026-06-01'));

    expect($grants)->toHaveCount(0);
});

it('withholds the grant when the monthly BV condition fails — and the use is NOT consumed', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-04-01');
    // No June personal BV → no June grant.

    $svc = app(AogoOfferService::class);
    expect($svc->grantForMonth(Carbon::parse('2026-06-01')))->toHaveCount(0);

    // July meets the condition → grant #1 (nothing was consumed in June).
    aogoMonthlyBv($dist->id, '2026-07-08');
    $grants = $svc->grantForMonth(Carbon::parse('2026-07-01'));

    expect($grants)->toHaveCount(1);
    expect($grants->first()->grant_number)->toBe(1);
});

it('never grants in two consecutive months', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-04-01');
    aogoPastGrant($dist->id, '2026-05-01', 1);
    aogoMonthlyBv($dist->id, '2026-06-10');

    $grants = app(AogoOfferService::class)->grantForMonth(Carbon::parse('2026-06-01'));

    expect($grants)->toHaveCount(0);
});

it('requires a rank re-achieved between uses', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-03-01');
    aogoPastGrant($dist->id, '2026-04-01', 1);
    aogoMonthlyBv($dist->id, '2026-06-10');

    // June is non-consecutive with April, but no rank was achieved after the
    // April grant → blocked.
    $svc = app(AogoOfferService::class);
    expect($svc->grantForMonth(Carbon::parse('2026-06-01')))->toHaveCount(0);

    // Re-achieve in June → degraded again in July → grant #2.
    aogoQualify($dist->id, 1, '2026-06-01');
    aogoMonthlyBv($dist->id, '2026-07-08');
    $grants = $svc->grantForMonth(Carbon::parse('2026-07-01'));

    expect($grants)->toHaveCount(1);
    expect($grants->first()->grant_number)->toBe(2);
});

it('caps AO-GO at the lifetime maximum of 3 uses', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-01-01');
    aogoPastGrant($dist->id, '2026-02-01', 1);
    aogoPastGrant($dist->id, '2026-04-01', 2);
    aogoPastGrant($dist->id, '2026-05-01', 3);
    // Rank re-achieved after the last grant, non-consecutive month, BV met —
    // the lifetime cap is the only blocker.
    aogoQualify($dist->id, 1, '2026-06-01');
    aogoMonthlyBv($dist->id, '2026-07-08');

    $grants = app(AogoOfferService::class)->grantForMonth(Carbon::parse('2026-07-01'));

    expect($grants)->toHaveCount(0);
});

it('is idempotent — a rerun returns the existing grant without duplicating it', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-04-01');
    aogoMonthlyBv($dist->id, '2026-06-10');

    $svc = app(AogoOfferService::class);
    $svc->grantForMonth(Carbon::parse('2026-06-01'));
    $grants = $svc->grantForMonth(Carbon::parse('2026-06-01'));

    expect($grants)->toHaveCount(1);
    expect(RankAogoGrant::count())->toBe(1);
});

it('reports every AO-GO condition met for a degraded ex-rank-holder', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-04-01');
    aogoMonthlyBv($dist->id, '2026-06-10');

    $status = app(AogoOfferService::class)->eligibilityFor((int) $dist->id, Carbon::parse('2026-06-15'));

    expect($status->everAchievedRank)->toBeTrue()
        ->and($status->usesUsed)->toBe(0)
        ->and($status->usesMax)->toBe(3)
        ->and($status->usesLeft())->toBe(3)
        ->and($status->pointsPerGrant)->toBe(5)
        ->and($status->granted())->toBeFalse()
        ->and($status->conditionsMet)->toBeTrue()
        // Three standing rules plus the month's requalification condition; the
        // consecutive-month and re-achieve rules only appear after a use.
        ->and($status->conditions)->toHaveCount(4);
});

it('names the unmet AO-GO condition — ranked this month, or no rank re-achieved since the last use', function (): void {
    $ranked = Distributor::factory()->create();
    aogoQualify($ranked->id, 1, '2026-04-01');
    aogoQualify($ranked->id, 1, '2026-06-01');
    aogoMonthlyBv($ranked->id, '2026-06-10');

    $status = app(AogoOfferService::class)->eligibilityFor((int) $ranked->id, Carbon::parse('2026-06-15'));
    $unmet = array_map(fn ($c) => $c->label, $status->unmetConditions());

    expect($status->conditionsMet)->toBeFalse()
        ->and($unmet)->toBe(['No rank held this month']);

    // Used once in April, no rank achieved since → that rule is the blocker.
    $used = Distributor::factory()->create();
    aogoQualify($used->id, 1, '2026-03-01');
    aogoPastGrant($used->id, '2026-04-01', 1);
    aogoMonthlyBv($used->id, '2026-06-10');

    $usedStatus = app(AogoOfferService::class)->eligibilityFor((int) $used->id, Carbon::parse('2026-06-15'));

    expect($usedStatus->usesUsed)->toBe(1)
        ->and($usedStatus->usesLeft())->toBe(2)
        ->and($usedStatus->conditions)->toHaveCount(6)
        ->and(array_map(fn ($c) => $c->label, $usedStatus->unmetConditions()))
        ->toBe(['A rank re-achieved since your last use']);
});

it('shows the grant once the monthly run has created it', function (): void {
    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-04-01');
    aogoMonthlyBv($dist->id, '2026-06-10');

    $svc = app(AogoOfferService::class);
    $svc->grantForMonth(Carbon::parse('2026-06-01'));

    $status = $svc->eligibilityFor((int) $dist->id, Carbon::parse('2026-06-15'));

    expect($status->granted())->toBeTrue()
        ->and($status->grantedPoints)->toBe(5)
        ->and($status->grantedStatus)->toBe(RankAogoGrant::STATUS_GRANTED)
        ->and($status->usesUsed)->toBe(1);
});

it('previews exactly what the monthly run decides, across every blocking rule', function (): void {
    // One distributor per rule, all evaluated for the same month: whatever
    // eligibilityFor() reports must match what grantForMonth() actually does.
    // This is the guard against the checklist and the payout drifting apart.
    $eligible = Distributor::factory()->create();
    aogoQualify($eligible->id, 1, '2026-04-01');
    aogoMonthlyBv($eligible->id, '2026-06-10');

    $rankedNow = Distributor::factory()->create();
    aogoQualify($rankedNow->id, 1, '2026-04-01');
    aogoQualify($rankedNow->id, 1, '2026-06-01');
    aogoMonthlyBv($rankedNow->id, '2026-06-10');

    $noBv = Distributor::factory()->create();
    aogoQualify($noBv->id, 1, '2026-04-01');

    $capped = Distributor::factory()->create();
    aogoPastGrant($capped->id, '2026-01-01', 1);
    aogoPastGrant($capped->id, '2026-02-01', 2);
    aogoPastGrant($capped->id, '2026-03-01', 3);
    aogoQualify($capped->id, 1, '2026-04-01');
    aogoMonthlyBv($capped->id, '2026-06-10');

    $consecutive = Distributor::factory()->create();
    aogoQualify($consecutive->id, 1, '2026-04-01');
    aogoPastGrant($consecutive->id, '2026-05-01', 1);
    aogoMonthlyBv($consecutive->id, '2026-06-10');

    $notReAchieved = Distributor::factory()->create();
    aogoQualify($notReAchieved->id, 1, '2026-03-01');
    aogoPastGrant($notReAchieved->id, '2026-04-01', 1);
    aogoMonthlyBv($notReAchieved->id, '2026-06-10');

    $svc = app(AogoOfferService::class);
    $month = Carbon::parse('2026-06-01');

    $previewed = collect([$eligible, $rankedNow, $noBv, $capped, $consecutive, $notReAchieved])
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

    // Sanity: the fixture actually exercises both outcomes.
    expect($previewed->filter()->keys()->all())->toBe([$eligible->id]);
});

it('blocks the grant while the repurchase wallet is not cleared (engine on, cycle suspended)', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create();
    aogoQualify($dist->id, 1, '2026-04-01');
    aogoMonthlyBv($dist->id, '2026-06-10');

    RepurchaseCycle::create([
        'distributor_id' => $dist->id,
        'cycle_start_date' => '2026-05-05',
        'due_date' => '2026-06-04',
        'grace_end_date' => '2026-06-11',
        'required_bv_paise' => 100_000,
        'completed_bv_paise' => 0,
        'status' => RepurchaseCycle::STATUS_SUSPENDED,
    ]);

    $grants = app(AogoOfferService::class)->grantForMonth(Carbon::parse('2026-06-01'));

    expect($grants)->toHaveCount(0);
});
