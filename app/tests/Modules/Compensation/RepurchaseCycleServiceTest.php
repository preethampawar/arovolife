<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Events\IncomeSuspended;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\GsbCutoffService;
use App\Modules\Compensation\Services\IncomeEligibilityService;
use App\Modules\Compensation\Services\RepurchaseCycleService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    seedCompensationPlanTables(); // gsb_slabs (Retailer = 300,000 paise) + rank_tiers (repurchase BV)
});

/** Record a self-consumption purchase: an order + its BV ledger accrual. */
function seedSelfPurchase(int $distributorId, int $bvPaise, string $date): void
{
    $orderId = DB::table('orders')->insertGetId([
        'order_no' => 'O'.uniqid('', true),
        'customer_id' => 1,
        'attributed_distributor_id' => $distributorId,
        'self_consumption' => true,
        'idempotency_key' => 'k'.uniqid('', true),
        'created_at' => $date,
        'updated_at' => $date,
    ]);

    BvLedgerEntry::create([
        'distributor_id' => $distributorId,
        'order_id' => $orderId,
        'bv_paise' => $bvPaise,
        'type' => BvLedgerEntry::TYPE_ACCRUAL,
        'effective_at' => $date,
    ]);
}

function svc(): RepurchaseCycleService
{
    return app(RepurchaseCycleService::class);
}

it('has no obligation until the distributor reaches 600 BV personal', function (): void {
    // 5 Jul 2026 rule: the repurchase anchor is the 600-BV first-purchase point,
    // not the 3,000-BV Retailer title. Below 600 BV there is no obligation.
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 50_000, '2026-01-05'); // 500 BV < 600

    expect(svc()->evaluate($dist->id, Carbon::parse('2026-02-20')))->toBeNull();
});

it('opens the repurchase cycle from 600 BV personal, before the Retailer title', function (): void {
    // A distributor with 600–2,999 BV (below Retailer) now has a repurchase
    // obligation anchored to the day they first crossed 600 BV.
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 60_000, '2026-01-05'); // exactly 600 BV, still below Retailer

    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-02-20'));

    expect($cycle)->not->toBeNull();
    expect($cycle->cycle_start_date->toDateString())->toBe('2026-02-05'); // anchored to day 5
    expect($cycle->required_bv_paise)->toBe(60_000); // non-ranked 600 BV obligation
});

it('is active within a fresh cycle with the obligation unmet', function (): void {
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-05'); // Retailer, anchor day 5

    // Feb cycle (5th–4th Mar); no repurchase yet, before due.
    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-02-20'));

    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_ACTIVE);
    expect($cycle->required_bv_paise)->toBe(60_000); // non-ranked default 600 BV
    expect($cycle->cycle_start_date->toDateString())->toBe('2026-02-05');
});

it('completes the cycle once the required repurchase BV is reached', function (): void {
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');
    seedSelfPurchase($dist->id, 60_000, '2026-02-10'); // 600 BV repurchase in the Feb cycle

    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-02-20'));

    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_COMPLETED);
    expect($cycle->completed_at)->not->toBeNull();
});

it('immediately suspends past the due date and emits the event', function (): void {
    Event::fake([IncomeSuspended::class]);
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');

    // Feb cycle due 2026-03-04; grace_days=0 so grace_end_date == due_date.
    // Evaluating on the 6th (past due) immediately suspends — no grace window.
    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-03-06'));

    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_SUSPENDED);
    Event::assertDispatched(IncomeSuspended::class);
});

it('suspends after grace lapses and emits the event', function (): void {
    Event::fake([IncomeSuspended::class]);
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');

    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-03-20')); // past due_date 03-04; grace_days=0

    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_SUSPENDED);
    Event::assertDispatched(IncomeSuspended::class);
});

it('resolves to SUSPENDED the day after due date when grace_days is zero', function (): void {
    // Unit regression: grace_days=0 means grace_end_date == due_date.
    // Any date strictly after the due date must resolve to SUSPENDED, not GRACE.
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-05'); // anchor day 5; due 2026-03-04

    // 2026-03-05 is exactly one day after due_date; grace_end_date is also 03-04.
    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-03-05'));

    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_SUSPENDED);
});

it('reactivates a suspended distributor once they complete the repurchase', function (): void {
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');

    expect(svc()->evaluate($dist->id, Carbon::parse('2026-03-20'))->status)
        ->toBe(RepurchaseCycle::STATUS_SUSPENDED);

    Event::fake([IncomeReactivated::class]);
    seedSelfPurchase($dist->id, 60_000, '2026-03-22'); // completes the obligation
    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-03-25'));

    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_COMPLETED);
    Event::assertDispatched(IncomeReactivated::class);
});

it('handles a month-end anchor (Retailer on the 31st) without window errors', function (): void {
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-31'); // Retailer anchored on the 31st

    // Feb has no 31st: the first cycle (Jan 31 → Feb 27) and the next must roll
    // cleanly. Evaluate a few months out; no exception, valid window, suspended
    // (no repurchase made).
    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-04-15'));

    expect($cycle)->not->toBeNull();
    expect($cycle->due_date->greaterThan($cycle->cycle_start_date))->toBeTrue();
    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_SUSPENDED);
});

it('reads the per-rank repurchase BV from config, not a constant', function (): void {
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');
    // Make them Rank 1 → obligation jumps to rank_tiers.repurchase_bv (1,000 BV).
    RankQualification::create([
        'distributor_id' => $dist->id, 'rank_number' => 1, 'month_start' => '2026-02-01',
        'occurrence_in_month' => 1, 'is_carry_forward' => false, 'status' => RankQualification::STATUS_QUALIFIED,
    ]);

    // 600 BV repurchase is no longer enough for a Rank-1 (needs 1,000 BV).
    seedSelfPurchase($dist->id, 60_000, '2026-02-10');
    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-02-20'));

    expect($cycle->required_bv_paise)->toBe(100_000); // 1,000 BV, from rank_tiers
    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_ACTIVE); // 600 < 1,000
});

