<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Jobs\DispatchRazorpayPayoutsJob;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/** Set a plan/settings scalar so the payout batch reads it (overrides the registry default). */
function setPayoutSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => $key],
        ['value' => $value, 'version' => 1, 'updated_at' => now()],
    );
}

/**
 * The last day of the Wednesday→Tuesday earning week the weekly batch dated
 * `$batchDate` pays. Group A income has to be earned on or before it to go out
 * in that batch, so every Group A credit in this file is stamped from here
 * rather than from a hand-rolled `subDays(7)`.
 */
function earnedForBatch(?Carbon $batchDate = null): Carbon
{
    return PayoutBatch::weeklyEarningWindow($batchDate ?? Carbon::today())['end'];
}

/**
 * Create a distributor with sufficient personal BV to pass the Retailer gate
 * (3,000 BV = 300,000 paise) and receive NEFT payouts.
 */
function makePayoutEligibleDistributor(): Distributor
{
    $dist = Distributor::factory()->create();
    BvLedgerEntry::create([
        'distributor_id' => $dist->id,
        'order_id' => 900_000 + $dist->id,  // unique per distributor
        'bv_paise' => 300_000,               // exactly 3,000 BV — Retailer threshold
        'type' => 'accrual',
        'effective_at' => now(),
    ]);

    return $dist;
}

it('generates a PENDING batch after run — wallets debited, awaiting admin approval', function () {
    $dist = makePayoutEligibleDistributor();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch()); // ₹1,000

    $svc = app(PayoutService::class);
    $batch = $svc->runWeeklyBatch(Carbon::today());

    // Batch is PENDING (not yet approved by admin).
    expect($batch->status)->toBe(PayoutBatch::STATUS_PENDING);
    expect($batch->processed_at)->not->toBeNull();
    expect($batch->distributor_count)->toBe(1);

    // Line item is PENDING (awaiting NEFT confirmation).
    // ₹1,000 gross → admin 3% ₹30 → payable ₹970 → TDS 5% ₹48.50 → net ₹921.50.
    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_PENDING);
    expect($line->net_transferred_paise)->toBe(92_150);

    // Wallet IS debited immediately during generation to prevent double-spend.
    expect($walletSvc->balancePaise($dist->id))->toBe(0);
});

it('approve() in manual NEFT mode holds the batch at APPROVED and leaves line items PENDING', function () {
    // Approval is finance signing the amount off; it is not the bank moving
    // money. Marking a line transferred here would put an unpaid amount on the
    // distributor's Total Withdrawal Income and tax statement.
    setPayoutSetting('payout.gateway', 'manual_neft');

    $admin = User::factory()->create();
    $dist = makePayoutEligibleDistributor();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch());

    $svc = app(PayoutService::class);
    $batch = $svc->runWeeklyBatch(Carbon::today());

    $approved = $svc->approve($batch, $admin->id);

    expect($approved->status)->toBe(PayoutBatch::STATUS_APPROVED);
    expect($approved->approved_by)->toBe($admin->id);
    expect($approved->approved_at)->not->toBeNull();

    // Settled only by the bank response import.
    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_PENDING);
});

it('approve() in Razorpay mode marks the batch DISPATCHED and queues the dispatch job', function () {
    setPayoutSetting('payout.gateway', 'razorpay');
    Queue::fake();

    $admin = User::factory()->create();
    $dist = makePayoutEligibleDistributor();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch());

    $svc = app(PayoutService::class);
    $batch = $svc->runWeeklyBatch(Carbon::today());

    $approved = $svc->approve($batch, $admin->id);

    expect($approved->status)->toBe(PayoutBatch::STATUS_DISPATCHED);
    Queue::assertPushed(DispatchRazorpayPayoutsJob::class);

    // Still pending: the payout webhook, not the approval, marks it transferred.
    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_PENDING);
});

it('a re-run never appends line items to an already-approved batch', function () {
    setPayoutSetting('payout.gateway', 'manual_neft');

    $admin = User::factory()->create();
    $first = makePayoutEligibleDistributor();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($first->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch());

    $svc = app(PayoutService::class);
    $batch = $svc->runWeeklyBatch(Carbon::today());
    $svc->approve($batch, $admin->id);

    // A second distributor earns after the batch was signed off. Re-running the
    // same date must not slip them into an amount finance already approved.
    $second = makePayoutEligibleDistributor();
    $walletSvc->credit($second->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch());

    $svc->runWeeklyBatch(Carbon::today());

    expect(PayoutLineItem::where('payout_batch_id', $batch->id)->count())->toBe(1);
    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_APPROVED);
});

it('skips wallet below minimum payout threshold', function () {
    $dist = makePayoutEligibleDistributor();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 8_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch()); // ₹80 — net ₹73.72, below ₹100 minimum (KP)

    $svc = app(PayoutService::class);
    $batch = $svc->runWeeklyBatch(Carbon::today());

    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_BELOW_MINIMUM);

    // Wallet still has the balance (no debit for below-minimum).
    expect($walletSvc->balancePaise($dist->id))->toBe(8_000);
});

it('is idempotent — running twice returns the same batch without double-debiting', function () {
    $dist = makePayoutEligibleDistributor();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch());

    $svc = app(PayoutService::class);
    $batch1 = $svc->runWeeklyBatch(Carbon::today());
    $batch2 = $svc->runWeeklyBatch(Carbon::today());

    expect($batch1->id)->toBe($batch2->id);
    expect(PayoutBatch::count())->toBe(1);
    // Wallet only debited once.
    expect($walletSvc->balancePaise($dist->id))->toBe(0);
    // Only one line item created.
    expect(PayoutLineItem::where('distributor_id', $dist->id)->count())->toBe(1);
});

it('weekly batch: sweeps repurchase_transfer entries and reports deduction in line item', function () {
    // Repurchase is now deducted at credit time via creditWithRepurchaseDeduction().
    // The payout batch reads the unswept repurchase_transfer debits and reports them
    // as repurchase_deduction_paise; payout_debit = effectiveGross (post-deduction).
    $dist = makePayoutEligibleDistributor();
    $walletSvc = app(WalletService::class);

    // ₹2,000 GSB: 10% = 20,000 paise deducted at credit time.
    // Main wallet after: 180,000 paise. Repurchase wallet: 20,000 paise.
    $walletSvc->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 200_000,
        bonusType: 'gsb_credit',
        referenceId: walletRef(),
        referenceType: 'gsb_cutoff_result',
        bonusMonth: now()->startOfMonth(),
        earnedOn: earnedForBatch(),
    );

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::today());

    // gross_paise = full bonus; repurchase_deduction_paise from the credit-time transfer;
    // wallet_balance_paise = effectiveGross = gross − repurchase = 180,000.
    // admin 3% of gsbEffective (200,000) = 6,000; payable = 174,000; TDS = 8,700; net = 165,300.
    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_PENDING);
    expect($line->gross_paise)->toBe(200_000);
    expect($line->repurchase_deduction_paise)->toBe(20_000);
    expect($line->wallet_balance_paise)->toBe(180_000);
    expect($line->net_transferred_paise)->toBe(165_300);

    // Main wallet is fully swept to zero; repurchase wallet retains 20,000.
    expect($walletSvc->balancePaise($dist->id))->toBe(0);
    expect($walletSvc->repurchaseWalletBalancePaise($dist->id))->toBe(20_000);
});

it('weekly batch: no repurchase deduction when plain credit() is used (no repurchase_transfer entries)', function () {
    // A plain credit() call (e.g. manual_credit, awards_credit) writes no
    // repurchase_transfer debit, so the payout reports zero deduction and
    // sweeps the full gross.
    $dist = makePayoutEligibleDistributor();
    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch());

    app(PayoutService::class)->runWeeklyBatch(Carbon::today());

    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->repurchase_deduction_paise)->toBe(0);
    expect($line->net_transferred_paise)->toBe(92_150);
    expect($walletSvc->balancePaise($dist->id))->toBe(0);
});

it('skips distributors with zero wallet balance', function () {
    $dist = makePayoutEligibleDistributor();

    $svc = app(PayoutService::class);
    $batch = $svc->runWeeklyBatch(Carbon::today());

    expect($batch->distributor_count)->toBe(0);
    expect(PayoutLineItem::where('distributor_id', $dist->id)->count())->toBe(0);
});

