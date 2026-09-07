<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Events\IncomeReactivated;
use App\Modules\Compensation\Events\IncomeSuspended;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\DTOs\GsbCutoffComputation;
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
    // Client spec §1: the anchor day is day 0 and the due date is 30 days after
    // it — "completed his 600-BV on July 7th, therefore his Beginning Date is
    // July 7th", judged on 6 August.
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 60_000, '2026-07-07'); // exactly 600 BV

    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-07-20'));

    expect($cycle)->not->toBeNull();
    expect($cycle->cycle_start_date->toDateString())->toBe('2026-07-07');
    expect($cycle->due_date->toDateString())->toBe('2026-08-06'); // start + 30 days
    expect($cycle->required_bv_paise)->toBe(60_000);              // non-ranked 600 BV
});

it("matches the client's worked cycle examples", function (): void {
    // All six dated examples in the client spec (§1) are exactly start + 30.
    foreach ([
        ['2026-07-07', '2026-08-06'],
        ['2026-07-13', '2026-08-12'],
        ['2026-07-24', '2026-08-23'],
        ['2026-08-09', '2026-09-08'],
        ['2026-08-17', '2026-09-16'],
        ['2026-08-27', '2026-09-26'],
    ] as [$anchor, $expectedDue]) {
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
    seedSelfPurchase($dist->id, 300_000, '2026-01-05'); // anchor; window 01-05 → 02-04
    seedSelfPurchase($dist->id, 60_000, '2026-01-10');  // obligation met on day 6

    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-01-20'));

    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_ACTIVE);
    expect($cycle->completed_bv_paise)->toBe(360_000);
    expect($cycle->resolved_at)->toBeNull();
});

it('completes at the window end when both conditions hold, and freezes the wallet balance', function (): void {
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');

    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-02-04'));
    // Still the last day of the window — resolved only once it has passed.
    expect($cycle->status)->toBe(RepurchaseCycle::STATUS_ACTIVE);

    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-02-05'));

    // The first window resolved and handed over to the next one on due + 1.
    $first = RepurchaseCycle::where('distributor_id', $dist->id)->orderBy('cycle_start_date')->first();
    expect($first->status)->toBe(RepurchaseCycle::STATUS_COMPLETED);
    expect($first->wallet_zeroed)->toBeTrue();
    expect($first->wallet_balance_paise)->toBe(0);
    expect($first->fulfilled_on->toDateString())->toBe('2026-02-04');
    expect($first->failure_reason)->toBeNull();
    expect($cycle->cycle_start_date->toDateString())->toBe('2026-02-05');
});

it('fails the cycle when the repurchase wallet is not zero on the last day', function (): void {
    // Condition 4(A) met, 4(B) not: the client's second proof.
    Event::fake([IncomeSuspended::class]);
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');
    seedRepurchaseWalletCredit($dist->id, 25_000, '2026-01-20 10:00:00');

    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-02-05'));

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

    // Window 01-05 → 02-04 is satisfied by the anchoring purchase itself, so the
    // shortfall shows on the SECOND window (02-05 → 03-07).
    svc()->evaluate($dist->id, Carbon::parse('2026-03-08'));

    $second = RepurchaseCycle::where('distributor_id', $dist->id)
        ->whereDate('cycle_start_date', '2026-02-05')->first();

    expect($second->status)->toBe(RepurchaseCycle::STATUS_SUSPENDED);
    expect($second->failure_reason)->toBe(RepurchaseCycle::REASON_BV_SHORT);
});

it('records both reasons when BV and wallet fail together', function (): void {
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 60_000, '2026-01-05');
    seedRepurchaseWalletCredit($dist->id, 10_000, '2026-02-10 10:00:00');

    svc()->evaluate($dist->id, Carbon::parse('2026-03-08'));

    $second = RepurchaseCycle::where('distributor_id', $dist->id)
        ->whereDate('cycle_start_date', '2026-02-05')->first();

    expect($second->failure_reason)->toBe(RepurchaseCycle::REASON_BOTH);
});

