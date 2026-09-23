<?php

declare(strict_types=1);

/**
 * The dashboard's live Fortune Bonus read. FortuneBonusService::dashboardCardFor()
 * runs this month through the same builders and the same evaluateGates() the
 * month-end enrolment uses, so FQ-06 pins the two together: whoever the engine
 * would enrol is exactly whoever the card calls qualified.
 */

use App\Modules\Compensation\Models\FortuneBonusParticipant;
use App\Modules\Compensation\Models\FortuneBonusResult;
use App\Modules\Compensation\Services\DTOs\FortuneDashboardCard;
use App\Modules\Compensation\Services\FortuneBonusService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function fqDistributor(string $effectiveDate): Distributor
{
    $distributor = Distributor::factory()->create();
    $distributor->forceFill(['effective_date' => $effectiveDate.' 10:00:00'])->save();

    return $distributor;
}

function fqGsbCredit(int $distributorId, string $date, int $slab = 1): void
{
    DB::table('gsb_cutoff_results')->insert([
        'distributor_id' => $distributorId,
        'cutoff_date' => $date,
        'left_bv_paise' => 30_000_000,
        'right_bv_paise' => 30_000_000,
        'weaker_bv_paise' => 30_000_000,
        'slab' => $slab,
        'gross_gsb_paise' => 100_000,
        'admin_charge_paise' => 3_000,
        'tds_paise' => 5_000,
        'net_gsb_paise' => 92_000,
        'power_cf_before_paise' => 0,
        'power_cf_after_paise' => 0,
        'power_side_after' => null,
        'slab1_weaker_cf_before_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'status' => 'credited',
        'failure_reason' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function fqPersonalBv(int $distributorId, int $bvPaise, string $date = '2026-06-10'): void
{
    static $fakeOrderId = 810000;
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'order_id' => $fakeOrderId++,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => $date.' 12:00:00',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function fqRank(int $distributorId, int $rank, string $monthStart = '2026-06-01'): void
{
    DB::table('rank_qualifications')->insert([
        'distributor_id' => $distributorId,
        'rank_number' => $rank,
        'month_start' => $monthStart,
        'occurrence_in_month' => 1,
        'is_carry_forward' => false,
        'carry_forward_from_month' => null,
        'status' => 'qualified',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

function fqResult(int $distributorId, string $monthStart, string $status, int $netPaise): FortuneBonusResult
{
    return FortuneBonusResult::create([
        'distributor_id' => $distributorId,
        'month_start' => $monthStart,
        'position' => 1,
        'matrix_level' => 0,
        'points' => 27,
        'point_value_paise' => 100,
        'min_commission_paise' => 3_000,
        'cap_paise' => 3_000_000,
        'gross_paise' => $netPaise,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'repurchase_deduction_paise' => 0,
        'net_paise' => $netPaise,
        'status' => $status,
        'credited_at' => $status === FortuneBonusResult::STATUS_CREDITED ? now() : null,
    ]);
}

function fqCard(int $distributorId): FortuneDashboardCard
{
    return app(FortuneBonusService::class)->dashboardCardFor($distributorId, Carbon::parse('2026-06-15', 'Asia/Kolkata'));
}

it('FQ-01: qualifies a new joiner with 3,000 BV and one slab-1 GSB credit', function (): void {
    $joiner = fqDistributor('2026-06-02');
    fqPersonalBv($joiner->id, 300_000);
    fqGsbCredit($joiner->id, '2026-06-05', 1);

    $card = fqCard($joiner->id);

    expect($card->month->toDateString())->toBe('2026-06-01')
        ->and($card->thisMonth->tier)->toBe(FortuneBonusService::TIER_NEW_JOINER)
        ->and($card->thisMonth->hasGsbIncome)->toBeTrue()
        ->and($card->thisMonth->slabCount)->toBe(1)
        ->and($card->thisMonth->holdsTitle)->toBeNull()
        ->and($card->thisMonth->qualified)->toBeTrue();
});

it('FQ-02: reports personal BV and does not qualify below the BV requirement', function (): void {
    $joiner = fqDistributor('2026-06-02');
    fqPersonalBv($joiner->id, 200_000);
    fqGsbCredit($joiner->id, '2026-06-05', 1);

    $q = fqCard($joiner->id)->thisMonth;

    expect($q->personalBvPaise)->toBe(200_000)
        ->and($q->bvRequiredPaise)->toBe(300_000)
        ->and($q->hasGsbIncome)->toBeTrue()
        ->and($q->qualified)->toBeFalse();
});

it('FQ-03: flags an ineligible rank and never qualifies it', function (): void {
    $ranked = fqDistributor('2026-01-02');
    fqPersonalBv($ranked->id, 500_000);
    foreach (range(1, 20) as $day) {
        fqGsbCredit($ranked->id, sprintf('2026-06-%02d', $day), 3);
    }
    fqRank($ranked->id, 6);

    $q = fqCard($ranked->id)->thisMonth;

    expect($q->rankIneligible)->toBeTrue()
        ->and($q->qualified)->toBeFalse();
});

it('FQ-04: a non-ranked distributor without a title reports holdsTitle false and does not qualify', function (): void {
    $untitled = fqDistributor('2026-05-02');
    fqPersonalBv($untitled->id, 60_000);
    fqGsbCredit($untitled->id, '2026-06-05');

    $q = fqCard($untitled->id)->thisMonth;

    expect($q->tier)->toBe(FortuneBonusService::TIER_NON_RANKED)
        ->and($q->personalBvPaise)->toBeGreaterThanOrEqual($q->bvRequiredPaise)
        ->and($q->slabCount)->toBeGreaterThanOrEqual($q->slabsRequired)
        ->and($q->holdsTitle)->toBeFalse()
        ->and($q->qualified)->toBeFalse();
});

it('FQ-05: returns only the distributor\'s own last-month result', function (): void {
    $own = fqDistributor('2026-01-02');
    $other = fqDistributor('2026-01-02');
    $absent = fqDistributor('2026-01-02');

    fqResult($own->id, '2026-05-01', FortuneBonusResult::STATUS_CREDITED, 12_345);
    fqResult($other->id, '2026-05-01', FortuneBonusResult::STATUS_CREDITED, 99_999);

    $card = fqCard($own->id);

    expect($card->lastMonth)->not->toBeNull()
        ->and((int) $card->lastMonth->distributor_id)->toBe($own->id)
        ->and($card->lastMonth->net_paise)->toBe(12_345)
        ->and($card->lastMonthEntry)->toBeNull();

    $absentCard = fqCard($absent->id);

    expect($absentCard->lastMonth)->toBeNull()
        ->and($absentCard->lastMonthEntry)->toBeNull();
});

it('FQ-05b: falls back to the enrolment row while last month\'s result is not written', function (): void {
    $entered = fqDistributor('2026-01-02');

    FortuneBonusParticipant::create([
        'distributor_id' => $entered->id,
        'month_start' => '2026-05-01',
        'position' => 3,
        'matrix_level' => 1,
        'eligibility_tier' => FortuneBonusService::TIER_NON_RANKED,
        'first_gsb_date' => '2026-05-05',
        'enrolled_at' => now(),
    ]);

    $card = fqCard($entered->id);

    expect($card->lastMonth)->toBeNull()
        ->and($card->lastMonthEntry)->not->toBeNull()
        ->and($card->lastMonthEntry->matrix_level)->toBe(1);
});

it('FQ-06: the card qualifies exactly the distributors enrollEligible() enrols', function (): void {
    $distributors = [];

    // New joiner, slab 1, 3,000 BV — enrols.
    $d = fqDistributor('2026-06-02');
    fqPersonalBv($d->id, 300_000);
    fqGsbCredit($d->id, '2026-06-03', 1);
    $distributors[] = $d->id;

    // New joiner on slab 2 only — does not.
    $d = fqDistributor('2026-06-02');
    fqPersonalBv($d->id, 300_000);
    fqGsbCredit($d->id, '2026-06-03', 2);
    $distributors[] = $d->id;

    // Titled non-ranked with 600 BV and a slab — enrols.
    $d = fqDistributor('2026-03-02');
    fqPersonalBv($d->id, 300_000, '2026-03-10');
    fqPersonalBv($d->id, 60_000);
    fqGsbCredit($d->id, '2026-06-04');
    $distributors[] = $d->id;

    // Untitled non-ranked — does not.
    $d = fqDistributor('2026-03-02');
    fqPersonalBv($d->id, 60_000);
    fqGsbCredit($d->id, '2026-06-04');
    $distributors[] = $d->id;

    // Titled non-ranked below 600 BV — does not.
    $d = fqDistributor('2026-03-02');
    fqPersonalBv($d->id, 300_000, '2026-03-10');
    fqPersonalBv($d->id, 50_000);
    fqGsbCredit($d->id, '2026-06-04');
    $distributors[] = $d->id;

    // Rank 1 with 7 slabs (needs 8) — does not.
    $d = fqDistributor('2026-01-02');
    fqPersonalBv($d->id, 100_000);
    foreach (range(1, 7) as $day) {
        fqGsbCredit($d->id, sprintf('2026-06-%02d', $day), 1);
    }
    fqRank($d->id, 1);
    $distributors[] = $d->id;

    // Rank 1 with 8 slabs — enrols.
    $d = fqDistributor('2026-01-02');
    fqPersonalBv($d->id, 100_000);
    foreach (range(1, 8) as $day) {
        fqGsbCredit($d->id, sprintf('2026-06-%02d', $day), 1);
    }
    fqRank($d->id, 1);
    $distributors[] = $d->id;

    // Rank 6 — ineligible.
    $d = fqDistributor('2026-01-02');
    fqPersonalBv($d->id, 500_000);
    foreach (range(1, 20) as $day) {
        fqGsbCredit($d->id, sprintf('2026-06-%02d', $day), 3);
    }
    fqRank($d->id, 6);
    $distributors[] = $d->id;

    // Titled, BV met, but no GSB income — not in the engine's population.
    $d = fqDistributor('2026-03-02');
    fqPersonalBv($d->id, 300_000, '2026-03-10');
    fqPersonalBv($d->id, 60_000);
    $distributors[] = $d->id;

    $qualifiedOnCard = array_values(array_filter(
        $distributors,
        fn (int $id): bool => fqCard($id)->thisMonth->qualified,
    ));

    app(FortuneBonusService::class)->enrollEligible(Carbon::parse('2026-06-01'));

    $enrolled = FortuneBonusParticipant::where('month_start', '2026-06-01')
        ->orderBy('distributor_id')
        ->pluck('distributor_id')
        ->map(fn ($id): int => (int) $id)
        ->all();

    sort($qualifiedOnCard);

    expect($enrolled)->toHaveCount(3)
        ->and($qualifiedOnCard)->toBe($enrolled);
});