it('accumulates totals correctly across multiple distributors', function () {
    $dist1 = makePayoutEligibleDistributor();
    $dist2 = makePayoutEligibleDistributor();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist1->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch());
    $walletSvc->credit($dist2->id, 200_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch());

    $svc = app(PayoutService::class);
    $batch = $svc->runWeeklyBatch(Carbon::today());

    // d1: 100,000 → net 92,150; d2: 200,000 → admin 6,000 → payable 194,000
    // → TDS 9,700 → net 184,300. Totals: gross 300,000, net 276,450.
    expect($batch->distributor_count)->toBe(2);
    expect($batch->total_gross_paise)->toBe(300_000);
    expect($batch->total_net_paise)->toBe(276_450);
    expect($batch->status)->toBe(PayoutBatch::STATUS_PENDING);
});

it('marks web_only for distributor with personal BV below 3,000 BV Retailer threshold', function () {
    // Distributor with only 2,999 BV — below Retailer title, NEFT blocked.
    $dist = Distributor::factory()->create();
    BvLedgerEntry::create([
        'distributor_id' => $dist->id,
        'order_id' => 800_000 + $dist->id,
        'bv_paise' => 299_900,   // 2,999 BV — one BV below the threshold
        'type' => 'accrual',
        'effective_at' => now(),
    ]);

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch());

    $svc = app(PayoutService::class);
    $svc->runWeeklyBatch(Carbon::today());

    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_WEB_ONLY);
    expect($line->net_transferred_paise)->toBe(0);

    // Wallet balance is NOT debited — balance stays available in back-office.
    expect($walletSvc->balancePaise($dist->id))->toBe(100_000);
});

it('weekly batch: admin charge covers every Group-A stream when all toggles are ON (default)', function () {
    $dist = makePayoutEligibleDistributor();

    $wallet = app(WalletService::class);
    $wallet->credit($dist->id, 1_000_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch(Carbon::create(2026, 7, 21))); // ₹10,000
    $wallet->credit($dist->id, 1_000_000, 'mb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch(Carbon::create(2026, 7, 21)));  // ₹10,000

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 21));

    $line = PayoutLineItem::where('distributor_id', $dist->id)->where('payout_batch_id', $batch->id)->first();
    // 3% of the full ₹20,000 Group-A gross = 60,000 paise.
    expect($line->admin_charge_paise)->toBe(60_000);
});

it('weekly batch: admin charge skips a Group-A stream whose applies_to toggle is OFF', function () {
    $dist = makePayoutEligibleDistributor();

    $wallet = app(WalletService::class);
    $wallet->credit($dist->id, 1_000_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch(Carbon::create(2026, 7, 21))); // ₹10,000
    $wallet->credit($dist->id, 1_000_000, 'mb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch(Carbon::create(2026, 7, 21)));  // ₹10,000

    // Exempt Mentorship from the admin charge — the toggle must take effect.
    setPayoutSetting('comp.admin_charge.applies_to_mb', 'false');

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 21));

    $line = PayoutLineItem::where('distributor_id', $dist->id)->where('payout_batch_id', $batch->id)->first();
    // 3% of GSB only (₹10,000) = 30,000; the ₹10,000 of MB is exempt.
    expect($line->admin_charge_paise)->toBe(30_000);
    // Gross is unchanged — the exemption only lowers the admin charge, not the payout base.
    expect($line->gross_paise)->toBe(2_000_000);
});

it('monthly batch: Group-B admin charge excludes a stream whose applies_to toggle is OFF', function () {
    $dist = makePayoutEligibleDistributor();

    $wallet = app(WalletService::class);
    $wallet->credit($dist->id, 1_000_000, 'gbb_credit', walletRef(), 'test_reference');     // ₹10,000
    $wallet->credit($dist->id, 1_000_000, 'rank_credit', walletRef(), 'test_reference');    // ₹10,000
    $wallet->credit($dist->id, 1_000_000, 'fortune_credit', walletRef(), 'test_reference'); // ₹10,000

    setPayoutSetting('comp.admin_charge.applies_to_fortune', 'false');

    $batch = app(PayoutService::class)->runMonthlyBatch(Carbon::create(2026, 7, 1));

    $line = PayoutLineItem::where('distributor_id', $dist->id)->where('payout_batch_id', $batch->id)->first();
    // Group B = GBB + Rank + Fortune, but Fortune is exempt → 3% of ₹20,000 = 60,000.
    expect($line->admin_charge_paise)->toBe(60_000);
});

it('weekly batches: each credit\'s repurchase_transfer is swept exactly once across multiple runs', function () {
    // Each creditWithRepurchaseDeduction() call writes its own repurchase_transfer
    // debit. A batch sweeps (marks swept_by_payout_batch_id) those entries exactly
    // once; the next batch finds no unswept transfers for the previous credits.
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);

    // First credit: ₹2,000 → repurchase_transfer -20,000.
    Carbon::setTestNow(Carbon::create(2026, 7, 7, 9));
    $wallet->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 200_000,
        bonusType: 'gsb_credit',
        referenceId: walletRef(),
        referenceType: 'gsb_cutoff_result',
        bonusMonth: now()->startOfMonth(),
        earnedOn: earnedForBatch(Carbon::create(2026, 7, 14)),
    );
    $b1 = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));
    $l1 = PayoutLineItem::where('payout_batch_id', $b1->id)->where('distributor_id', $dist->id)->first();
    // Batch 1 sweeps the ₹2,000 credit's transfer → 20,000 deduction.
    expect($l1->repurchase_deduction_paise)->toBe(20_000);

    // Second credit: ₹1,000 → repurchase_transfer -10,000.
    Carbon::setTestNow(Carbon::create(2026, 7, 14, 9));
    $wallet->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 100_000,
        bonusType: 'gsb_credit',
        referenceId: walletRef(),
        referenceType: 'gsb_cutoff_result',
        bonusMonth: now()->startOfMonth(),
        earnedOn: earnedForBatch(Carbon::create(2026, 7, 21)),
    );
    $b2 = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 21));
    $l2 = PayoutLineItem::where('payout_batch_id', $b2->id)->where('distributor_id', $dist->id)->first();
    // Batch 2 sweeps only the NEW credit's transfer (first is already swept) → 10,000 only.
    expect($l2->repurchase_deduction_paise)->toBe(10_000);

    Carbon::setTestNow(null);
});

it('monthly batch: rank credits above the income cap are forfeited with a ledger debit, not stranded', function () {
    $dist = makePayoutEligibleDistributor();
    setPayoutSetting('comp.monthly_income_cap_paise', '500000'); // ₹5,000 cap

    $wallet = app(WalletService::class);
    $wallet->credit($dist->id, 800_000, 'rank_credit', walletRef(), 'test_reference'); // ₹8,000 — ₹3,000 above cap

    $batch = app(PayoutService::class)->runMonthlyBatch(Carbon::create(2026, 7, 1));

    $line = PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $dist->id)->first();
    expect($line->gross_paise)->toBe(500_000);

    // The above-cap excess is explicitly debited as a forfeit…
    $forfeit = WalletLedgerEntry::where('distributor_id', $dist->id)
        ->where('type', 'income_cap_forfeit')
        ->first();
    expect($forfeit)->not->toBeNull();
    expect($forfeit->amount_paise)->toBe(-300_000);

    // …so the wallet nets to zero instead of showing a phantom ₹3,000 forever.
    expect($wallet->balancePaise($dist->id))->toBe(0);
});

it('weekly batch: crash-resume neither duplicates line items nor loses batch totals', function () {
    $d1 = makePayoutEligibleDistributor();
    $d2 = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);
    $wallet->credit($d1->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch(Carbon::create(2026, 7, 21)));
    $wallet->credit($d2->id, 200_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch(Carbon::create(2026, 7, 21)));

    // Simulate a run that crashed after fully committing d1 (line item written,
    // entries swept, wallet debited) but before batch totals were finalized.
    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => Carbon::create(2026, 7, 21)->toDateString(),
        'status' => PayoutBatch::STATUS_PENDING,
    ]);
    WalletLedgerEntry::where('distributor_id', $d1->id)
        ->where('type', 'gsb_credit')
        ->update(['swept_by_payout_batch_id' => $batch->id]);
    $preCrashLine = PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => $d1->id,
        'wallet_balance_paise' => 100_000,
        'gross_paise' => 100_000,
        'repurchase_deduction_paise' => 0,
        'admin_charge_paise' => 3_000,
        'tds_paise' => 4_850,
        'net_transferred_paise' => 92_150,
        'status' => PayoutLineItem::STATUS_PENDING,
    ]);
    $wallet->debit($d1->id, 100_000, 'payout_debit', $preCrashLine->id, 'payout_line_item');

    $resumed = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 21));

    expect($resumed->id)->toBe($batch->id);
    // d1 keeps exactly one line item; d2 got processed on the resume.
    expect(PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $d1->id)->count())->toBe(1);
    expect(PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $d2->id)->count())->toBe(1);
    // Totals cover the WHOLE batch (pre-crash d1 + resumed d2), not just d2.
    expect($resumed->distributor_count)->toBe(2);
    expect($resumed->total_gross_paise)->toBe(300_000);
});