it('re-anchors a new 30-day window on the day a failed cycle is fulfilled', function (): void {
    // The client's distributor C: the window 24 Jul → 23 Aug failed, he
    // repurchased on 27 Aug, and his fresh window runs 27 Aug → 26 Sep — it
    // starts on the fulfilment day itself, not the day after the old one ended.
    Event::fake([IncomeReactivated::class]);
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 60_000, '2026-06-23'); // anchor; window 06-23 → 07-23

    // Second window 07-24 → 08-23 fails (no repurchase in it).
    svc()->evaluate($dist->id, Carbon::parse('2026-08-24'));

    // Fulfilled on 08-27 — four days late.
    seedSelfPurchase($dist->id, 60_000, '2026-08-27');
    $current = svc()->evaluate($dist->id, Carbon::parse('2026-08-31'));

    $failed = RepurchaseCycle::where('distributor_id', $dist->id)
        ->whereDate('cycle_start_date', '2026-07-24')->first();

    expect($failed->due_date->toDateString())->toBe('2026-08-23');
    expect($failed->status)->toBe(RepurchaseCycle::STATUS_COMPLETED);
    expect($failed->fulfilled_on->toDateString())->toBe('2026-08-27');
    expect($failed->fulfilledOnTime())->toBeFalse();

    expect($current->cycle_start_date->toDateString())->toBe('2026-08-27');
    expect($current->due_date->toDateString())->toBe('2026-09-26'); // 30 days from 08-27
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
        ->whereDate('cycle_start_date', '2026-02-05')->first();

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
        ->whereDate('cycle_start_date', '2026-02-05')->first();

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
        ->whereDate('cycle_start_date', '2026-02-05')->first();

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

    // Jan 31 + 30 days = Mar 2, so the first window spans a short month without
    // the date arithmetic overflowing; the second lapses unrepurchased. A day
    // count never has to answer "the 31st of the month after February".
    $cycle = svc()->evaluate($dist->id, Carbon::parse('2026-04-15'));

    expect($cycle)->not->toBeNull();
    expect($cycle->cycle_start_date->toDateString())->toBe('2026-03-03');
    expect($cycle->due_date->toDateString())->toBe('2026-04-02');
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

it('forfeitedWindow is null on time, [due + 1, fulfilled − 1] when late, null when fulfilled the very next day, open-ended while unresolved, null inside the window', function (): void {
    // The one place that answers "which days did this distributor lose?" —
    // client spec §2: every day from the day after the due date up to the day
    // before the fulfilment day is forfeited.
    $dist = Distributor::factory()->create();

    $cycle = fn (array $overrides): RepurchaseCycle => RepurchaseCycle::create([
        'distributor_id' => $dist->id,
        'due_date' => '2026-08-23',
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 60_000,
        'status' => RepurchaseCycle::STATUS_COMPLETED,
        ...$overrides,
    ]);

    $onTime = $cycle([
        'cycle_start_date' => '2026-07-24',
        'fulfilled_on' => '2026-08-23',
        'resolved_at' => '2026-08-24 00:05:00',
    ]);
    expect($onTime->forfeitedWindow())->toBeNull();

    // The client's distributor C: failed 24–26 Aug, fulfilled on the 27th.
    $late = $cycle([
        'cycle_start_date' => '2026-07-25',
        'fulfilled_on' => '2026-08-27',
        'resolved_at' => '2026-08-24 00:05:00',
    ]);
    [$from, $to] = $late->forfeitedWindow();
    expect($from->toDateString())->toBe('2026-08-24')
        ->and($to->toDateString())->toBe('2026-08-26');

    // Fulfilled the very next day: the fulfilment day counts in full, so
    // nothing at all was lost — an empty range is no window, never [due + 1,
    // due], which a between() check would read backwards.
    $nextDay = $cycle([
        'cycle_start_date' => '2026-07-26',
        'fulfilled_on' => '2026-08-24',
        'resolved_at' => '2026-08-24 00:05:00',
    ]);
    expect($nextDay->forfeitedWindow())->toBeNull();

    // Still failed: the window runs on with no end yet.
    $stillFailed = $cycle([
        'cycle_start_date' => '2026-07-27',
        'status' => RepurchaseCycle::STATUS_SUSPENDED,
        'completed_bv_paise' => 0,
        'failure_reason' => RepurchaseCycle::REASON_BV_SHORT,
        'resolved_at' => '2026-08-24 00:05:00',
    ]);
    [$from, $to] = $stillFailed->forfeitedWindow();
    expect($from->toDateString())->toBe('2026-08-24')->and($to)->toBeNull();

    // Inside its own window nothing is forfeited — the verdict is not taken yet.
    $open = $cycle([
        'cycle_start_date' => '2026-07-28',
        'status' => RepurchaseCycle::STATUS_ACTIVE,
        'completed_bv_paise' => 0,
    ]);
    expect($open->forfeitedWindow())->toBeNull();
});

it('answers eligibility for a past date from the cycle that governed it', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create();
    seedSelfPurchase($dist->id, 60_000, '2026-01-05');
    seedSelfPurchase($dist->id, 60_000, '2026-03-15'); // fulfils the failed 02-05 window late

    svc()->evaluate($dist->id, Carbon::parse('2026-03-20'));

    $svc = app(IncomeEligibilityService::class);

    // Inside the window that later failed, nothing was due yet.
    expect($svc->verdictAsOf($dist->id, Carbon::parse('2026-02-20'))->isEligible())->toBeTrue();
    // Between the window closing and the late fulfilment, the day is forfeited.
    expect($svc->verdictAsOf($dist->id, Carbon::parse('2026-03-10'))->isEligible())->toBeFalse();
    // From the re-anchored window onward, eligible again.
    expect($svc->verdictAsOf($dist->id, Carbon::parse('2026-03-16'))->isEligible())->toBeTrue();
});

