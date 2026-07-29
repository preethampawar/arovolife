<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit'); // ₹1,000

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

it('approve() marks batch COMPLETED and line items TRANSFERRED', function () {
    $admin = User::factory()->create();
    $dist = makePayoutEligibleDistributor();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit');

    $svc = app(PayoutService::class);
    $batch = $svc->runWeeklyBatch(Carbon::today());

    $approved = $svc->approve($batch, $admin->id);

    expect($approved->status)->toBe(PayoutBatch::STATUS_COMPLETED);
    expect($approved->approved_by)->toBe($admin->id);
    expect($approved->approved_at)->not->toBeNull();

    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_TRANSFERRED);
});

it('skips wallet below minimum payout threshold', function () {
    $dist = makePayoutEligibleDistributor();

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 8_000, 'gsb_credit'); // ₹80 — net ₹73.72, below ₹100 minimum (KP)

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
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit');

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

it('applies repurchase deduction from prior month gsb and mb credits', function () {
    $dist = makePayoutEligibleDistributor();

    $walletSvc = app(WalletService::class);

    $today = Carbon::create(2026, 7, 15, 12, 0, 0);
    $priorMonthMid = Carbon::create(2026, 6, 15, 12, 0, 0);

    // Credit prior-month GSB: ₹2,000 → 10% deduction = 20,000 paise.
    Carbon::setTestNow($priorMonthMid);
    $walletSvc->credit($dist->id, 200_000, 'gsb_credit');

    // Credit current-month GSB: ₹1,000 (not included in deduction).
    Carbon::setTestNow($today);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit');

    $svc = app(PayoutService::class);
    $batch = $svc->runWeeklyBatch($today);

    // Both months' unswept credits (₹3,000 gross) sweep into this batch;
    // the repurchase figure is 10% of PRIOR-month credits only (₹200 = 20,000p).
    $line = PayoutLineItem::where('distributor_id', $dist->id)->first();
    expect($line->status)->toBe(PayoutLineItem::STATUS_PENDING);
    expect($line->gross_paise)->toBe(300_000);
    expect($line->repurchase_deduction_paise)->toBe(20_000);

    // After debit and repurchase credit: net wallet = 20,000 (the held-back deduction).
    expect($walletSvc->balancePaise($dist->id))->toBe(20_000);

    Carbon::setTestNow(null);
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
    $walletSvc->credit($dist1->id, 100_000, 'gsb_credit');
    $walletSvc->credit($dist2->id, 200_000, 'gsb_credit');

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
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit');

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
    $wallet->credit($dist->id, 1_000_000, 'gsb_credit'); // ₹10,000
    $wallet->credit($dist->id, 1_000_000, 'mb_credit');  // ₹10,000

    $batch = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));

    $line = PayoutLineItem::where('distributor_id', $dist->id)->where('payout_batch_id', $batch->id)->first();
    // 3% of the full ₹20,000 Group-A gross = 60,000 paise.
    expect($line->admin_charge_paise)->toBe(60_000);
});

it('weekly batch: admin charge skips a Group-A stream whose applies_to toggle is OFF', function () {
    $dist = makePayoutEligibleDistributor();

    $wallet = app(WalletService::class);
    $wallet->credit($dist->id, 1_000_000, 'gsb_credit'); // ₹10,000
    $wallet->credit($dist->id, 1_000_000, 'mb_credit');  // ₹10,000

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
    $wallet->credit($dist->id, 1_000_000, 'gbb_credit');     // ₹10,000
    $wallet->credit($dist->id, 1_000_000, 'rank_credit');    // ₹10,000
    $wallet->credit($dist->id, 1_000_000, 'fortune_credit'); // ₹10,000

    setPayoutSetting('comp.admin_charge.applies_to_fortune', 'false');

    $batch = app(PayoutService::class)->runMonthlyBatch(Carbon::create(2026, 7, 1));

    $line = PayoutLineItem::where('distributor_id', $dist->id)->where('payout_batch_id', $batch->id)->first();
    // Group B = GBB + Rank + Fortune, but Fortune is exempt → 3% of ₹20,000 = 60,000.
    expect($line->admin_charge_paise)->toBe(60_000);
});