it('monthly batch: a fully-exempt group is charged nothing', function () {
    $dist = makePayoutEligibleDistributor();

    $wallet = app(WalletService::class);
    $wallet->credit($dist->id, 1_000_000, 'adc_credit', walletRef(), 'test_reference'); // ₹10,000 — Group D

    setPayoutSetting('comp.admin_charge.applies_to_adc', 'false');

    $batch = app(PayoutService::class)->runMonthlyBatch(Carbon::create(2026, 7, 1));

    $line = PayoutLineItem::where('distributor_id', $dist->id)->where('payout_batch_id', $batch->id)->first();
    expect($line->admin_charge_paise)->toBe(0);
});

it('weekly batch: holds payout as no_bank_account when no bank details are on file', function () {
    $dist = makePayoutEligibleDistributor();
    $dist->update(['bank_account_enc' => null]);  // skipped the optional bank step

    // Pin both batches inside one calendar month: run this near month-end and
    // "+1 week" lands in the next month, where the ₹1,000 becomes prior-month
    // income and the 10% repurchase deduction breaks the balance assertion.
    $batchDate = Carbon::today()->startOfMonth()->addDays(7);

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch($batchDate)); // ₹1,000

    $svc = app(PayoutService::class);
    $svc->runWeeklyBatch($batchDate);

    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_NO_BANK_ACCOUNT);
    expect($line->gross_paise)->toBe(100_000);
    expect($line->net_transferred_paise)->toBe(0);

    // Balance held: no debit, credits not swept.
    expect($walletSvc->balancePaise($dist->id))->toBe(100_000);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->whereNull('swept_by_payout_batch_id')->count())->toBe(1);

    // The first batch after bank details arrive pays it out.
    $dist->update(['bank_account_enc' => 'stub']);
    $next = $svc->runWeeklyBatch($batchDate->copy()->addWeek());
    $paid = PayoutLineItem::where('payout_batch_id', $next->id)->where('distributor_id', $dist->id)->first();
    expect($paid->status)->toBe(PayoutLineItem::STATUS_PENDING);
    expect($walletSvc->balancePaise($dist->id))->toBe(0);
});

it('weekly batch: GSB above the monthly income cap is trimmed and forfeited', function () {
    $dist = makePayoutEligibleDistributor();
    setPayoutSetting('comp.monthly_income_cap_paise', '500000'); // ₹5,000 cap

    $wallet = app(WalletService::class);
    $wallet->credit($dist->id, 800_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch(Carbon::create(2026, 7, 14))); // ₹8,000 — ₹3,000 above cap

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));

    $line = PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $dist->id)->first();
    expect($line->gross_paise)->toBe(500_000);

    $forfeit = WalletLedgerEntry::where('distributor_id', $dist->id)
        ->where('type', 'income_cap_forfeit')
        ->first();
    expect($forfeit->amount_paise)->toBe(-300_000);
    expect($wallet->balancePaise($dist->id))->toBe(0);
});

it('monthly income cap is shared across all five cash bonuses and across batches', function () {
    $dist = makePayoutEligibleDistributor();
    setPayoutSetting('comp.monthly_income_cap_paise', '500000'); // ₹5,000 cap

    $wallet = app(WalletService::class);

    // Weekly batch consumes ₹3,000 of the month's ₹5,000 room.
    $wallet->credit($dist->id, 300_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch(Carbon::create(2026, 7, 14)));
    app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));

    // Monthly batch in the same month: rank ₹4,000 against ₹2,000 remaining room.
    $wallet->credit($dist->id, 400_000, 'rank_credit', walletRef(), 'test_reference');
    $monthly = app(PayoutService::class)->runMonthlyBatch(Carbon::create(2026, 7, 1));

    $line = PayoutLineItem::where('payout_batch_id', $monthly->id)->where('distributor_id', $dist->id)->first();
    expect($line->gross_paise)->toBe(200_000);

    $forfeit = WalletLedgerEntry::where('distributor_id', $dist->id)
        ->where('type', 'income_cap_forfeit')
        ->first();
    expect($forfeit->amount_paise)->toBe(-200_000);
    expect($wallet->balancePaise($dist->id))->toBe(0);
});

// ── KYC gate (partner 2026-07-08: hold payouts until KYC verified) ───────────

it('holds the weekly payout as kyc_pending when the distributor KYC is not verified', function () {
    $dist = makePayoutEligibleDistributor();       // active + bank on file by factory
    $dist->user->update(['status' => 'pending']);  // KYC not yet approved
    app(WalletService::class)->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch()); // ₹1,000

    app(PayoutService::class)->runWeeklyBatch(Carbon::today());

    $line = PayoutLineItem::where('distributor_id', $dist->id)->firstOrFail();
    expect($line->status)->toBe(PayoutLineItem::STATUS_KYC_PENDING);
    expect($line->net_transferred_paise)->toBe(0);
    // Balance is HELD — never debited or swept, so the next batch after KYC pays it.
    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(100_000);
});

it('holds the monthly payout as kyc_pending when the distributor KYC is not verified', function () {
    $dist = makePayoutEligibleDistributor();
    $dist->user->update(['status' => 'pending']);
    app(WalletService::class)->credit($dist->id, 500_000, 'rank_credit', walletRef(), 'test_reference'); // ₹5,000 monthly stream

    app(PayoutService::class)->runMonthlyBatch(Carbon::today());

    $line = PayoutLineItem::where('distributor_id', $dist->id)->firstOrFail();
    expect($line->status)->toBe(PayoutLineItem::STATUS_KYC_PENDING);
    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(500_000);
});

it('pays the held balance once KYC is verified on a later batch', function () {
    $dist = makePayoutEligibleDistributor();
    $dist->user->update(['status' => 'pending']);
    app(WalletService::class)->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch());

    // First batch while unverified → held.
    app(PayoutService::class)->runWeeklyBatch(Carbon::today());
    expect(PayoutLineItem::where('distributor_id', $dist->id)->first()->status)
        ->toBe(PayoutLineItem::STATUS_KYC_PENDING);

    // KYC approved → user active. Next batch pays it out (nothing was swept).
    $dist->user->update(['status' => 'active']);
    app(PayoutService::class)->runWeeklyBatch(Carbon::today()->addWeek());

    $paid = PayoutLineItem::where('distributor_id', $dist->id)
        ->where('status', PayoutLineItem::STATUS_PENDING)->first();
    expect($paid)->not->toBeNull();
    expect($paid->gross_paise)->toBe(100_000);
});

// ── Repurchase deduction in the MONTHLY batch ────────────────────────────────

it('monthly batch: sweeps repurchase_transfer entries for gbb/rank/fortune credits', function () {
    // creditWithRepurchaseDeduction() writes a repurchase_transfer debit at credit
    // time. The monthly batch reads unswept repurchase_transfer entries for the
    // MONTHLY_REPURCHASE_REF_TYPES and reports them as repurchase_deduction_paise.
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);

    // ₹2,000 GBB: 10% = 20,000 paise deducted at credit time.
    // Main wallet after: 180,000. Repurchase wallet: 20,000.
    $wallet->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 200_000,
        bonusType: 'gbb_credit',
        referenceId: walletRef(),
        referenceType: 'gbb_monthly_result',
        bonusMonth: now()->startOfMonth(),
    );

    $batch = app(PayoutService::class)->runMonthlyBatch(Carbon::create(2026, 7, 1));

    // gross = 200,000; repurchase = 20,000 (from credit-time transfer);
    // effectiveGross = 180,000; admin 3% of 200,000 = 6,000;
    // payable = 174,000; TDS = 8,700; net = 165,300.
    $line = PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_PENDING);
    expect($line->gross_paise)->toBe(200_000);
    expect($line->repurchase_deduction_paise)->toBe(20_000);
    expect($line->wallet_balance_paise)->toBe(180_000);
    expect($line->admin_charge_paise)->toBe(6_000);
    expect($line->tds_paise)->toBe(8_700);
    expect($line->net_transferred_paise)->toBe(165_300);

    // Main wallet fully swept; repurchase wallet retains credit-time deduction.
    expect($wallet->balancePaise($dist->id))->toBe(0);
    expect($wallet->repurchaseWalletBalancePaise($dist->id))->toBe(20_000);

    Carbon::setTestNow(null);
});