// ── Forfeited days (client spec §2) ─────────────────────────────────────────

/** A resolved cycle row, straight from the calendar dates the client's examples use. */
function seedCycle(int $distributorId, string $start, string $due, ?string $fulfilledOn, ?string $resolvedAt = '00:05:00'): RepurchaseCycle
{
    return RepurchaseCycle::create([
        'distributor_id' => $distributorId,
        'cycle_start_date' => $start,
        'due_date' => $due,
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => $fulfilledOn === null ? 0 : 60_000,
        'status' => match (true) {
            $resolvedAt === null => RepurchaseCycle::STATUS_ACTIVE,
            $fulfilledOn === null => RepurchaseCycle::STATUS_SUSPENDED,
            default => RepurchaseCycle::STATUS_COMPLETED,
        },
        'fulfilled_on' => $fulfilledOn,
        'failure_reason' => $resolvedAt !== null && $fulfilledOn === null ? RepurchaseCycle::REASON_BV_SHORT : null,
        'resolved_at' => $resolvedAt === null ? null : Carbon::parse($due)->addDay()->toDateString().' '.$resolvedAt,
    ]);
}

it('forfeits Aug 24–26 for a cycle due Aug 23 fulfilled Aug 27 (RB example 3)', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create();
    seedCycle($dist->id, '2026-07-24', '2026-08-23', '2026-08-27');

    expect(app(IncomeEligibilityService::class)
        ->forfeitedDayRanges(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')))
        ->toBe([$dist->id => [['2026-08-24', '2026-08-26']]]);
});

it('runs an unresolved failure to the end of the asked window', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create();
    seedCycle($dist->id, '2026-07-24', '2026-08-23', null);

    expect(app(IncomeEligibilityService::class)
        ->forfeitedDayRanges(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')))
        ->toBe([$dist->id => [['2026-08-24', '2026-08-31']]]);
});

it('clips a window that started before the asked range', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create();
    seedCycle($dist->id, '2026-06-20', '2026-07-20', '2026-08-10'); // forfeited 21 Jul – 9 Aug

    expect(app(IncomeEligibilityService::class)
        ->forfeitedDayRanges(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')))
        ->toBe([$dist->id => [['2026-08-01', '2026-08-09']]]);
});

it('returns two ranges for two consecutive failed cycles', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create();
    seedCycle($dist->id, '2026-06-05', '2026-07-05', '2026-07-10'); // forfeited 6–9 Jul
    seedCycle($dist->id, '2026-07-10', '2026-08-09', '2026-08-15'); // forfeited 10–14 Aug

    expect(app(IncomeEligibilityService::class)
        ->forfeitedDayRanges(Carbon::parse('2026-07-01'), Carbon::parse('2026-09-30')))
        ->toBe([$dist->id => [['2026-07-06', '2026-07-09'], ['2026-08-10', '2026-08-14']]]);
});

it('agrees day for day with verdictAsOf across August', function (): void {
    // One predicate, two shapes: the daily engine asks verdictAsOf() and the
    // monthly rank sum asks forfeitedDayRanges(). They must never disagree.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create();
    seedCycle($dist->id, '2026-07-24', '2026-08-23', '2026-08-27');
    seedCycle($dist->id, '2026-08-27', '2026-09-26', null, resolvedAt: null); // still running

    $svc = app(IncomeEligibilityService::class);

    $fromRanges = [];
    foreach ($svc->forfeitedDayRanges(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'))[$dist->id] ?? [] as [$start, $end]) {
        foreach (Carbon::parse($start)->toPeriod(Carbon::parse($end)) as $day) {
            $fromRanges[] = $day->toDateString();
        }
    }

    $fromVerdict = [];
    foreach (Carbon::parse('2026-08-01')->toPeriod(Carbon::parse('2026-08-31')) as $day) {
        if (! $svc->verdictAsOf($dist->id, $day)->isEligible()) {
            $fromVerdict[] = $day->toDateString();
        }
    }

    expect($fromVerdict)->toBe($fromRanges)
        ->and($fromVerdict)->toBe(['2026-08-24', '2026-08-25', '2026-08-26']);
});

it('forfeits nothing for a cycle fulfilled on time, and nothing at all when the engine is off', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create();
    $onTime = seedCycle($dist->id, '2026-07-24', '2026-08-23', '2026-08-23');
    $svc = app(IncomeEligibilityService::class);

    expect($svc->forfeitedDayRanges(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')))->toBe([]);

    // A real failure, with the engine switched off: no forfeits anywhere.
    $onTime->update(['fulfilled_on' => '2026-08-27']);
    Feature::for(null)->deactivate(RepurchaseEngineFeature::class);

    expect($svc->forfeitedDayRanges(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')))->toBe([])
        ->and($svc->verdictAsOf($dist->id, Carbon::parse('2026-08-25'))->isEligible())->toBeTrue();
});

// ── GSB cut-off: a failed day is forfeited, never held ───────────────────────

/**
 * The conditional personal-BV top-up is live by default (go-live 1970-01-01)
 * and would silently move BV between the legs of these fixtures. Push it out of
 * the way; the one test that cares about it switches it back on itself.
 */
function gsbSuppressTopup(): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => 'comp.gsb.topup_golive_date'],
        ['value' => '2099-01-01'],
    );
}

/**
 * A Retailer whose cycle (05 Jan → 04 Feb) was fulfilled late on 10 Feb, so
 * 05–09 Feb are forfeited and 10 Feb counts again.
 */
function makeLateFulfiller(string $adn = '100000900'): Distributor
{
    $dist = Distributor::factory()->create(['status' => 'active', 'adn' => $adn]);
    seedSelfPurchase($dist->id, 300_000, '2026-01-05');
    seedCycle($dist->id, '2026-01-05', '2026-02-04', '2026-02-10');

    return $dist;
}

/** Today's Genos BV for one distributor. */
function seedDayBv(int $distributorId, string $date, int $leftPaise, int $rightPaise): void
{
    GroupBvDaily::create([
        'distributor_id' => $distributorId, 'date' => $date,
        'left_bv_paise' => $leftPaise, 'right_bv_paise' => $rightPaise,
    ]);
}

it('credits GSB normally when the repurchase engine is OFF, even past a failed window', function (): void {
    gsbSuppressTopup();
    $dist = makeLateFulfiller();
    seedDayBv($dist->id, '2026-02-06', 2_000_000, 1_600_000);

    $result = app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::parse('2026-02-06'));

    expect($result->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
});

it('forfeits a failed day: zero income, no ledger row, both carry-forwards untouched, day BV not added', function (): void {
    // Client spec §2.1 / answer 3: "the BVs on both sides of the left genos and
    // right genos at that time will stop there as assets". No match, no income,
    // and the two stores stand exactly where the due date left them.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    gsbSuppressTopup();
    $dist = makeLateFulfiller();

    GsbCarryforward::create([
        'distributor_id' => $dist->id,
        'power_side_bv_paise' => 800_000, 'power_side' => 'L', 'slab1_weaker_bv_paise' => 500_000,
    ]);
    seedDayBv($dist->id, '2026-02-06', 2_000_000, 1_600_000); // would match slab 1

    $result = app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::parse('2026-02-06'));

    expect($result->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_FORFEITED)
        ->and($result->gross_gsb_paise)->toBe(0)
        ->and($result->net_gsb_paise)->toBe(0)
        ->and($result->slab)->toBeNull()
        ->and($result->score)->toBeNull()
        ->and($result->weaker_bv_paise)->toBe(0)
        // The day's raw Genos BV is recorded for the report — and nothing else.
        ->and($result->left_bv_paise)->toBe(2_000_000)
        ->and($result->right_bv_paise)->toBe(1_600_000)
        // The carry-forward invariant: after == before, on both stores.
        ->and($result->power_cf_after_paise)->toBe($result->power_cf_before_paise)
        ->and($result->power_cf_after_paise)->toBe(800_000)
        ->and($result->power_side_before)->toBe('L')
        ->and($result->power_side_after)->toBe('L')
        ->and($result->slab1_weaker_cf_after_paise)->toBe($result->slab1_weaker_cf_before_paise)
        ->and($result->slab1_weaker_cf_after_paise)->toBe(500_000);

    $cf = GsbCarryforward::where('distributor_id', $dist->id)->first();
    expect($cf->power_side_bv_paise)->toBe(800_000)
        ->and($cf->power_side)->toBe('L')
        ->and($cf->slab1_weaker_bv_paise)->toBe(500_000);

    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(0)
        ->and(DB::table('wallet_ledger_entries')->where('distributor_id', $dist->id)->count())->toBe(0);
});

it('forfeits a small-BV failed day without accumulating it into the slab-1 store', function (): void {
    // The everyday case: far below any slab. An eligible day would add the
    // weaker side to the lifetime slab-1 accumulator; a forfeited day must not.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    gsbSuppressTopup();
    $dist = makeLateFulfiller();

    GsbCarryforward::create([
        'distributor_id' => $dist->id,
        'power_side_bv_paise' => 120_000, 'power_side' => 'R', 'slab1_weaker_bv_paise' => 100_000,
    ]);
    seedDayBv($dist->id, '2026-02-07', 50_000, 30_000);

    $result = app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::parse('2026-02-07'));

    expect($result->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_FORFEITED)
        ->and($result->slab1_weaker_cf_after_paise)->toBe(100_000)
        ->and($result->power_cf_after_paise)->toBe(120_000)
        ->and($result->power_side_after)->toBe('R');

    $cf = GsbCarryforward::where('distributor_id', $dist->id)->first();
    expect($cf->slab1_weaker_bv_paise)->toBe(100_000)
        ->and($cf->power_side_bv_paise)->toBe(120_000)
        ->and($cf->power_side)->toBe('R');
});

it('leaves a pending personal-BV top-up pending on a forfeited day', function (): void {
    // The top-up is consumed by a match. A forfeited day never matches, so the
    // pending BV must survive for the day the distributor fulfils.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    DB::table('settings')->updateOrInsert(
        ['key' => 'comp.gsb.topup_golive_date'],
        ['value' => '2026-01-01'],
    );
    $dist = makeLateFulfiller();
    seedDayBv($dist->id, '2026-02-06', 2_000_000, 1_600_000);

    app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::parse('2026-02-06'));

    expect(DB::table('gsb_personal_bv_topups')->where('distributor_id', $dist->id)->count())->toBe(0);
});

