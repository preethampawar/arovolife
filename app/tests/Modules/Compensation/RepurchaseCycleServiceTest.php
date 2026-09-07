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

/** Put money in the distributor's repurchase wallet so condition 4(B) fails. */
function seedRepurchaseWalletCredit(int $distributorId, int $amountPaise, string $at): void
{
    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'type' => 'repurchase_deduction',
        'amount_paise' => $amountPaise,
        'reference_type' => 'test',
        'reference_id' => 1,
        'memo' => 'test repurchase deduction',
        'created_at' => $at,
    ]);
}

/** Spend the repurchase wallet down at checkout so condition 4(B) passes. */
function seedRepurchaseWalletSpend(int $distributorId, int $amountPaise, string $at): void
{
    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'type' => 'repurchase_wallet_used',
        'amount_paise' => -$amountPaise,
        'reference_type' => 'order',
        'reference_id' => 1,
        'memo' => 'test repurchase spend',
        'created_at' => $at,
    ]);
}

it('has no obligation until the distributor reaches 600 BV personal', function (): void {
    // The repurchase anchor is the 600-BV first-purchase point. Below it there
    // is no obligation at all.
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 50_000, '2026-01-05'); // 500 BV < 600

    expect(svc()->evaluate($dist->id, Carbon::parse('2026-02-20')))->toBeNull();
});

it('opens a 30-day window anchored on the day 600 BV was reached', function (): void {
    // Client rule 1: "the 30-day period from that date", inclusive of it.
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 60_000, '2026-01-05'); // exactly 600 BV

    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-01-20'));

    expect($cycle)->not->toBeNull();
    expect($cycle->cycle_start_date->toDateString())->toBe('2026-01-05');
    expect($cycle->due_date->toDateString())->toBe('2026-02-03'); // start + 29 days
    expect($cycle->required_bv_paise)->toBe(60_000);              // non-ranked 600 BV
});

it("matches the client's worked cycle examples", function (): void {
    // A) 1 Jan → 30 Jan.  B) 1 Feb → 2 Mar (2026 is not a leap year).
    foreach ([['2026-01-01', '2026-01-30'], ['2026-02-01', '2026-03-02']] as [$anchor, $expectedDue]) {
        $dist = Distributor::factory()->create();
        seedSelfPurchase($dist->id, 60_000, $anchor);

        $cycle = svc()->evaluate($dist->id, Carbon::parse($anchor));

        expect($cycle->due_date->toDateString())->toBe($expectedDue);
    }
});

it('cannot complete a cycle early — the wallet condition needs the last day', function (): void {
    // Rule 4(B) asks about the wallet on the window's LAST day, so a cycle whose
    // BV obligation is already met stays ACTIVE until the window closes.
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-05'); // anchor; window 01-05 → 02-03
    seedSelfPurchase($dist->id, 60_000, '2026-01-10');  // obligation met on day 6

    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-01-20'));

    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_ACTIVE);
    expect($cycle->completed_bv_paise)->toBe(360_000);
    expect($cycle->resolved_at)->toBeNull();
});

it('completes at the window end when both conditions hold, and freezes the wallet balance', function (): void {
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');

    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-02-03'));
    // Still the last day of the window — resolved only once it has passed.
    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_ACTIVE);

    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-02-04'));

    // The first window resolved and handed over to the next one on due + 1.
    $first = RepurchaseCycle::where('distributor_id', $dist->id)->orderBy('cycle_start_date')->first();
    expect($first->status)->toBe(RepurchaseCycle::STATUS_COMPLETED);
    expect($first->wallet_zeroed)->toBeTrue();
    expect($first->wallet_balance_paise)->toBe(0);
    expect($first->fulfilled_on->toDateString())->toBe('2026-02-03');
    expect($first->failure_reason)->toBeNull();
    expect($cycle->cycle_start_date->toDateString())->toBe('2026-02-04');
});

