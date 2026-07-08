<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Events\IncomeSuspended;
use App\Modules\Compensation\Events\RepurchaseGraceStarted;
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

it('enters grace past the due date and emits the event', function (): void {
    Event::fake([RepurchaseGraceStarted::class]);
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');

    // Feb cycle due 2026-03-04, grace to 2026-03-11; evaluate on the 6th, unmet.
    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-03-06'));

    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_GRACE);
    Event::assertDispatched(RepurchaseGraceStarted::class);
});

it('suspends after grace lapses and emits the event', function (): void {
    Event::fake([IncomeSuspended::class]);
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');

    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-03-20')); // past grace_end 03-11

    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_SUSPENDED);
    Event::assertDispatched(IncomeSuspended::class);
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

it('credits GSB normally when the repurchase engine is OFF, even past grace', function (): void {
    $dist = makeGsbReadyRetailer('2026-03-20'); // would be suspended if engine were on

    $result = app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::parse('2026-03-20'));

    expect($result->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
});

it('suspends the GSB credit when the engine is ON and the cycle is past grace', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = makeGsbReadyRetailer('2026-03-20'); // > grace_end 2026-03-11

    $result = app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::parse('2026-03-20'));

    expect($result->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED);
    expect($result->gross_gsb_paise)->toBeGreaterThan(0);          // calculated…
    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(0); // …but not credited
});

it('holds the GSB credit during the repurchase grace window', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = makeGsbReadyRetailer('2026-03-06'); // due 03-04, grace to 03-11

    $result = app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::parse('2026-03-06'));

    expect($result->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_HELD);
    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(0);
});

it('releases grace-held GSB on repurchase completion but keeps suspended GSB forfeited', function (): void {
    // KP 2026-06-28 final: income HELD during the 7-day grace is released to the
    // wallet once the distributor completes their repurchase; income SUSPENDED
    // after grace is forfeited for that lapsed period and is never released.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create(['status' => 'active', 'adn' => '100000901']);
    seedSelfPurchase($dist->id, 300_000, '2026-01-05'); // anchor day 5; due 03-04, grace to 03-11

    // A slab-1 match on each of two days: one in grace, one after grace.
    foreach (['2026-03-06' => 'grace', '2026-03-15' => 'suspended'] as $date => $_phase) {
        GroupBvDaily::create([
            'distributor_id' => $dist->id, 'date' => $date,
            'left_bv_paise' => 2_000_000, 'right_bv_paise' => 1_600_000, // weaker 16,000 BV ≥ slab 1
        ]);
    }

    $gsb = app(GsbCutoffService::class);

    // Day in grace → HELD (calculated, not credited).
    svc()->evaluate($dist->id, Carbon::parse('2026-03-06'));
    $held = $gsb->runForDistributor($dist->id, Carbon::parse('2026-03-06'));
    expect($held->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_HELD);

    // Day past grace → SUSPENDED (forfeited).
    svc()->evaluate($dist->id, Carbon::parse('2026-03-15'));
    $suspended = $gsb->runForDistributor($dist->id, Carbon::parse('2026-03-15'));
    expect($suspended->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED);
    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(0); // nothing credited yet

    // Complete the repurchase → IncomeReactivated → listener releases HELD only.
    seedSelfPurchase($dist->id, 60_000, '2026-03-20'); // 600 BV, meets the obligation
    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-03-20'));
    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_COMPLETED);

    // Grace-held row released and credited; suspended row still forfeited.
    expect($held->fresh()->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
    expect($suspended->fresh()->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_SUSPENDED);
    // Wallet holds exactly the one released slab-1 amount (₹1,800), not both.
    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(180_000);
});

it('release listener is idempotent — a second reactivation does not double-credit', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create(['status' => 'active', 'adn' => '100000902']);
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');
    GroupBvDaily::create([
        'distributor_id' => $dist->id, 'date' => '2026-03-06',
        'left_bv_paise' => 2_000_000, 'right_bv_paise' => 1_600_000,
    ]);

    svc()->evaluate($dist->id, Carbon::parse('2026-03-06'));
    app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::parse('2026-03-06'));

    // Fire the reactivation twice directly.
    event(new IncomeReactivated($dist->id, 1));
    event(new IncomeReactivated($dist->id, 1));

    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(180_000); // credited once only
});