it('monthly batch: no repurchase deduction when no repurchase_transfer entries exist', function () {
    // Awards and ADC credits use plain credit() — no repurchase_transfer written —
    // so the monthly batch reports zero deduction for those income streams.
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);

    $wallet->credit($dist->id, 100_000, 'gbb_credit', walletRef(), 'test_reference');

    $batch = app(PayoutService::class)->runMonthlyBatch(Carbon::create(2026, 7, 1));

    $line = PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $dist->id)->first();
    expect($line->repurchase_deduction_paise)->toBe(0);
    expect($line->wallet_balance_paise)->toBe(100_000);
    // 100,000 − admin 3,000 = 97,000 → TDS 4,850 → net 92,150.
    expect($line->net_transferred_paise)->toBe(92_150);
    expect($wallet->balancePaise($dist->id))->toBe(0);

    Carbon::setTestNow(null);
});

it('monthly batch: GSB repurchase_transfer entries swept by weekly batch are not re-swept by monthly', function () {
    // The weekly batch marks repurchase_transfer entries as swept. The subsequent
    // monthly batch (for GBB/Rank/Fortune credits) only finds its own unswept
    // repurchase_transfer entries — it never re-sweeps the weekly-swept ones.
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);

    // Weekly credit: GSB ₹1,000 with credit-time repurchase_transfer of 10,000.
    Carbon::setTestNow(Carbon::create(2026, 7, 7, 9));
    $wallet->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 100_000,
        bonusType: 'gsb_credit',
        referenceId: walletRef(),
        referenceType: 'gsb_cutoff_result',
        bonusMonth: now()->startOfMonth(),
        earnedOn: earnedForBatch(Carbon::create(2026, 7, 14)),
    );
    $weekly = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));
    expect(PayoutLineItem::where('payout_batch_id', $weekly->id)->where('distributor_id', $dist->id)->first()->repurchase_deduction_paise)
        ->toBe(10_000);

    // Monthly credit: GBB ₹1,000 with credit-time repurchase_transfer of 10,000.
    Carbon::setTestNow(Carbon::create(2026, 7, 20, 9));
    $wallet->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 100_000,
        bonusType: 'gbb_credit',
        referenceId: walletRef(),
        referenceType: 'gbb_monthly_result',
        bonusMonth: now()->startOfMonth(),
    );
    $monthly = app(PayoutService::class)->runMonthlyBatch(Carbon::create(2026, 7, 1));

    $line = PayoutLineItem::where('payout_batch_id', $monthly->id)->where('distributor_id', $dist->id)->first();
    // Only the GBB repurchase_transfer is unswept → 10,000 (not 20,000).
    expect($line->repurchase_deduction_paise)->toBe(10_000);
    // effectiveGross = 90,000; admin 3% of 100,000 = 3,000; payable = 87,000; TDS = 4,350; net = 82,650.
    expect($line->net_transferred_paise)->toBe(82_650);
    expect($wallet->balancePaise($dist->id))->toBe(0);

    Carbon::setTestNow(null);
});

it('monthly batch: each income stream\'s repurchase_transfer is swept by its own batch type', function () {
    // Verifies the ref-type filter: WEEKLY_REPURCHASE_REF_TYPES covers gsb_cutoff_result;
    // MONTHLY_REPURCHASE_REF_TYPES covers gbb_monthly_result / rank_bonus_result /
    // fortune_bonus_result. Cross-batch contamination is impossible.
    setPayoutSetting('payout.min_threshold_paise', '0');

    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);

    // GBB credit with repurchase_transfer (ref type: gbb_monthly_result).
    $wallet->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 200_000,
        bonusType: 'gbb_credit',
        referenceId: walletRef(),
        referenceType: 'gbb_monthly_result',
        bonusMonth: now()->startOfMonth(),
    );
    // GSB credit with repurchase_transfer (ref type: gsb_cutoff_result).
    $wallet->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 100_000,
        bonusType: 'gsb_credit',
        referenceId: walletRef(),
        referenceType: 'gsb_cutoff_result',
        bonusMonth: now()->startOfMonth(),
        earnedOn: earnedForBatch(),
    );

    // Weekly batch sweeps only the gsb_cutoff_result repurchase_transfer.
    $weekly = app(PayoutService::class)->runWeeklyBatch(Carbon::today());
    $wl = PayoutLineItem::where('payout_batch_id', $weekly->id)->where('distributor_id', $dist->id)->first();
    expect($wl->repurchase_deduction_paise)->toBe(10_000); // 10% of 100,000 only

    // Monthly batch sweeps only the gbb_monthly_result repurchase_transfer.
    $monthly = app(PayoutService::class)->runMonthlyBatch(Carbon::today());
    $ml = PayoutLineItem::where('payout_batch_id', $monthly->id)->where('distributor_id', $dist->id)->first();
    expect($ml->repurchase_deduction_paise)->toBe(20_000); // 10% of 200,000 only

    expect($wallet->balancePaise($dist->id))->toBe(0);

    Carbon::setTestNow(null);
});

it('isolates a per-distributor failure: the rest are still paid and the batch lands partially_failed', function () {
    $ok = makePayoutEligibleDistributor();
    $bad = makePayoutEligibleDistributor();

    $wallet = app(WalletService::class);
    $wallet->credit($ok->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch());
    $wallet->credit($bad->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch());

    // Force exactly one distributor to throw inside the loop body.
    $state = new stdClass;
    $state->failing = true;
    PayoutLineItem::creating(function (PayoutLineItem $line) use ($state, $bad): void {
        if ($state->failing === true && $line->distributor_id === $bad->id) {
            throw new RuntimeException('simulated per-distributor failure');
        }
    });

    $svc = app(PayoutService::class);
    $batch = $svc->runWeeklyBatch(Carbon::today());

    // The healthy distributor was paid; the failing one wrote nothing.
    expect($batch->status)->toBe(PayoutBatch::STATUS_PARTIALLY_FAILED);
    expect(PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $ok->id)->exists())->toBeTrue();
    expect(PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $bad->id)->exists())->toBeFalse();
    expect($wallet->balancePaise($bad->id))->toBe(100_000);

    // Re-running the same batch date retries only the failed distributor and
    // promotes the batch back to pending.
    $state->failing = false;
    $rerun = $svc->runWeeklyBatch(Carbon::today());

    expect($rerun->id)->toBe($batch->id);
    expect($rerun->status)->toBe(PayoutBatch::STATUS_PENDING);
    expect($rerun->distributor_count)->toBe(2);
    expect(PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $ok->id)->count())->toBe(1);
    expect(PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $bad->id)->count())->toBe(1);
    expect($wallet->balancePaise($bad->id))->toBe(0);
});

it('LOG-3/5: writes audit_log rows when a payout batch is created and finalised, once each', function () {
    $dist = makePayoutEligibleDistributor();
    app(WalletService::class)->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch());

    $svc = app(PayoutService::class);
    $batch = $svc->runWeeklyBatch(Carbon::today());

    $created = AuditLog::where('action', 'payout.batch.created')
        ->where('subject_id', $batch->id);
    $finalised = AuditLog::where('action', 'payout.batch.finalised')
        ->where('subject_id', $batch->id);

    expect($created->count())->toBe(1)
        ->and($finalised->count())->toBe(1)
        ->and($created->first()->details['batch_type'])->toBe(PayoutBatch::TYPE_WEEKLY);

    // A crash-resume re-entry must not record a second creation.
    $svc->runWeeklyBatch(Carbon::today());

    expect(AuditLog::where('action', 'payout.batch.created')
        ->where('subject_id', $batch->id)->count())->toBe(1);
});