it('fails the cycle when the repurchase wallet is not zero on the last day', function (): void {
    // Condition 4(A) met, 4(B) not: the client's second proof.
    Event::fake([IncomeSuspended::class]);
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');
    seedRepurchaseWalletCredit($dist->id, 25_000, '2026-01-20 10:00:00');

    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-02-04'));

    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_SUSPENDED);
    expect($cycle->failure_reason)->toBe(RepurchaseCycle::REASON_WALLET_NONZERO);
    expect($cycle->wallet_balance_paise)->toBe(25_000);
    expect($cycle->wallet_zeroed)->toBeFalse();
    expect($cycle->fulfilled_on)->toBeNull();
    Event::assertDispatched(IncomeSuspended::class);
});

it('fails the cycle when the window BV falls short', function (): void {
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 60_000, '2026-01-05'); // anchor only; nothing repurchased after

    // Window 01-05 → 02-03 is satisfied by the anchoring purchase itself, so the
    // shortfall shows on the SECOND window (02-04 → 03-05).
    svc()->evaluate($dist->id, Carbon::parse('2026-03-06'));

    $second = RepurchaseCycle::where('distributor_id', $dist->id)
        ->whereDate('cycle_start_date', '2026-02-04')->first();

    expect($second->status)->toBe(RepurchaseCycle::STATUS_SUSPENDED);
    expect($second->failure_reason)->toBe(RepurchaseCycle::REASON_BV_SHORT);
});

it('records both reasons when BV and wallet fail together', function (): void {
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 60_000, '2026-01-05');
    seedRepurchaseWalletCredit($dist->id, 10_000, '2026-02-10 10:00:00');

    svc()->evaluate($dist->id, Carbon::parse('2026-03-06'));

    $second = RepurchaseCycle::where('distributor_id', $dist->id)
        ->whereDate('cycle_start_date', '2026-02-04')->first();

    expect($second->failure_reason)->toBe(RepurchaseCycle::REASON_BOTH);
});

it('re-anchors a new 30-day window on the day a failed cycle is fulfilled', function (): void {
    // Client rule 9: failed on the due date, fulfilled later → the NEW window
    // starts on the fulfilment day itself, not the day after the old one ended.
    Event::fake([IncomeReactivated::class]);
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 60_000, '2026-01-05');

    // Second window 02-04 → 03-05 fails (no repurchase in it).
    svc()->evaluate($dist->id, Carbon::parse('2026-03-06'));

    // Fulfilled on 03-15 — ten days late.
    seedSelfPurchase($dist->id, 60_000, '2026-03-15');
    $current = svc()->evaluate($dist->id, Carbon::parse('2026-03-20'));

    $failed = RepurchaseCycle::where('distributor_id', $dist->id)
        ->whereDate('cycle_start_date', '2026-02-04')->first();

    expect($failed->status)->toBe(RepurchaseCycle::STATUS_COMPLETED);
    expect($failed->fulfilled_on->toDateString())->toBe('2026-03-15');
    expect($failed->fulfilledOnTime())->toBeFalse();

    expect($current->cycle_start_date->toDateString())->toBe('2026-03-15');
    expect($current->due_date->toDateString())->toBe('2026-04-13'); // 30 days from 03-15
    Event::assertDispatched(IncomeReactivated::class);
});

it('stamps the fulfilment day the conditions actually met, not the day it was noticed', function (): void {
    // A catch-up replay must not shift every later window by pretending the
    // distributor fulfilled today.
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 60_000, '2026-01-05');
    seedSelfPurchase($dist->id, 60_000, '2026-03-10'); // fulfils the failed 02-04 window

    svc()->evaluate($dist->id, Carbon::parse('2026-04-20')); // noticed six weeks later

    $failed = RepurchaseCycle::where('distributor_id', $dist->id)
        ->whereDate('cycle_start_date', '2026-02-04')->first();

    expect($failed->fulfilled_on->toDateString())->toBe('2026-03-10');
});

it('does not double-count BV when a failed cycle is re-evaluated', function (): void {
    // Regression: the late-fulfilment scan re-derives its base from the ledger,
    // so running it twice cannot inflate completed_bv_paise past the truth.
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 60_000, '2026-01-05');
    seedSelfPurchase($dist->id, 20_000, '2026-03-10'); // 200 BV — still short of 600

    svc()->evaluate($dist->id, Carbon::parse('2026-03-20'));
    svc()->evaluate($dist->id, Carbon::parse('2026-03-21'));

    $failed = RepurchaseCycle::where('distributor_id', $dist->id)
        ->whereDate('cycle_start_date', '2026-02-04')->first();

    expect($failed->completed_bv_paise)->toBe(20_000);
    expect($failed->status)->toBe(RepurchaseCycle::STATUS_SUSPENDED);
});