it('resumes on the fulfilment day: day BV + preserved carry-forward matches and credits', function (): void {
    // Client answer 3: "on the day the repurchase condition is satisfied, the
    // BVs from that day will be credited to the old ones". The preserved 4,000
    // BV slab-1 store is exactly what lifts the fulfilment day to the 15K match.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    gsbSuppressTopup();
    $dist = makeLateFulfiller();

    GsbCarryforward::create([
        'distributor_id' => $dist->id,
        'power_side_bv_paise' => 1_000_000, 'power_side' => 'L', 'slab1_weaker_bv_paise' => 400_000,
    ]);
    seedDayBv($dist->id, '2026-02-06', 500_000, 500_000);   // forfeited
    seedDayBv($dist->id, '2026-02-10', 1_600_000, 1_100_000); // fulfilment day

    $gsb = app(GsbCutoffService::class);

    expect($gsb->runForDistributor($dist->id, Carbon::parse('2026-02-06'))->status)
        ->toBe(GsbCutoffResult::STATUS_REPURCHASE_FORFEITED);

    $resumed = $gsb->runForDistributor($dist->id, Carbon::parse('2026-02-10'));

    // Left 16,000 + 10,000 power carry = 26,000; Right 11,000 weaker + the
    // preserved 4,000 slab-1 store = 15,000 = the slab-1 threshold exactly.
    expect($resumed->status)->toBe(GsbCutoffResult::STATUS_CREDITED)
        ->and($resumed->slab)->toBe(1)
        ->and($resumed->slab1_weaker_cf_before_paise)->toBe(400_000)
        ->and($resumed->weaker_bv_paise)->toBe(1_500_000)
        ->and($resumed->gross_gsb_paise)->toBeGreaterThan(0);

    expect(app(WalletService::class)->balancePaise($dist->id))->toBeGreaterThan(0);
});

