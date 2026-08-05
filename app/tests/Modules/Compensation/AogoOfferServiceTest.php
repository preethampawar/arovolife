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