it('needs the wallet cleared as well as the BV before a failed cycle is fulfilled', function (): void {
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 60_000, '2026-01-05');
    seedRepurchaseWalletCredit($dist->id, 30_000, '2026-02-10 10:00:00');
    seedSelfPurchase($dist->id, 60_000, '2026-03-10'); // BV now met, wallet still holds ₹300

    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-03-12'));
    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_SUSPENDED);

    seedRepurchaseWalletSpend($dist->id, 30_000, '2026-03-18 09:00:00');
    svc()->evaluate($dist->id, Carbon::parse('2026-03-20'));

    $failed = RepurchaseCycle::where('distributor_id', $dist->id)
        ->whereDate('cycle_start_date', '2026-02-04')->first();

    expect($failed->status)->toBe(RepurchaseCycle::STATUS_COMPLETED);
    expect($failed->fulfilled_on->toDateString())->toBe('2026-03-18');
});

it('never lets an unmeasured wallet pass the cleared-wallet condition', function (): void {
    // Regression. A cycle resolved before wallet_balance_paise existed carries
    // NULL there. Reading that as "the wallet was clear" completed the cycle for
    // a distributor who was holding repurchase money — the exact under-withhold
    // this engine exists to prevent.
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 60_000, '2026-01-05');
    seedRepurchaseWalletCredit($dist->id, 42_000, '2026-01-20 10:00:00');
    seedSelfPurchase($dist->id, 60_000, '2026-02-20'); // BV obligation met inside the window

    // A legacy row: past its window, marked resolved, wallet never measured.
    DB::table('repurchase_cycles')->insert([
        'distributor_id' => $dist->id,
        'cycle_start_date' => '2026-02-04',
        'due_date' => '2026-03-05',
        'grace_end_date' => '2026-03-05',
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 0,
        'wallet_balance_paise' => null,
        'wallet_zeroed' => null,
        'status' => RepurchaseCycle::STATUS_SUSPENDED,
        'resolved_at' => '2026-03-06 00:05:00',
        'created_at' => '2026-02-04 00:05:00',
        'updated_at' => '2026-03-06 00:05:00',
    ]);

    svc()->evaluate($dist->id, Carbon::parse('2026-03-10'));

    $cycle = RepurchaseCycle::whereDate('cycle_start_date', '2026-02-04')->firstOrFail();

    // BV was met, so only the wallet can hold it — and it must.
    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_SUSPENDED)
        ->and($cycle->fulfilled_on)->toBeNull()
        // The scan freezes what it measured, so the row stops being ambiguous.
        ->and($cycle->wallet_balance_paise)->toBe(42_000)
        ->and($cycle->wallet_zeroed)->toBeFalse();
});

it('completes a late fulfilment once the derived wallet balance really is clear', function (): void {
    // The mirror of the regression above: same unmeasured legacy row, but the
    // wallet was genuinely spent down, so the cycle must complete.
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 60_000, '2026-01-05');
    seedRepurchaseWalletCredit($dist->id, 42_000, '2026-01-20 10:00:00');
    seedRepurchaseWalletSpend($dist->id, 42_000, '2026-02-25 10:00:00');
    seedSelfPurchase($dist->id, 60_000, '2026-02-20');

    DB::table('repurchase_cycles')->insert([
        'distributor_id' => $dist->id,
        'cycle_start_date' => '2026-02-04',
        'due_date' => '2026-03-05',
        'grace_end_date' => '2026-03-05',
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 0,
        'wallet_balance_paise' => null,
        'wallet_zeroed' => null,
        'status' => RepurchaseCycle::STATUS_SUSPENDED,
        'resolved_at' => '2026-03-06 00:05:00',
        'created_at' => '2026-02-04 00:05:00',
        'updated_at' => '2026-03-06 00:05:00',
    ]);

    svc()->evaluate($dist->id, Carbon::parse('2026-03-10'));

    $cycle = RepurchaseCycle::whereDate('cycle_start_date', '2026-02-04')->firstOrFail();

    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_COMPLETED)
        ->and($cycle->wallet_balance_paise)->toBe(0)
        ->and($cycle->wallet_zeroed)->toBeTrue();
});