it('repurchase_deduction credits from cancelled orders do not affect payout sweep (R-60)', function () {
    // A cancelled order restores repurchase credit (type=repurchase_deduction,
    // reference_type=order) to the repurchase wallet. The payout batch now looks at
    // repurchase_transfer entries (not repurchase_deduction), so order-restoration
    // credits are invisible to it and cannot inflate or deflate the deduction.
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);

    // GSB credit with credit-time deduction: repurchase_transfer -10,000.
    Carbon::setTestNow(Carbon::create(2026, 7, 7, 9));
    $wallet->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 100_000,
        bonusType: 'gsb_credit',
        referenceId: walletRef(),
        referenceType: 'gsb_cutoff_result',
        bonusMonth: now()->startOfMonth(),
        earnedOn: earnedForBatch(Carbon::create(2026, 7, 14)),
    );

    // An order is cancelled: restores 8,000 to the repurchase wallet.
    // reference_type='order' — this must not appear as a payout-batch deduction.
    $wallet->credit(
        distributorId: $dist->id,
        amountPaise: 8_000,
        type: 'repurchase_deduction',
        referenceId: 4242,
        referenceType: 'order',
        memo: 'Restored on cancellation of order TEST-1',
    );

    $weekly = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));

    // Payout deduction = repurchase_transfer sweep only (10,000), not affected
    // by the order restoration credit.
    $line = PayoutLineItem::where('payout_batch_id', $weekly->id)->where('distributor_id', $dist->id)->first();
    expect($line->repurchase_deduction_paise)->toBe(10_000);

    // Main wallet: 0 (fully swept). Repurchase wallet: 10,000 (credit-time) + 8,000 (restoration).
    expect($wallet->balancePaise($dist->id))->toBe(0);
    expect($wallet->repurchaseWalletBalancePaise($dist->id))->toBe(18_000);

    Carbon::setTestNow(null);
});

it('weekly batch: writes the admin charge and TDS as their own ledger debits', function () {
    // The wallet statement has to be able to answer "where did the rest of my
    // money go?" — one payout_debit for the whole balance could not.
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);
    $wallet->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch()); // ₹1,000

    app(PayoutService::class)->runWeeklyBatch(Carbon::today());

    // admin 3% = 3,000; payable = 97,000; TDS 5% = 4,850; net = 92,150.
    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->admin_charge_paise)->toBe(3_000);
    expect($line->tds_paise)->toBe(4_850);
    expect($line->net_transferred_paise)->toBe(92_150);

    $debits = WalletLedgerEntry::where('distributor_id', $dist->id)
        ->where('amount_paise', '<', 0)
        ->pluck('amount_paise', 'type')
        ->map(fn ($paise): int => (int) $paise)
        ->all();

    expect($debits)->toBe([
        'admin_charge_debit' => -3_000,
        'tds_debit' => -4_850,
        'payout_debit' => -92_150,
    ]);

    // The three debits together remove exactly what was in the wallet.
    expect($wallet->balancePaise($dist->id))->toBe(0);
});

it('weekly batch: leaves the GSB cut-off result rows untouched — admin charge and TDS live only on the payout line', function () {
    // The engines freeze gross, the credit-time repurchase deduction and the
    // credited amount on the result row; the payout must not rewrite any of it,
    // or the bonus pages would show a figure that is neither what landed in
    // the wallet nor what reached the bank.
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);

    $resultId = walletRef();
    DB::table('gsb_cutoff_results')->insert([
        'id' => $resultId,
        'distributor_id' => $dist->id,
        'cutoff_date' => earnedForBatch()->toDateString(),
        'gross_gsb_paise' => 100_000,
        'repurchase_deduction_paise' => 10_000,
        'net_gsb_paise' => 90_000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $wallet->creditWithRepurchaseDeduction($dist->id, 100_000, 'gsb_credit', $resultId, 'gsb_cutoff_result', now()->startOfMonth(), earnedOn: earnedForBatch());

    app(PayoutService::class)->runWeeklyBatch(Carbon::today());

    $row = DB::table('gsb_cutoff_results')->find($resultId);
    expect((int) $row->admin_charge_paise)->toBe(0);
    expect((int) $row->tds_paise)->toBe(0);
    expect((int) $row->repurchase_deduction_paise)->toBe(10_000);
    expect((int) $row->net_gsb_paise)->toBe(90_000);

    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->admin_charge_paise)->toBeGreaterThan(0);
    expect($line->tds_paise)->toBeGreaterThan(0);
});

it('weekly batch: never charges more admin than the wallet actually holds', function () {
    // A ₹25,000-capped charge levied on a gross the repurchase deduction has
    // already eaten into must not push the payout negative.
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);
    setPayoutSetting('comp.admin_charge.rate_bp', '10000'); // 100% — forces the clamp

    $wallet->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 200_000,
        bonusType: 'gsb_credit',
        referenceId: walletRef(),
        referenceType: 'gsb_cutoff_result',
        bonusMonth: now()->startOfMonth(),
        earnedOn: earnedForBatch(),
    );

    app(PayoutService::class)->runWeeklyBatch(Carbon::today());

    // effectiveGross = 180,000; the charge is levied on the 200,000 gross, so
    // without the clamp the line item would claim 200,000 taken out of a wallet
    // holding 180,000 and net would go negative.
    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->admin_charge_paise)->toBe(180_000);
    expect($line->tds_paise)->toBe(0);
    expect($line->net_transferred_paise)->toBe(0);

    // Nothing left for the bank, so the line is held below the minimum and the
    // balance rolls over untouched rather than being swept for a zero payout.
    expect($line->status)->toBe(PayoutLineItem::STATUS_BELOW_MINIMUM);
    expect($wallet->balancePaise($dist->id))->toBe(180_000);
});

it('monthly batch: does not tax lifetime award cash a second time', function () {
    // Award cash reaches the wallet already net of the admin charge and 5% TDS
    // (AdminLifetimeAwardsController takes both at delivery), so the payout must
    // leave it out of the TDS base as well as the admin base.
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);
    $wallet->credit($dist->id, 100_000, 'awards_credit', walletRef(), 'lifetime_award_milestone');

    app(PayoutService::class)->runMonthlyBatch(Carbon::today()->startOfMonth());

    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->gross_paise)->toBe(100_000);
    expect($line->admin_charge_paise)->toBe(0);
    expect($line->tds_paise)->toBe(0);
    expect($line->net_transferred_paise)->toBe(100_000);
    expect($wallet->balancePaise($dist->id))->toBe(0);
});

it('weekly batch: a held line reports the credit-time repurchase deduction and the batch totals include held income', function () {
    $dist = makePayoutEligibleDistributor();
    $dist->update(['bank_account_enc' => null]);  // held as no_bank_account

    $walletSvc = app(WalletService::class);

    // ₹2,000 GSB: 10% = 20,000 paise already moved to the repurchase wallet at credit time.
    $walletSvc->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 200_000,
        bonusType: 'gsb_credit',
        referenceId: walletRef(),
        referenceType: 'gsb_cutoff_result',
        bonusMonth: now()->startOfMonth(),
        earnedOn: earnedForBatch(Carbon::today()->startOfMonth()->addDays(7)),
    );

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::today()->startOfMonth()->addDays(7));

    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_NO_BANK_ACCOUNT);
    expect($line->gross_paise)->toBe(200_000);
    expect($line->repurchase_deduction_paise)->toBe(20_000);
    expect($line->wallet_balance_paise)->toBe(180_000);  // what is actually still in the main wallet
    expect($line->net_transferred_paise)->toBe(0);

    // Nothing swept or debited while held.
    expect($walletSvc->balancePaise($dist->id))->toBe(180_000);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->whereNull('swept_by_payout_batch_id')->count())->toBe(3);

    // Gross and deductions cover every line item; count and net cover only what goes to the bank.
    $batch->refresh();
    expect($batch->total_gross_paise)->toBe(200_000);
    expect($batch->total_deductions_paise)->toBe(20_000);
    expect($batch->distributor_count)->toBe(0);
    expect($batch->total_net_paise)->toBe(0);
});

// ── Income cap windowed by the EARNED month (bonus_month) ────────────────────