it('a forfeited computation is not matched so it does not fund the day pool', function (): void {
    // GsbDailyCutoffCommand funds the day's pool from isMatched() computations
    // only. A forfeited day carries no slab and no score, so it contributes
    // neither fixed payout nor variable score to the frozen pool.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    gsbSuppressTopup();
    $dist = makeLateFulfiller();
    seedDayBv($dist->id, '2026-02-06', 2_000_000, 1_600_000);

    $computation = app(GsbCutoffService::class)->computeForDistributor($dist->id, Carbon::parse('2026-02-06'));

    expect($computation->outcome)->toBe(GsbCutoffComputation::OUTCOME_REPURCHASE_FORFEITED)
        ->and($computation->isMatched())->toBeFalse()
        ->and($computation->slabIndex)->toBeNull()
        ->and($computation->slabScore)->toBeNull()
        ->and($computation->fixedSlabGrossPaise())->toBe(0);
});

it('re-running a forfeited day is idempotent and never rewinds the store', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    gsbSuppressTopup();
    $dist = makeLateFulfiller();

    GsbCarryforward::create([
        'distributor_id' => $dist->id,
        'power_side_bv_paise' => 800_000, 'power_side' => 'L', 'slab1_weaker_bv_paise' => 500_000,
    ]);
    seedDayBv($dist->id, '2026-02-06', 2_000_000, 1_600_000);

    $gsb = app(GsbCutoffService::class);
    $gsb->runForDistributor($dist->id, Carbon::parse('2026-02-06'));
    $second = $gsb->runForDistributor($dist->id, Carbon::parse('2026-02-06'));

    expect(GsbCutoffResult::where('distributor_id', $dist->id)->count())->toBe(1)
        ->and($second->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_FORFEITED)
        ->and($second->power_cf_before_paise)->toBe(800_000)
        ->and($second->power_cf_after_paise)->toBe(800_000)
        ->and($second->slab1_weaker_cf_before_paise)->toBe(500_000)
        ->and($second->slab1_weaker_cf_after_paise)->toBe(500_000);

    $cf = GsbCarryforward::where('distributor_id', $dist->id)->first();
    expect($cf->power_side_bv_paise)->toBe(800_000)
        ->and($cf->slab1_weaker_bv_paise)->toBe(500_000);
});