it('weekly batches: repurchase deduction is collected once per month, not every Tuesday', function () {
    $dist = makePayoutEligibleDistributor();
    $wallet = app(WalletService::class);

    // Prior-month GSB ₹2,000 → monthly repurchase target = 10% = 20,000 paise.
    Carbon::setTestNow(Carbon::create(2026, 6, 15, 12));
    $wallet->credit($dist->id, 200_000, 'gsb_credit');

    Carbon::setTestNow(Carbon::create(2026, 7, 7, 9));
    $wallet->credit($dist->id, 100_000, 'gsb_credit');
    $b1 = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 7));
    $l1 = PayoutLineItem::where('payout_batch_id', $b1->id)->where('distributor_id', $dist->id)->first();
    expect($l1->repurchase_deduction_paise)->toBe(20_000);

    // Second Tuesday of the same month: the target was already collected —
    // deducting it again would take 10% per week instead of 10% per month.
    Carbon::setTestNow(Carbon::create(2026, 7, 14, 9));
    $wallet->credit($dist->id, 100_000, 'gsb_credit');
    $b2 = app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 14));
    $l2 = PayoutLineItem::where('payout_batch_id', $b2->id)->where('distributor_id', $dist->id)->first();
    expect($l2->repurchase_deduction_paise)->toBe(0);

    Carbon::setTestNow(null);
});

it('monthly batch: rank credits above the income cap are forfeited with a ledger debit, not stranded', function () {
    $dist = makePayoutEligibleDistributor();
    setPayoutSetting('comp.monthly_income_cap_paise', '500000'); // ₹5,000 cap

    $wallet = app(WalletService::class);
    $wallet->credit($dist->id, 800_000, 'rank_credit'); // ₹8,000 — ₹3,000 above cap

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
    $wallet->credit($d1->id, 100_000, 'gsb_credit');
    $wallet->credit($d2->id, 200_000, 'gsb_credit');

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
    $wallet->credit($dist->id, 1_000_000, 'adc_credit'); // ₹10,000 — Group D

    setPayoutSetting('comp.admin_charge.applies_to_adc', 'false');

    $batch = app(PayoutService::class)->runMonthlyBatch(Carbon::create(2026, 7, 1));

    $line = PayoutLineItem::where('distributor_id', $dist->id)->where('payout_batch_id', $batch->id)->first();
    expect($line->admin_charge_paise)->toBe(0);
});

it('weekly batch: holds payout as no_bank_account when no bank details are on file', function () {
    $dist = makePayoutEligibleDistributor();
    $dist->update(['bank_account_enc' => null]);  // skipped the optional bank step

    $walletSvc = app(WalletService::class);
    $walletSvc->credit($dist->id, 100_000, 'gsb_credit'); // ₹1,000

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
    $wallet->credit($dist->id, 800_000, 'gsb_credit'); // ₹8,000 — ₹3,000 above cap

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
    $wallet->credit($dist->id, 300_000, 'gsb_credit');
    app(PayoutService::class)->runWeeklyBatch(Carbon::create(2026, 7, 7));

    // Monthly batch in the same month: rank ₹4,000 against ₹2,000 remaining room.
    $wallet->credit($dist->id, 400_000, 'rank_credit');
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
    app(WalletService::class)->credit($dist->id, 100_000, 'gsb_credit'); // ₹1,000

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
    app(WalletService::class)->credit($dist->id, 500_000, 'rank_credit'); // ₹5,000 monthly stream

    app(PayoutService::class)->runMonthlyBatch(Carbon::today());

    $line = PayoutLineItem::where('distributor_id', $dist->id)->firstOrFail();
    expect($line->status)->toBe(PayoutLineItem::STATUS_KYC_PENDING);
    expect(app(WalletService::class)->balancePaise($dist->id))->toBe(500_000);
});

it('pays the held balance once KYC is verified on a later batch', function () {
    $dist = makePayoutEligibleDistributor();
    $dist->user->update(['status' => 'pending']);
    app(WalletService::class)->credit($dist->id, 100_000, 'gsb_credit');

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