it('forfeits — and records — a distributor with exactly ₹0 of cap room instead of paying them next month', function () {
    setPayoutSetting('comp.monthly_income_cap_paise', '500000'); // ₹5,000 cap
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);
    $july = Carbon::create(2026, 7, 1);

    // July's weekly batch consumes the whole ₹5,000 ceiling.
    $wallet->credit($dist->id, 500_000, 'gsb_credit', walletRef(), 'test_reference', null, $july, earnedOn: earnedForBatch(Carbon::create(2026, 7, 14)));
    app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));

    // A ₹3,000 rank bonus earned in the same month therefore has ₹0 of room.
    $wallet->credit($dist->id, 300_000, 'rank_credit', walletRef(), 'test_reference', null, $july);
    $monthly = app(PayoutService::class)->runMonthlyBatch($july);

    $line = PayoutLineItem::where('payout_batch_id', $monthly->id)->where('distributor_id', $dist->id)->first();
    expect($line)->not->toBeNull();
    expect($line->status)->toBe(PayoutLineItem::STATUS_INCOME_CAP_FORFEITED);
    expect($line->gross_paise)->toBe(300_000);
    expect($line->net_transferred_paise)->toBe(0);

    // Written off, not stranded: the credit is swept and the wallet closes to zero.
    $forfeit = WalletLedgerEntry::where('distributor_id', $dist->id)
        ->where('type', 'income_cap_forfeit')->first();
    expect($forfeit->amount_paise)->toBe(-300_000);
    expect($wallet->balancePaise($dist->id))->toBe(0);

    // The forfeit is attributed to the month it was MEASURED against, not the
    // month of the batch that wrote it off.
    expect($forfeit->bonus_month->toDateString())->toBe('2026-07-01');

    // Destroying income permanently is an audit fact with an 8-year retention.
    $audit = DB::table('audit_log')
        ->where('action', 'payout.income_cap_forfeited')
        ->where('subject_type', 'distributor')
        ->where('subject_id', $dist->id)
        ->sole();

    $details = json_decode((string) $audit->details, true);
    expect($details['payout_batch_id'])->toBe($monthly->id)
        ->and($details['payout_line_item_id'])->toBe($line->id)
        ->and($details['gross_paise'])->toBe(300_000)
        ->and($details['forfeited_paise'])->toBe(300_000)
        ->and($details['forfeited_by_earned_month'])->toBe(['2026-07-01' => 300_000]);

    // The old behaviour: next month's batch found the credit unswept and paid
    // it in full against a fresh ceiling. Nothing is left for it to find.
    $august = app(PayoutService::class)->runMonthlyBatch(Carbon::create(2026, 8, 1));
    expect(PayoutLineItem::where('payout_batch_id', $august->id)->where('distributor_id', $dist->id)->exists())->toBeFalse();
});

it('still rolls a below-minimum remainder over and still pays it on a later batch', function () {
    // The ₹100 deferral is intentional and must keep working: only the cap
    // stopped resetting, not the rollover.
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);

    $wallet->credit($dist->id, 8_000, 'gsb_credit', walletRef(), 'test_reference', null, Carbon::create(2026, 7, 1), earnedOn: earnedForBatch(Carbon::create(2026, 7, 14))); // ₹80 — net below ₹100
    app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));

    $held = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($held->status)->toBe(PayoutLineItem::STATUS_BELOW_MINIMUM);
    expect($wallet->balancePaise($dist->id))->toBe(8_000);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->whereNull('swept_by_payout_batch_id')->count())->toBe(1);

    // Next month it clears the floor alongside new income and goes out whole.
    $wallet->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', null, Carbon::create(2026, 8, 1), earnedOn: earnedForBatch(Carbon::create(2026, 8, 11)));
    $next = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 8, 11));

    $paid = PayoutLineItem::where('payout_batch_id', $next->id)->where('distributor_id', $dist->id)->first();
    expect($paid->status)->toBe(PayoutLineItem::STATUS_PENDING);
    expect($paid->gross_paise)->toBe(108_000);
    expect($wallet->balancePaise($dist->id))->toBe(0);
});

it('gives a rolled-over credit no second month of cap room — it keeps the ceiling of the month it was earned in', function () {
    setPayoutSetting('comp.monthly_income_cap_paise', '500000'); // ₹5,000 cap
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);
    $july = Carbon::create(2026, 7, 1);

    // July: ₹4,950 paid, leaving ₹50 of the month's ceiling.
    $wallet->credit($dist->id, 495_000, 'gsb_credit', walletRef(), 'test_reference', null, $july, earnedOn: earnedForBatch(Carbon::create(2026, 7, 14)));
    app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));

    // A ₹1,000 GSB earned in July meets only that ₹50. Net ₹46.07 is below the
    // ₹100 floor, so the whole credit rolls over unswept — cap decision included.
    $wallet->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', null, $july, earnedOn: earnedForBatch(Carbon::create(2026, 7, 21)));
    $second = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 21));
    expect(PayoutLineItem::where('payout_batch_id', $second->id)->where('distributor_id', $dist->id)->first()->status)
        ->toBe(PayoutLineItem::STATUS_BELOW_MINIMUM);

    // August: ₹1,000 of genuinely new income clears the floor. The rolled-over
    // July credit is still measured against July's ₹50 of room — not August's
    // untouched ₹5,000 — so ₹950 of it is forfeited rather than paid.
    $wallet->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', null, Carbon::create(2026, 8, 1), earnedOn: earnedForBatch(Carbon::create(2026, 8, 11)));
    $august = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 8, 11));

    $line = PayoutLineItem::where('payout_batch_id', $august->id)->where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_PENDING);
    expect($line->gross_paise)->toBe(105_000); // ₹50 of July's leftover + August's ₹1,000

    $forfeit = WalletLedgerEntry::where('distributor_id', $dist->id)
        ->where('type', 'income_cap_forfeit')->first();
    expect($forfeit->amount_paise)->toBe(-95_000);
    expect($wallet->balancePaise($dist->id))->toBe(0);
});

it('counts a historical credit with no bonus_month under the batch that swept it', function () {
    // Rows written before bonus_month existed have no earned month to read, so
    // the cap window falls back to the month of the batch that swept them and
    // those batches keep answering exactly as they did.
    setPayoutSetting('comp.monthly_income_cap_paise', '500000'); // ₹5,000 cap
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);

    // Plain credit() — no bonus_month, as every pre-migration row.
    $legacy = $wallet->credit($dist->id, 300_000, 'gsb_credit', walletRef(), 'test_reference', earnedOn: earnedForBatch(Carbon::create(2026, 7, 14)));
    expect($legacy->bonus_month)->toBeNull();

    app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));

    // The ₹3,000 it swept still consumes July's room, so a ₹4,000 rank bonus in
    // the same month is trimmed to the ₹2,000 that is left.
    $wallet->credit($dist->id, 400_000, 'rank_credit', walletRef(), 'test_reference');
    $monthly = app(PayoutService::class)->runMonthlyBatch(Carbon::create(2026, 7, 1));

    $line = PayoutLineItem::where('payout_batch_id', $monthly->id)->where('distributor_id', $dist->id)->first();
    expect($line->gross_paise)->toBe(200_000);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)
        ->where('type', 'income_cap_forfeit')->first()->amount_paise)->toBe(-200_000);
    expect($wallet->balancePaise($dist->id))->toBe(0);
});

it('monthly batch: a KYC-held line reports the credit-time repurchase deduction', function () {
    $dist = makePayoutEligibleDistributor();
    $dist->user->update(['status' => 'pending_kyc']);

    $walletSvc = app(WalletService::class);
    $walletSvc->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 300_000,
        bonusType: 'rank_credit',
        referenceId: walletRef(),
        referenceType: 'rank_bonus_result',
        bonusMonth: now()->startOfMonth(),
    );

    $batch = app(PayoutService::class)->runMonthlyBatch(Carbon::today()->startOfMonth()->addDays(7));

    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_KYC_PENDING);
    expect($line->gross_paise)->toBe(300_000);
    expect($line->repurchase_deduction_paise)->toBe(30_000);
    expect($line->wallet_balance_paise)->toBe(270_000);
    expect($walletSvc->balancePaise($dist->id))->toBe(270_000);

    $batch->refresh();
    expect($batch->total_gross_paise)->toBe(300_000);
    expect($batch->total_deductions_paise)->toBe(30_000);
    expect($batch->distributor_count)->toBe(0);
});

// ── A reversed bonus is never paid ───────────────────────────────────────────