it('suspends only GSB, Fortune and Growth Booster — never Mentorship or Rank', function (): void {
    $svc = app(IncomeEligibilityService::class);

    expect($svc->suspends(BonusType::Gsb))->toBeTrue();
    expect($svc->suspends(BonusType::Fortune))->toBeTrue();
    expect($svc->suspends(BonusType::GrowthBooster))->toBeTrue();
    expect($svc->suspends(BonusType::Mentorship))->toBeFalse();
    expect($svc->suspends(BonusType::Rank))->toBeFalse();
});

// ── GSB cut-off gate ────────────────────────────────────────────────────────

/** A Retailer (anchor 2026-01-05, never repurchased) whose group BV on $date
 *  matches GSB slab 1 (weaker side ≥ 15,000 BV). */
function makeGsbReadyRetailer(string $date): Distributor
{
    $dist = Distributor::factory()->create(['status' => 'active', 'adn' => '100000900']);
    seedSelfPurchase($dist->id, 300_000, '2026-01-05'); // Retailer; no later repurchase
    GroupBvDaily::create([
        'distributor_id' => $dist->id, 'date' => $date,
        'left_bv_paise' => 2_000_000, 'right_bv_paise' => 1_600_000, // weaker 16,000 BV ≥ slab 1
    ]);

    // The gate is read-only — the daily command (here, a direct evaluate) is the
    // sole writer that establishes the cycle status the cut-off then reads.
    svc()->evaluate($dist->id, Carbon::parse($date));

    return $dist;
}

it('credits GSB normally when the repurchase engine is OFF, even past due date', function (): void {
    $dist = makeGsbReadyRetailer('2026-03-20'); // would be suspended if engine were on

    $result = app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::parse('2026-03-20'));

    expect($result->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
});

it('suspends the GSB credit when the engine is ON and the cycle is past due date', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = makeGsbReadyRetailer('2026-03-20'); // > due_date 2026-03-04; grace_days=0

    $result = app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::parse('2026-03-20'));

    expect($result->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED);
    expect($result->gross_gsb_paise)->toBeGreaterThan(0);          // calculated…
    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(0); // …but not credited
});

it('suspends the GSB credit immediately the day after the due date', function (): void {
    // grace_days=0: grace_end_date == due_date, so any day past due_date is suspended.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = makeGsbReadyRetailer('2026-03-06'); // due 03-04; grace_days=0, so suspended on 03-06

    $result = app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::parse('2026-03-06'));

    expect($result->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED);
    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(0);
});

it('keeps suspended GSB rows forfeited after repurchase completion (grace_days=0)', function (): void {
    // grace_days=0: no grace window; any day past the due date is immediately SUSPENDED.
    // Completing repurchase later reactivates the cycle but cannot release SUSPENDED rows
    // (only HELD rows are released, and HELD only arises when grace_days > 0).
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create(['status' => 'active', 'adn' => '100000901']);
    seedSelfPurchase($dist->id, 300_000, '2026-01-05'); // anchor day 5; due 03-04, grace_days=0

    // A slab-1 match on each of two days, both past the due date.
    foreach (['2026-03-06', '2026-03-15'] as $date) {
        GroupBvDaily::create([
            'distributor_id' => $dist->id, 'date' => $date,
            'left_bv_paise' => 2_000_000, 'right_bv_paise' => 1_600_000, // weaker 16,000 BV ≥ slab 1
        ]);
    }

    $gsb = app(GsbCutoffService::class);

    // Both days past due → SUSPENDED immediately (no grace hold).
    svc()->evaluate($dist->id, Carbon::parse('2026-03-06'));
    $row1 = $gsb->runForDistributor($dist->id, Carbon::parse('2026-03-06'));
    expect($row1->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED);

    svc()->evaluate($dist->id, Carbon::parse('2026-03-15'));
    $row2 = $gsb->runForDistributor($dist->id, Carbon::parse('2026-03-15'));
    expect($row2->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED);
    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(0);

    // Complete the repurchase → cycle reactivates, but no HELD rows exist to release.
    seedSelfPurchase($dist->id, 60_000, '2026-03-20'); // 600 BV, meets the obligation
    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-03-20'));
    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_COMPLETED);

    // Both suspended rows remain forfeited; wallet stays empty.
    expect($row1->fresh()->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED);
    expect($row2->fresh()->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED);
    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(0);
});

it('release listener is idempotent — a second reactivation does not credit when no held rows exist', function (): void {
    // grace_days=0: the GSB row on 2026-03-06 is SUSPENDED, not HELD.
    // Firing IncomeReactivated twice must not throw and must not credit anything.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create(['status' => 'active', 'adn' => '100000902']);
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');
    GroupBvDaily::create([
        'distributor_id' => $dist->id, 'date' => '2026-03-06',
        'left_bv_paise' => 2_000_000, 'right_bv_paise' => 1_600_000,
    ]);

    svc()->evaluate($dist->id, Carbon::parse('2026-03-06')); // → SUSPENDED
    app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::parse('2026-03-06')); // → REPURCHASE_SUSPENDED

    // Fire the reactivation twice directly; no held rows to release → wallet stays 0.
    event(new IncomeReactivated($dist->id, 1));
    event(new IncomeReactivated($dist->id, 1));

    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(0); // nothing to release
});