it('refuses to re-run a forfeited day as eligible once a later cut-off advanced the store', function (): void {
    // A forfeited row deliberately left the store alone, so the store now holds
    // the LATER day's advance. Re-running the forfeited day as eligible would
    // measure it against that inflated baseline.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    gsbSuppressTopup();
    $dist = makeLateFulfiller();

    seedDayBv($dist->id, '2026-02-06', 500_000, 300_000);
    seedDayBv($dist->id, '2026-02-10', 700_000, 400_000);

    $gsb = app(GsbCutoffService::class);
    $gsb->runForDistributor($dist->id, Carbon::parse('2026-02-06'));
    $gsb->runForDistributor($dist->id, Carbon::parse('2026-02-10'));

    // The cycle is re-resolved as fulfilled the day after it was due: 6 Feb is
    // no longer forfeited, and the re-run must refuse rather than guess.
    RepurchaseCycle::where('distributor_id', $dist->id)->update(['fulfilled_on' => '2026-02-05']);

    expect(fn () => $gsb->runForDistributor($dist->id, Carbon::parse('2026-02-06')))
        ->toThrow(RuntimeException::class, 'a later cut-off already advanced the carry-forward store');
});

it('frozen wins over forfeited', function (): void {
    // An operator freeze already calculates without crediting and deliberately
    // advances the store; the repurchase verdict must not take that branch over.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    gsbSuppressTopup();
    $dist = makeLateFulfiller();
    $dist->update(['gsb_frozen_at' => '2026-02-01 00:00:00']);
    seedDayBv($dist->id, '2026-02-06', 2_000_000, 1_600_000);

    $result = app(GsbCutoffService::class)->runForDistributor($dist->id, Carbon::parse('2026-02-06'));

    expect($result->status)->toBe(GsbCutoffResult::STATUS_FROZEN)
        ->and($result->slab)->toBe(1)
        ->and($result->gross_gsb_paise)->toBeGreaterThan(0);

    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(0);
});