it('never sweeps or pays a bonus an admin has reversed', function () {
    // A reversal leaves the original `+gross` credit and its
    // `repurchase_transfer` debit in the ledger so the statement still shows
    // what was earned. The batch selects credits by ledger type, so without the
    // reversed-bonus exclusion it swept them, wired the net to the bank for a
    // bonus that no longer exists, and left the main wallet permanently
    // negative by that amount.
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);
    $august = Carbon::create(2026, 8, 1);
    $ref = walletRef();

    $wallet->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 200_000,          // ₹2,000 gross
        bonusType: 'gsb_credit',
        referenceId: $ref,
        referenceType: 'gsb_cutoff_result',
        bonusMonth: $august,
        earnedOn: earnedForBatch(Carbon::create(2026, 8, 18)),
    );

    expect($wallet->balancePaise($dist->id))->toBe(180_000);

    $wallet->reverseBonusCredit(
        distributorId: $dist->id,
        netPaise: 180_000,
        repurchaseDeductionPaise: 20_000,
        referenceId: $ref,
        referenceType: 'gsb_cutoff_result',
        bonusMonth: $august,
        memo: 'Admin reversal — slab matched against a reversed order.',
    );

    expect($wallet->balancePaise($dist->id))->toBe(0)
        ->and($wallet->repurchaseWalletBalancePaise($dist->id))->toBe(0);

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 8, 18));

    // Nothing to pay: no line item, no NEFT amount, and the reversed entries are
    // left unswept so no later batch can pick them up either.
    expect(PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $dist->id)->exists())->toBeFalse()
        ->and($batch->fresh()->total_net_paise)->toBe(0)
        ->and($batch->fresh()->distributor_count)->toBe(0)
        ->and($wallet->balancePaise($dist->id))->toBe(0)
        ->and(WalletLedgerEntry::where('distributor_id', $dist->id)->whereNotNull('swept_by_payout_batch_id')->count())->toBe(0);
});

it('leaves the earned month cap room untouched by a reversed bonus', function () {
    setPayoutSetting('comp.monthly_income_cap_paise', '500000'); // ₹5,000 cap
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);
    $august = Carbon::create(2026, 8, 1);
    $reversedRef = walletRef();

    $wallet->credit($dist->id, 200_000, 'gsb_credit', $reversedRef, 'gsb_cutoff_result', null, $august, earnedOn: earnedForBatch(Carbon::create(2026, 8, 18)));
    $wallet->reverseBonusCredit(
        distributorId: $dist->id,
        netPaise: 200_000,
        repurchaseDeductionPaise: 0,
        referenceId: $reversedRef,
        referenceType: 'gsb_cutoff_result',
        bonusMonth: $august,
    );

    // ₹5,000 of genuine August income exactly fills August's ceiling. It may
    // only be trimmed if the reversed ₹2,000 wrongly consumed part of that room.
    $wallet->credit($dist->id, 500_000, 'gsb_credit', walletRef(), 'gsb_cutoff_result', null, $august, earnedOn: earnedForBatch(Carbon::create(2026, 8, 18)));

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 8, 18));

    $line = PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $dist->id)->sole();
    expect($line->status)->toBe(PayoutLineItem::STATUS_PENDING)
        ->and($line->gross_paise)->toBe(500_000)
        ->and(WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'income_cap_forfeit')->exists())->toBeFalse()
        ->and($wallet->balancePaise($dist->id))->toBe(0);
});

// ── Cap forfeits carry the earned month and an audit row ─────────────────────

it('attributes a PARTIAL cap forfeit to the earned month and audits it', function () {
    // The partial-forfeit path used to write a bare `income_cap_forfeit` debit
    // with no bonus_month and no audit row, so the debit could not be
    // reconciled against the ceiling decision that destroyed the income.
    setPayoutSetting('comp.monthly_income_cap_paise', '500000'); // ₹5,000 cap
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);
    $july = Carbon::create(2026, 7, 1);

    $wallet->credit($dist->id, 400_000, 'gsb_credit', walletRef(), 'test_reference', null, $july, earnedOn: earnedForBatch(Carbon::create(2026, 7, 14)));
    app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));

    // ₹3,000 more of July income meets only the ₹1,000 July has left.
    $wallet->credit($dist->id, 300_000, 'gsb_credit', walletRef(), 'test_reference', null, $july, earnedOn: earnedForBatch(Carbon::create(2026, 7, 21)));
    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 21));

    $line = PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $dist->id)->sole();
    expect($line->status)->toBe(PayoutLineItem::STATUS_PENDING)
        ->and($line->gross_paise)->toBe(100_000);

    $forfeit = WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'income_cap_forfeit')->sole();
    expect($forfeit->amount_paise)->toBe(-200_000)
        ->and($forfeit->bonus_month->toDateString())->toBe('2026-07-01')
        ->and($wallet->balancePaise($dist->id))->toBe(0);

    $audit = AuditLog::where('action', 'payout.income_cap_forfeited')->where('subject_id', $dist->id)->sole();
    expect($audit->details['payout_line_item_id'])->toBe($line->id)
        ->and($audit->details['forfeited_paise'])->toBe(200_000)
        ->and($audit->details['forfeited_by_earned_month'])->toBe(['2026-07-01' => 200_000]);
});

it('writes one forfeit debit per earned month when a batch settles credits from two', function () {
    // The debits share a line item, so the month has to be part of their ledger
    // identity as well as their bonus_month: on a bare `payout_line_item`
    // reference the second month collides on uniq_wallet_ledger_source and the
    // whole distributor's payout fails.
    setPayoutSetting('comp.monthly_income_cap_paise', '500000'); // ₹5,000 cap
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);
    $july = Carbon::create(2026, 7, 1);
    $august = Carbon::create(2026, 8, 1);

    // Both months' ceilings are consumed in full.
    $wallet->credit($dist->id, 500_000, 'gsb_credit', walletRef(), 'test_reference', null, $july, earnedOn: earnedForBatch(Carbon::create(2026, 7, 14)));
    app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));
    $wallet->credit($dist->id, 500_000, 'gsb_credit', walletRef(), 'test_reference', null, $august, earnedOn: earnedForBatch(Carbon::create(2026, 8, 11)));
    app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 8, 11));

    // ₹1,000 more for each month — nothing payable, both wholly forfeited.
    $wallet->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', null, $july, earnedOn: Carbon::create(2026, 7, 21));
    $wallet->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference', null, $august, earnedOn: earnedForBatch(Carbon::create(2026, 8, 18)));
    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 8, 18));

    expect($batch->status)->toBe(PayoutBatch::STATUS_PENDING);

    $line = PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $dist->id)->sole();
    expect($line->status)->toBe(PayoutLineItem::STATUS_INCOME_CAP_FORFEITED);

    $forfeits = WalletLedgerEntry::where('distributor_id', $dist->id)
        ->where('type', 'income_cap_forfeit')
        ->orderBy('id')
        ->get();

    expect($forfeits)->toHaveCount(2)
        ->and($forfeits->map(fn ($e) => $e->bonus_month->toDateString())->all())->toBe(['2026-07-01', '2026-08-01'])
        ->and($forfeits->pluck('amount_paise')->all())->toBe([-100_000, -100_000])
        ->and($wallet->balancePaise($dist->id))->toBe(0);

    $audit = AuditLog::where('action', 'payout.income_cap_forfeited')->where('subject_id', $dist->id)->sole();
    expect($audit->details['forfeited_by_earned_month'])->toBe(['2026-07-01' => 100_000, '2026-08-01' => 100_000]);
});

// ── The Wednesday→Tuesday earning week, paid one Tuesday later ───────────────
//
// Client rule 2026-09-07: "eligible earnings accrued between Wednesday, August
// 5, and Tuesday, August 11" are deposited "on Tuesday, August 18". The batch
// dated Tuesday T pays what was EARNED on or before T − 7 and nothing later.

it('pays income earned Wed 5 Aug – Tue 11 Aug on Tue 18 Aug and not on 11 Aug', function () {
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);
    $august = Carbon::create(2026, 8, 1);

    // The client's own two examples: a Thursday and the Tuesday that closes the week.
    $thu = $wallet->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'gsb_cutoff_result', null, $august, Carbon::create(2026, 8, 6));
    $tue = $wallet->credit($dist->id, 200_000, 'gsb_credit', walletRef(), 'gsb_cutoff_result', null, $august, Carbon::create(2026, 8, 11));

    // 11 August is the Tuesday the week CLOSES on, not the Tuesday it is paid:
    // that batch pays 29 Jul – 4 Aug, which is empty here.
    $tooEarly = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 8, 11));

    expect(PayoutLineItem::where('payout_batch_id', $tooEarly->id)->count())->toBe(0)
        ->and($tooEarly->distributor_count)->toBe(0)
        ->and($wallet->balancePaise($dist->id))->toBe(300_000);

    // One Tuesday later the whole week goes out.
    $paidOn = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 8, 18));

    $line = PayoutLineItem::where('payout_batch_id', $paidOn->id)->where('distributor_id', $dist->id)->sole();
    expect($line->status)->toBe(PayoutLineItem::STATUS_PENDING)
        ->and($line->gross_paise)->toBe(300_000)
        ->and($thu->fresh()->swept_by_payout_batch_id)->toBe($paidOn->id)
        ->and($tue->fresh()->swept_by_payout_batch_id)->toBe($paidOn->id)
        ->and($wallet->balancePaise($dist->id))->toBe(0);
});