it('does not demote an already-resolved cycle back to active inside its window', function (): void {
    // Regression. A cycle written before the 2026-09-06 rules completed as soon
    // as its BV landed, so its window can still be open when the new engine
    // first sees it. Resetting it to active discarded the verdict AND left the
    // wallet unfrozen, which is what fed the bug above.
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 60_000, '2026-01-05');

    DB::table('repurchase_cycles')->insert([
        'distributor_id' => $dist->id,
        'cycle_start_date' => '2026-02-04',
        'due_date' => '2026-03-05',
        'grace_end_date' => '2026-03-05',
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 60_000,
        'status' => RepurchaseCycle::STATUS_COMPLETED,
        'fulfilled_on' => '2026-03-05',
        'resolved_at' => '2026-02-10 00:05:00',
        'created_at' => '2026-02-04 00:05:00',
        'updated_at' => '2026-02-10 00:05:00',
    ]);

    // Evaluated while the window is still open.
    svc()->evaluate($dist->id, Carbon::parse('2026-02-20'));

    $cycle = RepurchaseCycle::whereDate('cycle_start_date', '2026-02-04')->firstOrFail();

    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_COMPLETED)
        ->and($cycle->fulfilled_on->toDateString())->toBe('2026-03-05');
});

it('handles a month-end anchor without window errors', function (): void {
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-31');

    // Jan 31 + 29 days = Mar 1, so the first window spans a short month
    // without the date arithmetic overflowing; the second lapses unrepurchased.
    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-04-15'));

    expect($cycle)->not->toBeNull();
    expect($cycle->cycle_start_date->toDateString())->toBe('2026-03-02');
    expect($cycle->due_date->toDateString())->toBe('2026-03-31');
    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_SUSPENDED);
});

it('reads the per-rank repurchase BV from config, not a constant', function (): void {
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');
    RankQualification::create([
        'distributor_id' => $dist->id, 'rank_number' => 1, 'month_start' => '2026-01-01',
        'occurrence_in_month' => 1, 'is_carry_forward' => false, 'status' => RankQualification::STATUS_QUALIFIED,
    ]);

    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-01-20'));

    expect($cycle->required_bv_paise)->toBe(100_000); // 1,000 BV, from rank_tiers
});

it('withholds GSB, Rank, Growth Booster and Fortune — never Mentorship', function (): void {
    // Client rule 7: MSB is the one of the five that keeps paying.
    $svc = app(IncomeEligibilityService::class);

    expect($svc->suspends(BonusType::Gsb))->toBeTrue();
    expect($svc->suspends(BonusType::Rank))->toBeTrue();
    expect($svc->suspends(BonusType::GrowthBooster))->toBeTrue();
    expect($svc->suspends(BonusType::Fortune))->toBeTrue();
    expect($svc->suspends(BonusType::Mentorship))->toBeFalse();
    expect($svc->suspends(BonusType::Arete))->toBeFalse();
    expect($svc->suspends(BonusType::LifetimeAwards))->toBeFalse();
});

it('answers eligibility for a past date from the cycle that governed it', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 60_000, '2026-01-05');
    seedSelfPurchase($dist->id, 60_000, '2026-03-15'); // fulfils the failed 02-04 window late

    svc()->evaluate($dist->id, Carbon::parse('2026-03-20'));

    $svc = app(IncomeEligibilityService::class);

    // Inside the failed window nothing was due yet.
    expect($svc->verdictAsOf($dist->id, BonusType::Gsb, Carbon::parse('2026-02-20'))->isEligible())->toBeTrue();
    // In the gap between the window ending and the late fulfilment, income was held.
    expect($svc->verdictAsOf($dist->id, BonusType::Gsb, Carbon::parse('2026-03-10'))->isEligible())->toBeFalse();
    // From the re-anchored window onward, eligible again.
    expect($svc->verdictAsOf($dist->id, BonusType::Gsb, Carbon::parse('2026-03-16'))->isEligible())->toBeTrue();
    // Mentorship is never gated, even in the gap.
    expect($svc->verdictAsOf($dist->id, BonusType::Mentorship, Carbon::parse('2026-03-10'))->isEligible())->toBeTrue();
});