it('rewinds the store when a day that settled no_match is re-run as forfeited', function (): void {
    // The cycle was re-resolved behind an already-settled day. The no_match run
    // advanced the store with that day's BV; the forfeit says it was never
    // added. The row asserts after == before, so the store must agree with it.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    gsbSuppressTopup();
    $dist = makeLateFulfiller();

    GsbCarryforward::create([
        'distributor_id' => $dist->id,
        'power_side_bv_paise' => 300_000, 'power_side' => 'L', 'slab1_weaker_bv_paise' => 200_000,
    ]);
    seedDayBv($dist->id, '2026-02-06', 500_000, 100_000);

    // First run: the cycle still looked fulfilled, so the day counted.
    RepurchaseCycle::where('distributor_id', $dist->id)->update(['fulfilled_on' => '2026-02-05']);
    $gsb = app(GsbCutoffService::class);
    expect($gsb->runForDistributor($dist->id, Carbon::parse('2026-02-06'))->status)
        ->toBe(GsbCutoffResult::STATUS_NO_MATCH);

    $advanced = GsbCarryforward::where('distributor_id', $dist->id)->first();
    expect($advanced->power_side_bv_paise)->toBe(800_000)   // 5,000 BV + the 3,000 BV carry
        ->and($advanced->slab1_weaker_bv_paise)->toBe(300_000); // 1,000 BV + the 2,000 BV store

    // The cycle is re-resolved: 6 Feb is now inside a failed window.
    RepurchaseCycle::where('distributor_id', $dist->id)->update(['fulfilled_on' => '2026-02-10']);

    $result = $gsb->runForDistributor($dist->id, Carbon::parse('2026-02-06'));

    expect($result->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_FORFEITED)
        ->and($result->power_cf_before_paise)->toBe(300_000)
        ->and($result->power_cf_after_paise)->toBe(300_000)
        ->and($result->slab1_weaker_cf_before_paise)->toBe(200_000)
        ->and($result->slab1_weaker_cf_after_paise)->toBe(200_000);

    // The store is back at the pre-day state the row claims.
    $cf = GsbCarryforward::where('distributor_id', $dist->id)->first();
    expect($cf->power_side_bv_paise)->toBe(300_000)
        ->and($cf->power_side)->toBe('L')
        ->and($cf->slab1_weaker_bv_paise)->toBe(200_000);
});