it('sweeps income earned on T−7 and leaves T−6 for the next Tuesday', function () {
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);
    $august = Carbon::create(2026, 8, 1);
    $batchDate = Carbon::create(2026, 8, 18);

    // The last day of the week being paid, and the first day of the next one.
    $inWeek = $wallet->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'gsb_cutoff_result', null, $august, Carbon::create(2026, 8, 11));
    $nextWeek = $wallet->credit($dist->id, 500_000, 'gsb_credit', walletRef(), 'gsb_cutoff_result', null, $august, Carbon::create(2026, 8, 12));

    $batch = app(PayoutService::class)->runWeeklyBatch($batchDate);

    $line = PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $dist->id)->sole();
    expect($line->gross_paise)->toBe(100_000)
        ->and($inWeek->fresh()->swept_by_payout_batch_id)->toBe($batch->id)
        ->and($nextWeek->fresh()->swept_by_payout_batch_id)->toBeNull()
        // Held, not lost: the ₹5,000 is still in the wallet.
        ->and($wallet->balancePaise($dist->id))->toBe(500_000);

    // …and goes out whole on the Tuesday its own week reaches.
    $next = app(PayoutService::class)->runWeeklyBatch($batchDate->copy()->addWeek());

    expect($nextWeek->fresh()->swept_by_payout_batch_id)->toBe($next->id)
        ->and(PayoutLineItem::where('payout_batch_id', $next->id)->where('distributor_id', $dist->id)->sole()->gross_paise)
        ->toBe(500_000);
});

it('reports only the payable week on a line held for KYC', function () {
    // A held line is what the admin batch page and the distributor's statement
    // read. Reporting everything unswept would show income the batch was never
    // due to pay as though it had been withheld by the KYC gate.
    $dist = makePayoutEligibleDistributor();
    $dist->user->update(['status' => 'pending']);
    $wallet = app(WalletService::class);
    $august = Carbon::create(2026, 8, 1);

    $wallet->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'gsb_cutoff_result', null, $august, Carbon::create(2026, 8, 11));
    $wallet->credit($dist->id, 500_000, 'gsb_credit', walletRef(), 'gsb_cutoff_result', null, $august, Carbon::create(2026, 8, 12));

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 8, 18));

    $line = PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $dist->id)->sole();
    expect($line->status)->toBe(PayoutLineItem::STATUS_KYC_PENDING)
        ->and($line->gross_paise)->toBe(100_000)
        ->and($batch->fresh()->total_gross_paise)->toBe(100_000)
        // Nothing swept or debited while held.
        ->and($wallet->balancePaise($dist->id))->toBe(600_000);
});

it('sweeps a repurchase_transfer only in the batch that pays its own credit', function () {
    // The three rows of a deducted credit are written together and carry the
    // same earning day. A transfer swept ahead of its credit would take the
    // deduction out of a payout that never included the bonus it belongs to,
    // and leave the main wallet short by it for a week.
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);
    $august = Carbon::create(2026, 8, 1);

    $wallet->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 200_000,
        bonusType: 'gsb_credit',
        referenceId: walletRef(),
        referenceType: 'gsb_cutoff_result',
        bonusMonth: $august,
        earnedOn: Carbon::create(2026, 8, 11),   // inside the week being paid
    );
    $wallet->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 300_000,
        bonusType: 'gsb_credit',
        referenceId: walletRef(),
        referenceType: 'gsb_cutoff_result',
        bonusMonth: $august,
        earnedOn: Carbon::create(2026, 8, 12),   // the following week
    );

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 8, 18));

    $line = PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $dist->id)->sole();
    // Only the 11 August credit and only its own 20,000 transfer.
    expect($line->gross_paise)->toBe(200_000)
        ->and($line->repurchase_deduction_paise)->toBe(20_000)
        ->and($line->wallet_balance_paise)->toBe(180_000);

    $unswept = WalletLedgerEntry::where('distributor_id', $dist->id)
        ->whereNull('swept_by_payout_batch_id')
        ->whereIn('type', ['gsb_credit', 'repurchase_transfer'])
        ->pluck('amount_paise', 'type')
        ->map(fn ($paise): int => (int) $paise)
        ->all();

    expect($unswept)->toBe(['gsb_credit' => 300_000, 'repurchase_transfer' => -30_000])
        // Main wallet still holds next week's ₹3,000 less its own deduction.
        ->and($wallet->balancePaise($dist->id))->toBe(270_000);
});

it('pays mentorship credits on the same earning week as GSB', function () {
    // Spec §3 assumption A3: "the daily closing weekly payout" is the whole
    // Group A batch, Mentorship included.
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);
    $august = Carbon::create(2026, 8, 1);

    $inWeek = $wallet->credit($dist->id, 100_000, 'mb_credit', walletRef(), 'mentorship_bonus_result', null, $august, Carbon::create(2026, 8, 11));
    $nextWeek = $wallet->credit($dist->id, 400_000, 'mb_credit', walletRef(), 'mentorship_bonus_result', null, $august, Carbon::create(2026, 8, 12));

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 8, 18));

    expect(PayoutLineItem::where('payout_batch_id', $batch->id)->where('distributor_id', $dist->id)->sole()->gross_paise)->toBe(100_000)
        ->and($inWeek->fresh()->swept_by_payout_batch_id)->toBe($batch->id)
        ->and($nextWeek->fresh()->swept_by_payout_batch_id)->toBeNull();
});

it('rolls a below-minimum week over and pays it once the window reaches the next one', function () {
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);
    $august = Carbon::create(2026, 8, 1);

    // ₹80 earned in the 5–11 Aug week: net is below the ₹100 floor.
    $wallet->credit($dist->id, 8_000, 'gsb_credit', walletRef(), 'gsb_cutoff_result', null, $august, Carbon::create(2026, 8, 11));
    // ₹1,000 earned in the NEXT week — invisible to the 18 Aug batch.
    $wallet->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'gsb_cutoff_result', null, $august, Carbon::create(2026, 8, 12));

    $first = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 8, 18));

    expect(PayoutLineItem::where('payout_batch_id', $first->id)->where('distributor_id', $dist->id)->sole()->status)
        ->toBe(PayoutLineItem::STATUS_BELOW_MINIMUM)
        ->and($wallet->balancePaise($dist->id))->toBe(108_000);

    // 25 August: the window now covers both weeks and the whole balance clears.
    $second = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 8, 25));

    $line = PayoutLineItem::where('payout_batch_id', $second->id)->where('distributor_id', $dist->id)->sole();
    expect($line->status)->toBe(PayoutLineItem::STATUS_PENDING)
        ->and($line->gross_paise)->toBe(108_000)
        ->and($wallet->balancePaise($dist->id))->toBe(0);
});

it('measures a July week swept in August against July’s income cap', function () {
    // The earning week and the income cap window are different clocks: the last
    // July week is paid by an August batch, but the ceiling it is measured
    // against is still July's. Reading the cap off the batch date would hand
    // that income a second month of room.
    setPayoutSetting('comp.monthly_income_cap_paise', '500000'); // ₹5,000 cap
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);
    $july = Carbon::create(2026, 7, 1);

    // July's ₹5,000 ceiling is consumed in full by an earlier July week.
    $wallet->credit($dist->id, 500_000, 'gsb_credit', walletRef(), 'gsb_cutoff_result', null, $july, Carbon::create(2026, 7, 21));
    app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 28));

    // ₹1,000 earned on 28 July — the last day of a week the 4 August batch pays.
    $wallet->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'gsb_cutoff_result', null, $july, Carbon::create(2026, 7, 28));
    $august = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 8, 4));

    $line = PayoutLineItem::where('payout_batch_id', $august->id)->where('distributor_id', $dist->id)->sole();
    expect($line->status)->toBe(PayoutLineItem::STATUS_INCOME_CAP_FORFEITED)
        ->and($line->net_transferred_paise)->toBe(0);

    $forfeit = WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'income_cap_forfeit')->sole();
    expect($forfeit->amount_paise)->toBe(-100_000)
        ->and($forfeit->bonus_month->toDateString())->toBe('2026-07-01')
        ->and($wallet->balancePaise($dist->id))->toBe(0);
});
