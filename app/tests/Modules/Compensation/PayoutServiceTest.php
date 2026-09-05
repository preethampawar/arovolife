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
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference'); // ₹1,000

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
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');

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
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');

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
    $walletSvc->credit($first->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');

    $svc = app(PayoutService::class);
    $batch = $svc->runWeeklyBatch(Carbon::today());
    $svc->approve($batch, $admin->id);

    // A second distributor earns after the batch was signed off. Re-running the
    // same date must not slip them into an amount finance already approved.
    $second = makePayoutEligibleDistributor();
    $walletSvc->credit($second->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');

    $svc->runWeeklyBatch(Carbon::today());

    expect(PayoutLineItem::where('payout_batch_id', $batch->id)->count())->toBe(1);
    expect($batch->fresh()->status)->toBe(PayoutBatch::STATUS_APPROVED);
});

it('skips wallet below minimum payout threshold', function () {
    $dist = makePayoutEligibleDistributor();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 8_000, 'gsb_credit', walletRef(), 'test_reference'); // ₹80 — net ₹73.72, below ₹100 minimum (KP)

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
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');

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
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');

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
    $walletSvc->credit($dist1->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');
    $walletSvc->credit($dist2->id, 200_000, 'gsb_credit', walletRef(), 'test_reference');

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
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');

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
    $wallet->credit($dist->id, 1_000_000, 'gsb_credit', walletRef(), 'test_reference'); // ₹10,000
    $wallet->credit($dist->id, 1_000_000, 'mb_credit', walletRef(), 'test_reference');  // ₹10,000

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));

    $line = PayoutLineItem::where('distributor_id', $dist->id)->where('payout_batch_id', $batch->id)->first();
    // 3% of the full ₹20,000 Group-A gross = 60,000 paise.
    expect($line->admin_charge_paise)->toBe(60_000);
});

it('weekly batch: admin charge skips a Group-A stream whose applies_to toggle is OFF', function () {
    $dist = makePayoutEligibleDistributor();

    $wallet = app(WalletService::class);
    $wallet->credit($dist->id, 1_000_000, 'gsb_credit', walletRef(), 'test_reference'); // ₹10,000
    $wallet->credit($dist->id, 1_000_000, 'mb_credit', walletRef(), 'test_reference');  // ₹10,000

    // Exempt Mentorship from the admin charge — the toggle must take effect.
    setPayoutSetting('comp.admin_charge.applies_to_mb', 'false');

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));

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
    );
    $b1 = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 7));
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
    );
    $b2 = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));
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
    $wallet->credit($d1->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');
    $wallet->credit($d2->id, 200_000, 'gsb_credit', walletRef(), 'test_reference');

    // Simulate a run that crashed after fully committing d1 (line item written,
    // entries swept, wallet debited) but before batch totals were finalized.
    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => Carbon::create(2026, 7, 14)->toDateString(),
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

    $resumed = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));

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

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference'); // ₹1,000

    // Pin both batches inside one calendar month: run this near month-end and
    // "+1 week" lands in the next month, where the ₹1,000 becomes prior-month
    // income and the 10% repurchase deduction breaks the balance assertion.
    $batchDate = Carbon::today()->startOfMonth()->addDays(7);

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
    $wallet->credit($dist->id, 800_000, 'gsb_credit', walletRef(), 'test_reference'); // ₹8,000 — ₹3,000 above cap

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 7));

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
    $wallet->credit($dist->id, 300_000, 'gsb_credit', walletRef(), 'test_reference');
    app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 7));

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
    app(WalletService::class)->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference'); // ₹1,000

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
    app(WalletService::class)->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');

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
    );
    $weekly = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 7));
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
    );
    // GSB credit with repurchase_transfer (ref type: gsb_cutoff_result).
    $wallet->creditWithRepurchaseDeduction(
        distributorId: $dist->id,
        grossPaise: 100_000,
        bonusType: 'gsb_credit',
        referenceId: walletRef(),
        referenceType: 'gsb_cutoff_result',
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
    $wallet->credit($ok->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');
    $wallet->credit($bad->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');

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
    app(WalletService::class)->credit($dist->id, 100_000, 'gsb_credit', walletRef(), 'test_reference');

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

    $weekly = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 7));

    // Payout deduction = repurchase_transfer sweep only (10,000), not affected
    // by the order restoration credit.
    $line = PayoutLineItem::where('payout_batch_id', $weekly->id)->where('distributor_id', $dist->id)->first();
    expect($line->repurchase_deduction_paise)->toBe(10_000);

    // Main wallet: 0 (fully swept). Repurchase wallet: 10,000 (credit-time) + 8,000 (restoration).
    expect($wallet->balancePaise($dist->id))->toBe(0);
    expect($wallet->repurchaseWalletBalancePaise($dist->id))->toBe(18_000);

    Carbon::setTestNow(null);
});