it('re-running an already-forfeited day keeps its own before/after snapshot, not a later day state', function (): void {
    // A forfeited row never advanced the store, so a later day's advance is
    // what the store now holds. Re-reading it here would rewrite this date's
    // audit columns with another day's numbers. No money moves; the row must
    // simply not lie.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    gsbSuppressTopup();
    $dist = makeLateFulfiller();

    GsbCarryforward::create([
        'distributor_id' => $dist->id,
        'power_side_bv_paise' => 300_000, 'power_side' => 'L', 'slab1_weaker_bv_paise' => 200_000,
    ]);
    seedDayBv($dist->id, '2026-02-06', 500_000, 100_000);
    seedDayBv($dist->id, '2026-02-10', 900_000, 400_000);

    $gsb = app(GsbCutoffService::class);
    $gsb->runForDistributor($dist->id, Carbon::parse('2026-02-06')); // forfeited
    $gsb->runForDistributor($dist->id, Carbon::parse('2026-02-10')); // counts, advances the store

    $moved = GsbCarryforward::where('distributor_id', $dist->id)->first();
    expect($moved->power_side_bv_paise)->toBeGreaterThan(300_000);

    $rerun = $gsb->runForDistributor($dist->id, Carbon::parse('2026-02-06'));

    expect($rerun->status)->toBe(GsbCutoffResult::STATUS_REPURCHASE_FORFEITED)
        ->and($rerun->power_cf_before_paise)->toBe(300_000)
        ->and($rerun->power_cf_after_paise)->toBe(300_000)
        ->and($rerun->power_side_before)->toBe('L')
        ->and($rerun->power_side_after)->toBe('L')
        ->and($rerun->slab1_weaker_cf_before_paise)->toBe(200_000)
        ->and($rerun->slab1_weaker_cf_after_paise)->toBe(200_000);

    // And the later day's advance is untouched — the re-run wrote no store.
    expect(GsbCarryforward::where('distributor_id', $dist->id)->first()->power_side_bv_paise)
        ->toBe($moved->power_side_bv_paise);
});