// ── GSB cut-off gate ────────────────────────────────────────────────────────

/** A distributor (anchor 2026-01-05, never repurchased after) whose group BV on
 *  $date matches GSB slab 1 (weaker side ≥ 15,000 BV). */
function makeGsbReadyRetailer(string $date): Distributor
{
    $dist = Distributor::factory()->create(['status' => 'active', 'adn' => '100000900']);
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');
    GroupBvDaily::create([
        'distributor_id' => $dist->id, 'date' => $date,
        'left_bv_paise' => 2_000_000, 'right_bv_paise' => 1_600_000, // weaker 16,000 BV ≥ slab 1
    ]);

    // The gate is read-only — the daily command (here, a direct evaluate) is the
    // sole writer that establishes the cycle status the cut-off then reads.
    svc()->evaluate($dist->id, Carbon::parse($date));

    return $dist;
}

it('credits GSB normally when the repurchase engine is OFF, even past a failed window', function (): void {
    $dist = makeGsbReadyRetailer('2026-03-20');

    $result = app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::parse('2026-03-20'));

    expect($result->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
});

it('holds the GSB credit when the engine is ON and the cycle has failed', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = makeGsbReadyRetailer('2026-03-20'); // window 02-04 → 03-05 failed

    $result = app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::parse('2026-03-20'));

    expect($result->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_HELD);
    expect($result->gross_gsb_paise)->toBeGreaterThan(0);          // calculated…
    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(0); // …but not credited
});

it('releases held GSB rows when the distributor fulfils the repurchase', function (): void {
    // Client rule 8: withheld income is reinstated, not forfeited.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create(['status' => 'active', 'adn' => '100000901']);
    seedSelfPurchase($dist->id, 300_000, '2026-01-05'); // window 02-04 → 03-05 will fail

    foreach (['2026-03-06', '2026-03-08'] as $date) {
        GroupBvDaily::create([
            'distributor_id' => $dist->id, 'date' => $date,
            'left_bv_paise' => 2_000_000, 'right_bv_paise' => 1_600_000,
        ]);
    }

    $gsb = app(GsbCutoffService::class);
    $rows = [];

    foreach (['2026-03-06', '2026-03-08'] as $date) {
        svc()->evaluate($dist->id, Carbon::parse($date));
        $rows[] = $gsb->runForDistributor($dist->id, Carbon::parse($date));
    }

    expect($rows[0]->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_HELD);
    expect($rows[1]->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_HELD);
    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(0);

    // Fulfil the obligation → both held rows are released to the wallet.
    seedSelfPurchase($dist->id, 60_000, '2026-03-20');
    $current = svc()->evaluate($dist->id, Carbon::parse('2026-03-20'));

    // evaluate() hands back the window that was just re-anchored on the
    // fulfilment day; the one that failed is now completed behind it.
    expect($current->cycle_start_date->toDateString())->toBe('2026-03-20');
    expect(RepurchaseCycle::whereDate('cycle_start_date', '2026-02-04')->first()->status)
        ->toBe(RepurchaseCycle::STATUS_COMPLETED);
    expect($rows[0]->fresh()->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
    expect($rows[1]->fresh()->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
    expect(app(WalletService::class)->balancePaise($dist->id))->toBeGreaterThan(0);
});

it('release listener is idempotent — a second reactivation credits nothing more', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    $dist = Distributor::factory()->create(['status' => 'active', 'adn' => '100000902']);
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');
    GroupBvDaily::create([
        'distributor_id' => $dist->id, 'date' => '2026-03-06',
        'left_bv_paise' => 2_000_000, 'right_bv_paise' => 1_600_000,
    ]);

    svc()->evaluate($dist->id, Carbon::parse('2026-03-06'));
    app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::parse('2026-03-06'));

    event(new IncomeReactivated($dist->id, 1));
    $afterFirst = app(WalletService::class)->balancePaise($dist->id);

    event(new IncomeReactivated($dist->id, 1));

    expect($afterFirst)->toBeGreaterThan(0);
    expect(app(WalletService::class)->balancePaise($dist->id))->toBe($afterFirst);
});
