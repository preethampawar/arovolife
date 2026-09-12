<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Enums\BonusType;
use App\Modules\Compensation\Exceptions\BankDecryptionException;
use App\Modules\Compensation\Jobs\DispatchRazorpayPayoutsJob;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Shared\Crypto\PiiCrypter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PayoutService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly BvLedgerService $bvLedger,
        private readonly CompensationPlanSettingsService $plan,
        private readonly PayoutGatewaySettings $payoutSettings,
        private readonly EngineRunContext $engineRunContext,
    ) {}

    /**
     * The maker of a batch this run is about to create (QA F94).
     *
     * Three ways a batch comes into being, and each knows the actor
     * differently: an admin hitting a payout page carries an authenticated
     * session; an admin running the engine from the Engine Runs console has
     * their id bound on the (container-scoped) run context by EngineRunService
     * before it calls artisan, because the queue worker running the command has
     * no session; the scheduler has neither, and its batches are stamped NULL —
     * a machine-made batch has no maker, so any approver may check it.
     */
    private function batchCreatorId(): ?int
    {
        $authenticated = Auth::id();

        if (is_numeric($authenticated)) {
            return (int) $authenticated;
        }

        return $this->engineRunContext->actorId();
    }

    /**
     * Batch states a re-run must never touch. A batch that has been approved
     * — whether it is waiting for the bank response file (`approved`) or for
     * Razorpay's webhooks (`dispatched`) — is closed: appending fresh line
     * items to it would pay money outside the amount finance signed off.
     *
     * @var list<string>
     */
    private const CLOSED_BATCH_STATUSES = [
        PayoutBatch::STATUS_PROCESSING,
        PayoutBatch::STATUS_COMPLETED,
        PayoutBatch::STATUS_APPROVED,
        PayoutBatch::STATUS_DISPATCHED,
    ];

    /**
     * The credit types a monthly batch settles: Groups B, C and D.
     *
     * @return list<string>
     */
    private static function monthlyCreditTypes(): array
    {
        return array_merge(
            CompensationPlanSettingsService::GROUP_B_TYPES,
            CompensationPlanSettingsService::GROUP_C_TYPES,
            CompensationPlanSettingsService::GROUP_D_TYPES,
        );
    }

    /** reference_type values that produce repurchase_transfer debits from the weekly (Group A) engines. */
    private const WEEKLY_REPURCHASE_REF_TYPES = ['gsb_cutoff_result'];

    /** reference_type values that produce repurchase_transfer debits from the monthly (Group B) engines. */
    private const MONTHLY_REPURCHASE_REF_TYPES = ['gbb_monthly_result', 'rank_bonus_result', 'fortune_bonus_result'];

    /**
     * reference_type prefix of an `income_cap_forfeit` debit, completed with the
     * 'Y-m-d' first day of the month the forfeited income was EARNED for.
     * {@see writeIncomeCapForfeits()} for why the month is part of the identity.
     */
    private const FORFEIT_REFERENCE_PREFIX = 'payout_line_item_';

    /** {@see withSweepLock()} — the one lock both payout sweeps take. */
    private const SWEEP_LOCK_KEY = 'compensation:payout-sweep';

    /** How long a crashed sweep may hold every other payout before the lock expires. */
    private const SWEEP_LOCK_TTL_SECONDS = 3600;

    /** How long a second sweep queues behind the first before giving up. */
    private const SWEEP_LOCK_WAIT_SECONDS = 120;

    /**
     * Weekly payout batch (Group A: GSB + Mentorship).
     *
     * Pays ONE Wednesday→Tuesday earning week, the one that closed the previous
     * Tuesday: the batch dated `$cycleEnd` sweeps unswept `gsb_credit`,
     * `mb_credit` and their `repurchase_transfer` debits earned on or before
     * `$cycleEnd − 7` and leaves the rest for the following Tuesday
     * ({@see PayoutBatch::weeklyEarningWindow()}, the only place that rule
     * lives). Income earned 5–11 Aug is paid on 18 Aug; income earned 12 Aug on
     * 25 Aug (client 2026-09-07).
     *
     * The window is keyed on the day the income was EARNED, never on the credit
     * timestamp — Tuesday's cut-off is credited at 00:10 on Wednesday. Rows with
     * no `earned_on` (pre-column history; a credit written outside the engines)
     * still pass, so nothing is stranded unpaid.
     *
     * Deductions, in order: repurchase (already deducted at credit time and held
     * in the repurchase wallet), Group-A admin charge (3%, capped at ₹25k),
     * TDS (5% of payable).
     */
    public function runWeeklyBatch(Carbon $cycleEnd): PayoutBatch
    {
        return $this->withSweepLock(fn (): PayoutBatch => $this->sweepWeeklyBatch($cycleEnd));
    }

    /** {@see runWeeklyBatch()} — its body, run while the sweep lock is held. */
    private function sweepWeeklyBatch(Carbon $cycleEnd): PayoutBatch
    {
        $dateStr = $cycleEnd->toDateString();
        $earnedThrough = PayoutBatch::weeklyEarningWindow($cycleEnd)['end'];
        $minPayoutPaise = $this->plan->minPayoutPaise();
        $adminCapPaise = $this->plan->adminChargeWeeklyCapPaise();
        $adminRateBp = $this->plan->adminChargeRateBp();
        $tdsRateBp = $this->plan->tdsRateBp();
        $groupTypes = CompensationPlanSettingsService::GROUP_A_TYPES;

        // Idempotent: one weekly batch per date. whereDate() — the date cast
        // stores 'Y-m-d 00:00:00', so a bare where() on the string misses.
        $batch = PayoutBatch::whereDate('batch_date', $dateStr)
            ->where('batch_type', PayoutBatch::TYPE_WEEKLY)
            ->first();

        $wasCreated = false;
        if ($batch === null) {
            $batch = PayoutBatch::create([
                'batch_type' => PayoutBatch::TYPE_WEEKLY,
                'batch_date' => $dateStr,
                // Stamped at creation so the reports read the week this batch
                // actually paid instead of re-deriving one. Batches written
                // before this column existed keep null and read "—".
                'earnings_through' => $earnedThrough->toDateString(),
                'status' => PayoutBatch::STATUS_PENDING,
                // The maker half of maker-checker: whoever asked for this
                // batch cannot later approve it (QA F94).
                'created_by' => $this->batchCreatorId(),
            ]);
            $wasCreated = true;
        }

        if (in_array($batch->status, self::CLOSED_BATCH_STATUSES, true)
            || ($batch->status === PayoutBatch::STATUS_PENDING && $batch->processed_at !== null)) {
            return $batch;
        }

        $update = ['status' => PayoutBatch::STATUS_PROCESSING];

        // A re-run adds line items to a batch, which is making, not checking —
        // so an unattributed (scheduler-built) batch picks up the admin who
        // re-ran it as its maker. An existing maker is never overwritten: the
        // first hand on the batch is the one barred from approving it (QA F94).
        if ($batch->created_by === null && ($reRunMaker = $this->batchCreatorId()) !== null) {
            $update['created_by'] = $reRunMaker;
        }

        $batch->update($update);

        // A re-run of an existing batch re-reads its holds first: the
        // per-distributor guard below skips anyone who already has a line, so
        // without this a hold recorded on the first run could never clear.
        $this->releaseClearedHolds($batch);

        $distributorIds = WalletLedgerEntry::whereIn('type', $groupTypes)
            ->whereNull('swept_by_payout_batch_id')
            ->where('amount_paise', '>', 0)
            ->notReversed()
            ->earnedOnOrBefore($earnedThrough)
            ->distinct()
            ->pluck('distributor_id');

        if ($wasCreated) {
            $this->auditBatchCreated($batch, $dateStr, $distributorIds->count());
        }

        $failedCount = 0;

        foreach ($distributorIds as $rawId) {
            $distributorId = (int) $rawId;

            try {
                $this->processWeeklyDistributor($batch, $distributorId, $cycleEnd);
            } catch (Throwable $e) {
                // One distributor's failure must not strand the whole batch in
                // `processing` and block every other distributor's payout. The
                // DB::transaction above rolled their partial writes back; a
                // re-run of this batch date retries exactly these distributors
                // (the line-item existence guard skips everyone already done).
                Log::critical('Weekly payout batch: distributor failed — continuing with the rest', [
                    'payout_batch_id' => $batch->id,
                    'distributor_id' => $distributorId,
                    'exception' => $e,
                ]);
                $failedCount++;
            }
        }

        $this->finalizeBatchTotals($batch, $failedCount);

        if ($failedCount > 0) {
            Log::critical('Weekly payout batch finished partially failed', [
                'payout_batch_id' => $batch->id,
                'failed_distributor_count' => $failedCount,
            ]);
        }

        return $batch;
    }

    /**
     * Monthly payout batch (Groups B/C/D: GBB, Rank, Fortune, Awards, ADC).
     *
     * Sweeps all unswept entries for the five monthly bonus streams. Applies the
     * ₹50L per-distributor rank cap (KP Round-4), the repurchase deduction
     * remainder not already collected by the month's weekly batches, per-group
     * admin charges (each capped at ₹25k), and TDS (5% on payable). Deduction
     * order: gross → repurchase → admin charge → TDS.
     */
    public function runMonthlyBatch(Carbon $month): PayoutBatch
    {
        return $this->withSweepLock(fn (): PayoutBatch => $this->sweepMonthlyBatch($month));
    }

    /** {@see runMonthlyBatch()} — its body, run while the sweep lock is held. */
    private function sweepMonthlyBatch(Carbon $month): PayoutBatch
    {
        $dateStr = $month->copy()->startOfMonth()->toDateString();
        $earnedThrough = $month->copy()->endOfMonth();
        $minPayoutPaise = $this->plan->minPayoutPaise();
        $adminCapPaise = $this->plan->adminChargeMonthlyCapPaise();
        $adminRateBp = $this->plan->adminChargeRateBp();
        $tdsRateBp = $this->plan->tdsRateBp();

        $allMonthlyTypes = self::monthlyCreditTypes();

        // Idempotent: one monthly batch per month-start date. whereDate() — the
        // date cast stores 'Y-m-d 00:00:00', so a bare where() on the string misses.
        $batch = PayoutBatch::whereDate('batch_date', $dateStr)
            ->where('batch_type', PayoutBatch::TYPE_MONTHLY)
            ->first();

        $wasCreated = false;
        if ($batch === null) {
            $batch = PayoutBatch::create([
                'batch_type' => PayoutBatch::TYPE_MONTHLY,
                'batch_date' => $dateStr,
                // The last day of the month this batch pays. Without it the
                // monthly batch had no earning window at all and swept income
                // earned after the month whose engines the completion gate had
                // certified (QA F48).
                'earnings_through' => $earnedThrough->toDateString(),
                'status' => PayoutBatch::STATUS_PENDING,
                // The maker half of maker-checker: whoever asked for this
                // batch cannot later approve it (QA F94).
                'created_by' => $this->batchCreatorId(),
            ]);
            $wasCreated = true;
        }

        if (in_array($batch->status, self::CLOSED_BATCH_STATUSES, true)
            || ($batch->status === PayoutBatch::STATUS_PENDING && $batch->processed_at !== null)) {
            return $batch;
        }

        $update = ['status' => PayoutBatch::STATUS_PROCESSING];

        // A re-run adds line items to a batch, which is making, not checking —
        // so an unattributed (scheduler-built) batch picks up the admin who
        // re-ran it as its maker. An existing maker is never overwritten: the
        // first hand on the batch is the one barred from approving it (QA F94).
        if ($batch->created_by === null && ($reRunMaker = $this->batchCreatorId()) !== null) {
            $update['created_by'] = $reRunMaker;
        }

        $batch->update($update);

        // See runWeeklyBatch(): re-read this batch's holds before adding to it.
        $this->releaseClearedHolds($batch);

        $distributorIds = WalletLedgerEntry::whereIn('type', $allMonthlyTypes)
            ->whereNull('swept_by_payout_batch_id')
            ->where('amount_paise', '>', 0)
            ->notReversed()
            ->earnedForMonthOrBefore($month)
            ->distinct()
            ->pluck('distributor_id');

        if ($wasCreated) {
            $this->auditBatchCreated($batch, $dateStr, $distributorIds->count());
        }

        $failedCount = 0;

        foreach ($distributorIds as $rawId) {
            $distributorId = (int) $rawId;

            try {
                $this->processMonthlyDistributor($batch, $distributorId, $month);
            } catch (Throwable $e) {
                // See runWeeklyBatch(): isolate the failure, keep paying the rest.
                Log::critical('Monthly payout batch: distributor failed — continuing with the rest', [
                    'payout_batch_id' => $batch->id,
                    'distributor_id' => $distributorId,
                    'exception' => $e,
                ]);
                $failedCount++;
            }
        }

        $this->finalizeBatchTotals($batch, $failedCount);

        if ($failedCount > 0) {
            Log::critical('Monthly payout batch finished partially failed', [
                'payout_batch_id' => $batch->id,
                'failed_distributor_count' => $failedCount,
            ]);
        }

        return $batch;
    }

    /**
     * One distributor's weekly line: the income gates, the ₹50L cap allocation,
     * the deductions and the sweep.
     *
     * Extracted from {@see runWeeklyBatch()} so a hold cleared after the batch
     * was built can be released into that same batch without duplicating any of
     * it ({@see releaseClearedHolds()}). The caller owns the try/catch that
     * keeps one distributor's failure from stranding the rest.
     */
    private function processWeeklyDistributor(PayoutBatch $batch, int $distributorId, Carbon $cycleEnd): void
    {
        $earnedThrough = PayoutBatch::weeklyEarningWindow($cycleEnd)['end'];
        $groupTypes = CompensationPlanSettingsService::GROUP_A_TYPES;
        $minPayoutPaise = $this->plan->minPayoutPaise();
        $adminCapPaise = $this->plan->adminChargeWeeklyCapPaise();
        $adminRateBp = $this->plan->adminChargeRateBp();
        $tdsRateBp = $this->plan->tdsRateBp();

        // Crash-resume guard: a prior partial run of this batch may already
        // have written this distributor's line item (paid lines are also
        // protected by the sweep marker, but WEB_ONLY / BELOW_MINIMUM lines
        // are not and would duplicate).
        if (PayoutLineItem::where('payout_batch_id', $batch->id)
            ->where('distributor_id', $distributorId)
            ->exists()) {
            return;
        }

        $holdStatus = $this->holdStatusFor($distributorId);

        if ($holdStatus !== null) {
            $this->holdLineItem(
                $batch, $distributorId, $groupTypes, self::WEEKLY_REPURCHASE_REF_TYPES,
                $holdStatus,
                self::holdFailureReason($holdStatus),
                earnedOnOrBefore: $earnedThrough,
            );

            return;
        }

        $bankLast4 = $this->bankLast4ForDistributor($distributorId);

        DB::transaction(function () use (
            $distributorId, $batch, $cycleEnd, $groupTypes, $bankLast4, $earnedThrough,
            $adminRateBp, $adminCapPaise, $tdsRateBp, $minPayoutPaise,
        ): void {
            // notReversed(): a credit an admin has reversed keeps its
            // `+gross` row so the statement still shows what was earned,
            // and would otherwise be swept and wired to the bank for a
            // bonus that no longer exists.
            //
            // earnedOnOrBefore(): only the earning week this batch pays.
            // Anything earned after it stays in the wallet for the next
            // Tuesday, credits and repurchase debits alike.
            $entries = WalletLedgerEntry::where('distributor_id', $distributorId)
                ->whereIn('type', $groupTypes)
                ->whereNull('swept_by_payout_batch_id')
                ->where('amount_paise', '>', 0)
                ->notReversed()
                ->earnedOnOrBefore($earnedThrough)
                ->lockForUpdate()
                ->get();

            $gsbSum = (int) $entries->where('type', 'gsb_credit')->sum('amount_paise');
            $mbSum = (int) $entries->where('type', 'mb_credit')->sum('amount_paise');

            if ($gsbSum + $mbSum <= 0) {
                return;
            }

            // ₹50L combined monthly cap (client 2026-06-26): the five cash
            // bonuses (GSB, MB, GBB, Rank, Fortune) share one gross ceiling
            // per EARNED month. Each credit is measured against the ceiling
            // of the month it was earned for, GSB-first; whatever exceeds it
            // is forfeited with an explicit ledger debit.
            $allocation = $this->allocateAgainstIncomeCap(
                $distributorId,
                $entries,
                ['gsb_credit', 'mb_credit'],
                $cycleEnd,
            );
            $gsbEffective = $allocation['effective']['gsb_credit'];
            $mbEffective = $allocation['effective']['mb_credit'];
            $gross = $gsbEffective + $mbEffective;
            $capForfeit = $allocation['forfeit'];

            // Repurchase was deducted at credit time: each gsb_credit has a
            // matching repurchase_transfer debit already in the main wallet.
            // Sweep those entries alongside the bonus credits so the balance
            // closes to zero; the payout_debit uses effectiveGross (post-
            // repurchase), not the full gross, to match what actually remains.
            $repurchaseTransfers = $this->unsweptRepurchaseTransfers($distributorId, self::WEEKLY_REPURCHASE_REF_TYPES, $earnedThrough)
                ->lockForUpdate()
                ->get();
            $repurchase = abs((int) $repurchaseTransfers->sum('amount_paise'));

            // Nothing at all is payable because the whole balance sits above
            // the ceiling of the month it was earned in. Record the forfeit
            // HERE, before the below-minimum branch below returns: leaving
            // the credits unswept re-offered them to the next batch, so a
            // distributor with ₹0 of room was paid in full next month while
            // one with ₹1 of room had the same amount forfeited outright.
            if ($gross <= 0 && $capForfeit > 0) {
                $this->forfeitLineItem($batch, $distributorId, $entries, $repurchaseTransfers, $gsbSum + $mbSum, $repurchase, ['gsb_credit', 'mb_credit'], $cycleEnd);

                return;
            }

            $effectiveGross = max(0, $gross - $repurchase);
            // Admin charge honours the per-bonus applies_to toggles. It is
            // levied on the gross but can only ever be collected out of what
            // actually remains in the wallet — clamping it here keeps
            // admin + TDS + net identical to the amount debited, so the line
            // item's arithmetic reconciles against the ledger.
            $adminCharge = min($effectiveGross, $this->adminChargeFor(
                [[BonusType::Gsb, $gsbEffective], [BonusType::Mentorship, $mbEffective]],
                $adminRateBp,
                $adminCapPaise,
            ));
            $payable = $effectiveGross - $adminCharge;
            // min(): a mis-set rate must not tax more than is payable and
            // push the net — and so the payout_debit — negative.
            $tds = min($payable, (int) round($payable * $tdsRateBp / 10_000));
            $net = $payable - $tds;

            if ($net < $minPayoutPaise) {
                PayoutLineItem::create([
                    'payout_batch_id' => $batch->id,
                    'distributor_id' => $distributorId,
                    'wallet_balance_paise' => $effectiveGross,
                    'gross_paise' => $gross,
                    'repurchase_deduction_paise' => $repurchase,
                    'admin_charge_paise' => $adminCharge,
                    'tds_paise' => $tds,
                    'net_transferred_paise' => max(0, $net),
                    'status' => PayoutLineItem::STATUS_BELOW_MINIMUM,
                ]);

                return;
            }

            // Sweep bonus credits AND their associated repurchase_transfer
            // debits in one pass so the main wallet balance closes to zero.
            WalletLedgerEntry::whereIn('id', $entries->merge($repurchaseTransfers)->pluck('id')->all())
                ->update(['swept_by_payout_batch_id' => $batch->id]);

            // The ledger enforces uniqueness on (type, reference_type,
            // reference_id), so the debit must reference this distributor's
            // LINE ITEM — referencing the shared batch id would collide on
            // the second paid distributor. Line item first, then the debit.
            $lineItem = PayoutLineItem::create([
                'payout_batch_id' => $batch->id,
                'distributor_id' => $distributorId,
                'wallet_balance_paise' => $effectiveGross,
                'gross_paise' => $gross,
                'repurchase_deduction_paise' => $repurchase,
                'admin_charge_paise' => $adminCharge,
                'tds_paise' => $tds,
                'net_transferred_paise' => $net,
                'bank_account_last4' => $bankLast4,
                'status' => PayoutLineItem::STATUS_PENDING,
            ]);

            // Three debits, not one: together they remove exactly
            // effectiveGross (what remains in the main wallet after the
            // credit-time repurchase), but the statement now says how much
            // of it went to the admin charge, how much to TDS, and how much
            // to the bank.
            $this->writePayoutDebits($distributorId, $lineItem->id, $adminCharge, $tds, $net, $adminRateBp, $tdsRateBp);

            // Credits above the monthly cap were swept with the rest, so an
            // explicit debit is needed or the excess lingers as a phantom
            // wallet balance forever. Attributed to the earned month whose
            // ceiling destroyed it, and audited, exactly as a wholly
            // forfeited line is.
            $this->writeIncomeCapForfeits(
                $batch,
                $lineItem,
                $distributorId,
                $allocation['forfeit_by_month'],
                $gsbSum + $mbSum,
                $repurchase,
                'the part of the balance above the combined monthly income cap of the month it was earned for is forfeited, not carried forward',
            );
        });
    }

    /**
     * One distributor's monthly line — the Group B/C/D counterpart of
     * {@see processWeeklyDistributor()}, extracted for the same reason.
     */
    private function processMonthlyDistributor(PayoutBatch $batch, int $distributorId, Carbon $month): void
    {
        $allMonthlyTypes = self::monthlyCreditTypes();
        $earnedThrough = $month->copy()->endOfMonth();
        $minPayoutPaise = $this->plan->minPayoutPaise();
        $adminCapPaise = $this->plan->adminChargeMonthlyCapPaise();
        $adminRateBp = $this->plan->adminChargeRateBp();
        $tdsRateBp = $this->plan->tdsRateBp();

        // Crash-resume guard — see runWeeklyBatch().
        if (PayoutLineItem::where('payout_batch_id', $batch->id)
            ->where('distributor_id', $distributorId)
            ->exists()) {
            return;
        }

        $holdStatus = $this->holdStatusFor($distributorId);

        if ($holdStatus !== null) {
            $this->holdLineItem(
                $batch, $distributorId, $allMonthlyTypes, self::MONTHLY_REPURCHASE_REF_TYPES,
                $holdStatus,
                self::holdFailureReason($holdStatus),
                earnedForMonthOrBefore: $month,
            );

            return;
        }

        $bankLast4 = $this->bankLast4ForDistributor($distributorId);

        DB::transaction(function () use (
            $distributorId, $batch, $allMonthlyTypes, $month, $bankLast4,
            $adminRateBp, $adminCapPaise, $tdsRateBp, $minPayoutPaise,
        ): void {
            // notReversed() — see runWeeklyBatch().
            $entries = WalletLedgerEntry::where('distributor_id', $distributorId)
                ->whereIn('type', $allMonthlyTypes)
                ->whereNull('swept_by_payout_batch_id')
                ->where('amount_paise', '>', 0)
                ->notReversed()
                // Only the month this batch pays. Income earned after it stays
                // in the wallet for the next monthly batch, credits and
                // repurchase debits alike — see earnedForMonthOrBefore().
                ->earnedForMonthOrBefore($month)
                ->lockForUpdate()
                ->get();

            if ($entries->isEmpty()) {
                return;
            }

            // Group B: GBB + Rank + Fortune — all five cash bonuses share
            // the combined ₹50L cap of the month each credit was EARNED
            // for (that month's weekly GSB/MB batches already consumed
            // part of the room). Fill the remaining room Fortune → GBB →
            // Rank, so rank (the largest pool) is forfeited first when the
            // cap is breached.
            $sumB = (int) $entries->whereIn('type', ['gbb_credit', 'rank_credit', 'fortune_credit'])->sum('amount_paise');
            $allocation = $this->allocateAgainstIncomeCap(
                $distributorId,
                $entries,
                ['fortune_credit', 'gbb_credit', 'rank_credit'],
                $month,
            );
            $fortuneEffective = $allocation['effective']['fortune_credit'];
            $gbbEffective = $allocation['effective']['gbb_credit'];
            $rankEffective = $allocation['effective']['rank_credit'];
            $grossB = $gbbEffective + $rankEffective + $fortuneEffective;
            $capForfeit = $allocation['forfeit'];

            // Group C: Awards.
            $grossC = (int) $entries->where('type', 'awards_credit')->sum('amount_paise');

            // Group D: ADC bonus (formerly also franchise commission).
            $grossD = (int) $entries->where('type', 'adc_credit')->sum('amount_paise');
            $grossAdc = $grossD;

            $gross = $grossB + $grossC + $grossD;

            // Repurchase was deducted at credit time for Group B bonuses
            // (GBB, Rank, Fortune). Sweep their repurchase_transfer debits
            // alongside the bonus credits; payout_debit uses effectiveGross
            // so the main wallet balance closes to zero exactly.
            // Awards (Group C) and ADC (Group D) carry no repurchase deduction.
            $repurchaseTransfers = $this->unsweptRepurchaseTransfers($distributorId, self::MONTHLY_REPURCHASE_REF_TYPES, earnedForMonthOrBefore: $month)
                ->lockForUpdate()
                ->get();
            $repurchase = abs((int) $repurchaseTransfers->sum('amount_paise'));

            // Everything this distributor earned sits above the ceiling of
            // the month it was earned in. Record the forfeit HERE, before
            // the silent return: credits left unswept were re-offered to the
            // next batch against a fresh ceiling — see runWeeklyBatch().
            if ($gross <= 0 && $capForfeit > 0) {
                $this->forfeitLineItem($batch, $distributorId, $entries, $repurchaseTransfers, $sumB, $repurchase, ['fortune_credit', 'gbb_credit', 'rank_credit'], $month);

                return;
            }

            if ($gross <= 0) {
                return;
            }

            // Per-group admin charge caps (each independent ₹25k ceiling).
            // Within each group the charge honours the per-bonus applies_to
            // toggles, so an exempt stream is excluded from the chargeable base.
            $adminB = $this->adminChargeFor([
                [BonusType::GrowthBooster, $gbbEffective],
                [BonusType::Rank, $rankEffective],
                [BonusType::Fortune, $fortuneEffective],
            ], $adminRateBp, $adminCapPaise);
            $adminC = $this->adminChargeFor([[BonusType::LifetimeAwards, $grossC]], $adminRateBp, $adminCapPaise);
            $adminD = $this->adminChargeFor([
                [BonusType::Arete, $grossAdc],
            ], $adminRateBp, $adminCapPaise);
            $adminCharge = $adminB + $adminC + $adminD;

            $effectiveGross = max(0, $gross - $repurchase);

            // Clamped to what is actually left in the wallet — see the
            // matching note in runWeeklyBatch(). The per-group figures are
            // scaled down with it so they still add up to what was taken,
            // which is what the result-row backfill apportions.
            if ($adminCharge > $effectiveGross) {
                [$adminB, $adminC, $adminD] = $this->apportion($effectiveGross, [$adminB, $adminC, $adminD]);
                $adminCharge = $adminB + $adminC + $adminD;
            }

            $payable = $effectiveGross - $adminCharge;

            // Group C (Lifetime Award cash) reaches the wallet already NET:
            // AdminLifetimeAwardsController takes both the admin charge and
            // the 5% TDS at delivery time, which is why
            // comp.admin_charge.applies_to_awards defaults to false. It has
            // to come out of the TDS base for the same reason, or the award
            // is taxed a second time on its way to the bank.
            $tdsBase = max(0, $payable - $grossC);
            // min(): see runWeeklyBatch() — never tax past what is payable.
            $tds = min($payable, (int) round($tdsBase * $tdsRateBp / 10_000));
            $net = $payable - $tds;

            if ($net < $minPayoutPaise) {
                PayoutLineItem::create([
                    'payout_batch_id' => $batch->id,
                    'distributor_id' => $distributorId,
                    'wallet_balance_paise' => $effectiveGross,
                    'gross_paise' => $gross,
                    'repurchase_deduction_paise' => $repurchase,
                    'admin_charge_paise' => $adminCharge,
                    'tds_paise' => $tds,
                    'net_transferred_paise' => max(0, $net),
                    'status' => PayoutLineItem::STATUS_BELOW_MINIMUM,
                ]);

                return;
            }

            // Sweep bonus credits AND their associated repurchase_transfer
            // debits so the main wallet balance closes to zero exactly.
            WalletLedgerEntry::whereIn('id', $entries->merge($repurchaseTransfers)->pluck('id')->all())
                ->update(['swept_by_payout_batch_id' => $batch->id]);

            // Line item first — the ledger's (type, reference_type,
            // reference_id) unique index requires the debit to reference
            // this distributor's line item, not the shared batch id.
            $lineItem = PayoutLineItem::create([
                'payout_batch_id' => $batch->id,
                'distributor_id' => $distributorId,
                'wallet_balance_paise' => $effectiveGross,
                'gross_paise' => $gross,
                'repurchase_deduction_paise' => $repurchase,
                'admin_charge_paise' => $adminCharge,
                'tds_paise' => $tds,
                'net_transferred_paise' => $net,
                'bank_account_last4' => $bankLast4,
                'status' => PayoutLineItem::STATUS_PENDING,
            ]);

            // Three debits summing to effectiveGross — see runWeeklyBatch().
            $this->writePayoutDebits($distributorId, $lineItem->id, $adminCharge, $tds, $net, $adminRateBp, $tdsRateBp);

            // Credits above the monthly cap are forfeited, not carried:
            // their entries were swept above, so an explicit debit is needed
            // or the excess would linger as a phantom wallet balance forever.
            // One debit per earned month, plus the audit row — see
            // runWeeklyBatch().
            $this->writeIncomeCapForfeits(
                $batch,
                $lineItem,
                $distributorId,
                $allocation['forfeit_by_month'],
                $sumB,
                $repurchase,
                'the part of the balance above the combined monthly income cap of the month it was earned for is forfeited, not carried forward',
            );
        });
    }

    /**
     * The single ladder of income gates every payout line is measured against,
     * in the order they are applied — or null when the distributor's money may
     * go to the bank.
     *
     * It lives in one place because it is asked twice: once when the batch is
     * built, and again when the batch is approved ({@see releaseClearedHolds()}).
     * Before that second reading, a hold recorded at creation was frozen — a
     * distributor who supplied bank details and passed KYC the same week was
     * still recorded as "No bank account" in the batch an admin then approved,
     * so the audit trail asserted something that was no longer true and the
     * money waited for the next batch (QA F93).
     *
     *   web_only            — personal BV below the NEFT minimum (Retailer gate).
     *   kyc_pending         — KYC not verified (users.status !== 'active'). Income
     *                         accrues and stays fully visible; only the bank
     *                         release waits. Partner instruction 2026-07-08.
     *   no_bank_account     — no bank account on file. Registration promises no
     *                         commission is released until one is, and without
     *                         this gate approve() would record an impossible NEFT.
     *   bank_decrypt_failed — a bank account is on file but its ciphertext will
     *                         not decrypt (LOG-2). The critical log has already
     *                         fired inside bankLast4ForDistributor().
     *
     * In every case the wallet is neither debited nor swept, so the first batch
     * after the block clears pays the balance out.
     *
     * The Action Center's `PayoutsBankDetailsMissingProvider` mirrors the
     * web_only → kyc_pending → no_bank_account portion of this ladder in SQL,
     * against unswept ledger credits rather than line items — change both.
     */
    private function holdStatusFor(int $distributorId): ?string
    {
        if ($this->bvLedger->totalPersonalBvPaise($distributorId) < $this->plan->neftMinBvPaise()) {
            return PayoutLineItem::STATUS_WEB_ONLY;
        }

        if (! $this->isKycVerified($distributorId)) {
            return PayoutLineItem::STATUS_KYC_PENDING;
        }

        if (! $this->hasBankAccountOnFile($distributorId)) {
            return PayoutLineItem::STATUS_NO_BANK_ACCOUNT;
        }

        try {
            $this->bankLast4ForDistributor($distributorId);
        } catch (BankDecryptionException) {
            return PayoutLineItem::STATUS_BANK_DECRYPT_FAILED;
        }

        return null;
    }

    /** The line item's `failure_reason` for a hold status, where one adds anything. */
    private static function holdFailureReason(string $holdStatus): ?string
    {
        return $holdStatus === PayoutLineItem::STATUS_BANK_DECRYPT_FAILED
            ? 'Bank account on file could not be decrypted — re-capture bank details.'
            : null;
    }

    /**
     * Every line's status and hold reason on a batch, for the before/after
     * digests of a hold re-evaluation.
     *
     * @return array<int, array{status: string, failure_reason: string|null}>
     */
    private function holdState(PayoutBatch $batch): array
    {
        return PayoutLineItem::where('payout_batch_id', $batch->id)
            ->orderBy('id')
            ->get(['id', 'status', 'failure_reason'])
            ->mapWithKeys(fn (PayoutLineItem $line): array => [(int) $line->id => [
                'status' => (string) $line->status,
                'failure_reason' => $line->failure_reason,
            ]])
            ->all();
    }

    /**
     * Re-read every hold on a batch and act on what has changed since the batch
     * was built: a hold that has cleared is replaced by a real, payable line in
     * this same batch, and a hold that still stands but for a different reason
     * is restated.
     *
     * Holds used to be frozen at creation. A distributor who added bank details
     * and passed KYC after the batch was generated was carried into approval —
     * and into the batch's audit record — as "No bank account", and their money
     * waited for the next batch even though nothing was blocking it any more
     * (QA F93). Nothing was ever mis-paid, because held income is never debited
     * or swept; the record was simply wrong and the release late.
     *
     * Called when a batch is (re-)run and again at approval, which is the last
     * moment the recorded state can still be made true.
     *
     * @return array{released: int, restated: int}
     */
    private function releaseClearedHolds(PayoutBatch $batch): array
    {
        $held = PayoutLineItem::where('payout_batch_id', $batch->id)
            ->whereIn('status', PayoutLineItem::HELD_STATUSES)
            ->orderBy('id')
            ->get();

        $holdsBefore = $this->holdState($batch);

        $released = 0;
        $restated = 0;
        /** @var list<array{distributor_id: int, from: string, to: string}> $changes */
        $changes = [];

        foreach ($held as $line) {
            $distributorId = (int) $line->distributor_id;
            $holdStatus = $this->holdStatusFor($distributorId);

            if ($holdStatus === $line->status) {
                continue;
            }

            $from = (string) $line->status;

            try {
                if ($holdStatus !== null) {
                    // Still held, different reason. Restate it rather than
                    // leaving the batch asserting a block that has been lifted.
                    $line->forceFill([
                        'status' => $holdStatus,
                        'failure_reason' => self::holdFailureReason($holdStatus),
                    ])->save();

                    $changes[] = ['distributor_id' => $distributorId, 'from' => $from, 'to' => $holdStatus];
                    $restated++;

                    continue;
                }

                DB::transaction(function () use ($line, $batch, $distributorId): void {
                    // The held line carried no debit and swept nothing, so
                    // deleting it loses no ledger fact; the replacement is
                    // written by exactly the code that builds every other line.
                    $line->delete();

                    $batch->batch_type === PayoutBatch::TYPE_MONTHLY
                        ? $this->processMonthlyDistributor($batch, $distributorId, $batch->batch_date)
                        : $this->processWeeklyDistributor($batch, $distributorId, $batch->batch_date);
                });

                $changes[] = ['distributor_id' => $distributorId, 'from' => $from, 'to' => 'released'];
                $released++;
            } catch (Throwable $e) {
                // One distributor's release must not stop the rest, and must
                // never block an approval: the transaction rolled back, so the
                // line is exactly as it was.
                Log::critical('Payout batch: re-evaluating a hold failed — leaving the line as it was', [
                    'payout_batch_id' => $batch->id,
                    'distributor_id' => $distributorId,
                    'exception' => $e,
                ]);
            }
        }

        if ($changes !== []) {
            AuditLog::create([
                'actor_id' => Auth::id(),
                'action' => 'payout.batch.holds_reevaluated',
                'subject_type' => 'payout_batch',
                'subject_id' => (int) $batch->id,
                'before_hash' => AuditDigests::of($holdsBefore),
                'after_hash' => AuditDigests::of($this->holdState($batch)),
                'details' => [
                    'batch_type' => $batch->batch_type,
                    'batch_date' => $batch->batch_date->toDateString(),
                    'released' => $released,
                    'restated' => $restated,
                    'changes' => array_slice($changes, 0, 200),
                ],
                'ip' => app()->runningInConsole() ? null : request()->ip(),
            ]);
        }

        return ['released' => $released, 'restated' => $restated];
    }

    /**
     * Record a distributor whose income is held in the wallet this batch —
     * web-only, KYC pending, no bank account, bank details undecryptable.
     * Nothing is debited or swept; the line exists so admins can see the
     * money and why it did not move.
     *
     * Repurchase is deducted at credit time, so the held credits already have
     * their `repurchase_transfer` debits sitting unswept beside them. The line
     * reports that deduction and a wallet balance of gross minus it — what is
     * actually left in the main wallet — rather than pretending nothing was
     * withheld. Admin charge and TDS are payout-time deductions and stay zero
     * until the money actually leaves.
     *
     * `$earnedOnOrBefore` is the weekly batch's earning week and
     * `$earnedForMonthOrBefore` the monthly batch's month. A held line must
     * report the same window the paying path would have swept, or a distributor
     * whose KYC is pending sees a held figure that includes income this batch
     * was never due to pay.
     *
     * @param  list<string>  $creditTypes
     * @param  list<string>  $repurchaseRefTypes
     */
    private function holdLineItem(
        PayoutBatch $batch,
        int $distributorId,
        array $creditTypes,
        array $repurchaseRefTypes,
        string $status,
        ?string $failureReason = null,
        ?Carbon $earnedOnOrBefore = null,
        ?Carbon $earnedForMonthOrBefore = null,
    ): void {
        $gross = $this->wallet->sumUnsweptByTypes($distributorId, $creditTypes, $earnedOnOrBefore, $earnedForMonthOrBefore);

        if ($gross <= 0) {
            return;
        }

        $repurchase = abs((int) $this->unsweptRepurchaseTransfers($distributorId, $repurchaseRefTypes, $earnedOnOrBefore, $earnedForMonthOrBefore)->sum('amount_paise'));

        PayoutLineItem::create([
            'payout_batch_id' => $batch->id,
            'distributor_id' => $distributorId,
            'wallet_balance_paise' => max(0, $gross - $repurchase),
            'gross_paise' => $gross,
            'repurchase_deduction_paise' => $repurchase,
            'admin_charge_paise' => 0,
            'tds_paise' => 0,
            'net_transferred_paise' => 0,
            'status' => $status,
            'failure_reason' => $failureReason,
        ]);
    }

    /**
     * Record a distributor whose entire batch balance sits above the combined
     * monthly income cap of the month it was earned in: the credits and their
     * credit-time repurchase debits are swept, an `income_cap_forfeit` debit
     * closes the main wallet, and the batch carries a line saying so.
     *
     * Sweeping is the whole point. Left unswept — which is what both silent
     * returns used to do — the same credits were re-offered by the next batch
     * against a fresh ceiling, so ₹0 of room paid in full next month what ₹1 of
     * room forfeited outright.
     *
     * The forfeit debit is the gross MINUS the repurchase share, because that
     * share left the main wallet at credit time and is already sitting in the
     * repurchase wallet; debiting the full gross would push the balance
     * negative. A forfeited bonus does not return its repurchase deduction.
     *
     * One debit is written per EARNED month, each stamped with its own
     * `bonus_month`. The ceiling this forfeit was measured against belongs to
     * the month the income was earned for, so a debit that cannot name that
     * month cannot be reconciled against the decision that destroyed it — and a
     * batch routinely sweeps credits from more than one month.
     *
     * The forfeit permanently destroys income, so it also gets a
     * retention-guaranteed `audit_log` row, not only the line item (R-35).
     *
     * @param  EloquentCollection<int, WalletLedgerEntry>  $entries
     * @param  EloquentCollection<int, WalletLedgerEntry>  $repurchaseTransfers
     * @param  list<string>  $cappedTypes  the credit types the cap allocated
     */
    private function forfeitLineItem(
        PayoutBatch $batch,
        int $distributorId,
        EloquentCollection $entries,
        EloquentCollection $repurchaseTransfers,
        int $grossPaise,
        int $repurchasePaise,
        array $cappedTypes,
        Carbon $fallbackMonth,
    ): void {
        WalletLedgerEntry::whereIn('id', $entries->merge($repurchaseTransfers)->pluck('id')->all())
            ->update(['swept_by_payout_batch_id' => $batch->id]);

        $lineItem = PayoutLineItem::create([
            'payout_batch_id' => $batch->id,
            'distributor_id' => $distributorId,
            'wallet_balance_paise' => 0,
            'gross_paise' => $grossPaise,
            'repurchase_deduction_paise' => $repurchasePaise,
            'admin_charge_paise' => 0,
            'tds_paise' => 0,
            'net_transferred_paise' => 0,
            'status' => PayoutLineItem::STATUS_INCOME_CAP_FORFEITED,
            'failure_reason' => 'Entire balance above the combined monthly income cap of the month it was earned for.',
        ]);

        $fallback = $fallbackMonth->copy()->startOfMonth()->toDateString();

        /** @var array<string, int> $grossByMonth */
        $grossByMonth = [];
        /** @var array<string, int> $repurchaseByMonth */
        $repurchaseByMonth = [];

        foreach ($entries as $entry) {
            if (! in_array($entry->type, $cappedTypes, true)) {
                continue;
            }

            $key = $entry->bonus_month?->toDateString() ?? $fallback;
            $grossByMonth[$key] = ($grossByMonth[$key] ?? 0) + abs((int) $entry->amount_paise);
        }

        foreach ($repurchaseTransfers as $transfer) {
            $key = $transfer->bonus_month?->toDateString() ?? $fallback;
            $repurchaseByMonth[$key] = ($repurchaseByMonth[$key] ?? 0) + abs((int) $transfer->amount_paise);
        }

        /** @var array<string, int> $forfeitByMonth */
        $forfeitByMonth = [];

        foreach ($grossByMonth as $earnedMonth => $monthGross) {
            // Never below zero: a month's repurchase deduction is a fraction of
            // that month's own gross, so the subtraction cannot invert.
            $forfeitByMonth[$earnedMonth] = max(0, $monthGross - ($repurchaseByMonth[$earnedMonth] ?? 0));
        }

        $this->writeIncomeCapForfeits(
            $batch,
            $lineItem,
            $distributorId,
            $forfeitByMonth,
            $grossPaise,
            $repurchasePaise,
            'the whole balance sat above the combined monthly income cap of the month it was earned for; it is forfeited, not carried forward',
        );
    }

    /**
     * Write a line item's `income_cap_forfeit` debits — one per EARNED month,
     * each stamped with its own `bonus_month` — and the `audit_log` row saying
     * income was permanently destroyed.
     *
     * One debit per month rather than one per line item: the ceiling a forfeit
     * was measured against belongs to the month the income was earned for, so a
     * debit that cannot name that month cannot be reconciled against the
     * decision that destroyed it — and a batch routinely settles credits from
     * more than one month.
     *
     * The month goes into `reference_type` as well as `bonus_month` because
     * `uniq_wallet_ledger_source (type, reference_type, reference_id)` covers
     * the debit's identity: a bare `payout_line_item` would let the first month
     * through and reject every month after it on the same line item. Same
     * disambiguating-suffix mechanism as
     * {@see WalletService::REVERSAL_REFERENCE_SUFFIX}.
     *
     * The forfeit destroys income permanently, so it is a retention-guaranteed
     * audit fact and not only a line item (R-35).
     *
     * @param  array<string, int>  $forfeitByMonth  'Y-m-d' first-of-month => paise forfeited
     */
    private function writeIncomeCapForfeits(
        PayoutBatch $batch,
        PayoutLineItem $lineItem,
        int $distributorId,
        array $forfeitByMonth,
        int $grossPaise,
        int $repurchasePaise,
        string $reason,
    ): void {
        $forfeitByMonth = array_filter($forfeitByMonth, static fn (int $paise): bool => $paise > 0);

        if ($forfeitByMonth === []) {
            return;
        }

        ksort($forfeitByMonth);

        foreach ($forfeitByMonth as $earnedMonth => $monthForfeit) {
            $this->wallet->debit(
                distributorId: $distributorId,
                amountPaise: $monthForfeit,
                type: 'income_cap_forfeit',
                referenceId: $lineItem->id,
                referenceType: self::FORFEIT_REFERENCE_PREFIX.$earnedMonth,
                memo: 'Cash income above the combined monthly income cap',
                bonusMonth: Carbon::createFromFormat('Y-m-d', $earnedMonth)->startOfDay(),
            );
        }

        AuditLog::create([
            'action' => 'payout.income_cap_forfeited',
            'subject_type' => 'distributor',
            'subject_id' => $distributorId,
            'before_hash' => AuditDigests::of([
                'gross_paise' => $grossPaise,
                'repurchase_deduction_paise' => $repurchasePaise,
            ]),
            'after_hash' => AuditDigests::of([
                'forfeited_paise' => array_sum($forfeitByMonth),
                'forfeited_by_earned_month' => $forfeitByMonth,
            ]),
            'details' => [
                'payout_batch_id' => $batch->id,
                'payout_line_item_id' => $lineItem->id,
                'gross_paise' => $grossPaise,
                'repurchase_deduction_paise' => $repurchasePaise,
                'forfeited_paise' => array_sum($forfeitByMonth),
                'forfeited_by_earned_month' => $forfeitByMonth,
                'reason' => $reason,
            ],
        ]);
    }

    /**
     * Allocate a batch's capped cash-bonus credits against the ₹50L combined
     * ceiling, one ceiling per EARNED month.
     *
     * A batch routinely carries credits from more than one month: anything
     * deferred by the ₹100 minimum, held for KYC or a missing bank account
     * rolls forward, and the monthly engines all credit the month that just
     * closed. Each of those credits belongs to the ceiling of its own
     * `bonus_month`, so they are grouped by it (oldest first — the longest-held
     * income settles first) and each group meets only the room its own month
     * has left. Credits written before `bonus_month` existed fall back to the
     * batch's month, which is what the old batch-window measurement assumed.
     *
     * Within a month the `$priority` order decides who gets the remaining room
     * and who is forfeited.
     *
     * `forfeit_by_month` carries the same total as `forfeit`, broken down by the
     * earned month whose ceiling destroyed it — the attribution the forfeit
     * debits and the audit row are written from.
     *
     * @param  EloquentCollection<int, WalletLedgerEntry>  $entries
     * @param  list<string>  $priority  capped credit types, highest priority first
     * @return array{effective: array<string, int>, forfeit: int, forfeit_by_month: array<string, int>}
     */
    private function allocateAgainstIncomeCap(
        int $distributorId,
        EloquentCollection $entries,
        array $priority,
        Carbon $batchDate,
    ): array {
        $capPaise = $this->plan->monthlyIncomeCapPaise();
        $fallbackMonth = $batchDate->copy()->startOfMonth()->toDateString();

        $effective = array_fill_keys($priority, 0);
        $forfeit = 0;
        /** @var array<string, int> $forfeitByMonth */
        $forfeitByMonth = [];

        $byEarnedMonth = $entries
            ->whereIn('type', $priority)
            ->groupBy(fn (WalletLedgerEntry $entry): string => $entry->bonus_month?->toDateString() ?? $fallbackMonth)
            ->sortKeys();

        foreach ($byEarnedMonth as $earnedMonth => $monthEntries) {
            $earnedMonth = (string) $earnedMonth;

            $room = max(0, $capPaise - $this->monthToDateCappedGrossPaise(
                $distributorId,
                Carbon::createFromFormat('Y-m-d', $earnedMonth)->startOfDay(),
            ));

            foreach ($priority as $type) {
                $sum = (int) $monthEntries->where('type', $type)->sum('amount_paise');
                $taken = min($sum, $room);

                $effective[$type] += $taken;
                $forfeit += $sum - $taken;
                $forfeitByMonth[$earnedMonth] = ($forfeitByMonth[$earnedMonth] ?? 0) + ($sum - $taken);
                $room -= $taken;
            }
        }

        return [
            'effective' => $effective,
            'forfeit' => $forfeit,
            'forfeit_by_month' => array_filter($forfeitByMonth, static fn (int $paise): bool => $paise > 0),
        ];
    }

    /**
     * The credit-time repurchase debits that belong to this batch group and
     * have not yet been swept by a payout — the deduction a line item reports,
     * and the entries a paying line sweeps alongside its credits.
     *
     * Transfers belonging to a reversed bonus are excluded, exactly as the
     * bonus credits themselves are: the reversal already put that deduction
     * back, and sweeping the debit without its credit would understate the
     * payout by the deduction.
     *
     * `$earnedOnOrBefore` narrows to the weekly batch's earning week, and
     * `$earnedForMonthOrBefore` to the monthly batch's month, for the same
     * reason: a transfer swept without
     * its credit — which is still waiting for next Tuesday — would take the
     * deduction out of a payout that never included the bonus it belongs to.
     * The three rows are written together and carry the same day.
     *
     * @param  list<string>  $refTypes
     * @return Builder<WalletLedgerEntry>
     */
    private function unsweptRepurchaseTransfers(int $distributorId, array $refTypes, ?Carbon $earnedOnOrBefore = null, ?Carbon $earnedForMonthOrBefore = null): Builder
    {
        return WalletLedgerEntry::where('distributor_id', $distributorId)
            ->where('type', 'repurchase_transfer')
            ->whereIn('reference_type', $refTypes)
            ->whereNull('swept_by_payout_batch_id')
            ->notReversed()
            ->when($earnedOnOrBefore !== null, fn (Builder $q) => $q->earnedOnOrBefore($earnedOnOrBefore))
            ->when($earnedForMonthOrBefore !== null, fn (Builder $q) => $q->earnedForMonthOrBefore($earnedForMonthOrBefore));
    }

    /**
     * Batch rollup computed from the actual persisted line items, not from
     * in-memory accumulators — a crash-resumed run therefore reports the full
     * batch, not just the distributors processed after the restart.
     *
     * Gross and deductions cover every line item, held ones included: that is
     * the income the batch looked at and what was withheld from it. Net and
     * distributor count cover only the lines going to the bank — approve()
     * confirms "₹X to N distributors" from them, so a batch of held lines
     * reads as ₹0 to 0 distributors while still showing its gross.
     *
     * A batch with any per-distributor failure lands in `partially_failed`
     * instead of `pending`: it cannot be approved (approve() only accepts
     * pending) and the run command exits non-zero, but a re-run retries only
     * the failed distributors and promotes the batch to pending on success.
     */
    private function finalizeBatchTotals(PayoutBatch $batch, int $failedCount = 0): void
    {
        $all = PayoutLineItem::where('payout_batch_id', $batch->id)
            ->selectRaw('COALESCE(SUM(gross_paise),0) AS gross, COALESCE(SUM(repurchase_deduction_paise + admin_charge_paise + tds_paise),0) AS deductions')
            ->first();

        $paid = PayoutLineItem::where('payout_batch_id', $batch->id)
            ->where('status', PayoutLineItem::STATUS_PENDING)
            ->selectRaw('COALESCE(SUM(net_transferred_paise),0) AS net, COUNT(*) AS cnt')
            ->first();

        $grossPaise = (int) $all->gross;
        $netPaise = (int) $paid->net;
        $distributorCount = (int) $paid->cnt;

        $before = AuditDigests::of($batch);

        $batch->update([
            'status' => $failedCount > 0
                ? PayoutBatch::STATUS_PARTIALLY_FAILED
                : PayoutBatch::STATUS_PENDING,
            'total_gross_paise' => $grossPaise,
            'total_deductions_paise' => (int) $all->deductions,
            'total_net_paise' => $netPaise,
            'distributor_count' => $distributorCount,
            'processed_at' => now(),
        ]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'payout.batch.finalised',
            'subject_type' => 'payout_batch',
            'subject_id' => $batch->id,
            'before_hash' => $before,
            'after_hash' => AuditDigests::of($batch),
            'details' => [
                'batch_id' => $batch->id,
                'batch_type' => $batch->batch_type,
                'total_gross_paise' => $grossPaise,
                'total_net_paise' => $netPaise,
                'distributor_count' => $distributorCount,
                'failed_distributor_count' => $failedCount,
                'status' => $batch->status,
            ],
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    /**
     * LOG-3: an audit_log row the moment a payout batch comes into existence —
     * only on true creation, never on a crash-resume re-entry.
     */
    private function auditBatchCreated(PayoutBatch $batch, string $period, int $distributorCount): void
    {
        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'payout.batch.created',
            'subject_type' => 'payout_batch',
            'subject_id' => $batch->id,
            // A creation has no before-state: before_hash stays NULL.
            'before_hash' => null,
            'after_hash' => AuditDigests::of($batch),
            'details' => [
                'batch_id' => $batch->id,
                'period' => $period,
                'batch_type' => $batch->batch_type,
                'distributor_count' => $distributorCount,
            ],
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    /**
     * How much of one month's ₹50L combined ceiling a distributor has already
     * used up: the gross of the five capped cash-bonus streams (GSB, MB, GBB,
     * Rank, Fortune) that a payout batch has already settled — paid out or
     * forfeited — for that month.
     *
     * A credit counts under the month it was EARNED for (`bonus_month`), not
     * the month a batch happened to sweep it. Measuring by the sweeping batch
     * is what let a deferred credit meet a fresh ceiling the following month:
     * the room reset while the income did not.
     *
     * Rows written before `bonus_month` existed carry no earned month, so they
     * keep answering under the batch that swept them and historical batches
     * report exactly what they reported before. This is the same
     * window-with-fallback shape as
     * {@see WalletService::repurchaseDeductionForMonthPaise()}, so the two
     * monthly ceilings agree on what a month is.
     *
     * Only swept rows count. Unswept credits are the ones being measured
     * against the ceiling, not consumption already recorded against it.
     *
     * A reversed bonus consumes nothing: the income no longer exists, so it
     * must not go on holding room against the ceiling of the month it was
     * earned for. This holds whether the reversal came before the credit could
     * be swept or after it was already paid out.
     */
    private function monthToDateCappedGrossPaise(int $distributorId, Carbon $month): int
    {
        // The month is an IST calendar month by definition; anchor it in IST
        // rather than converting the caller's instant, so a value that arrives
        // as a bare date can never slide into the neighbouring month.
        $monthIst = Carbon::createFromFormat('Y-m-d H:i:s', $month->format('Y-m-01').' 00:00:00', 'Asia/Kolkata');

        $batchIds = PayoutBatch::whereBetween('batch_date', [
            $monthIst->copy()->startOfMonth()->format('Y-m-d 00:00:00'),
            $monthIst->copy()->endOfMonth()->format('Y-m-d 23:59:59'),
        ])->pluck('id');

        return (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->whereIn('type', CompensationPlanSettingsService::MONTHLY_CAP_TYPES)
            ->where('amount_paise', '>', 0)
            ->whereNotNull('swept_by_payout_batch_id')
            ->notReversed()
            ->where(function ($query) use ($monthIst, $batchIds): void {
                $query->whereDate('bonus_month', $monthIst->toDateString())
                    ->orWhere(function ($legacy) use ($batchIds): void {
                        $legacy->whereNull('bonus_month')
                            ->whereIn('swept_by_payout_batch_id', $batchIds);
                    });
            })
            ->sum('amount_paise');
    }

    /**
     * Serialise everything that sweeps the wallet against the shared monthly
     * income ceiling.
     *
     * The weekly batch (Tuesday 03:00), the monthly close (the 8th, 04:00) and
     * now approval all read how much of a month's ₹50L combined ceiling a
     * distributor has already used and then sweep against it. Nothing but the
     * clock kept them apart (QA F45): a manual run from the Engine Runs page on
     * a Tuesday the 8th, or a batch that overran its hour, had two sweeps
     * reading the same remaining room and both spending it — the distributor is
     * then paid over a cap that is supposed to be shared across five bonuses.
     *
     * Blocking rather than refusing: a batch that will not start is a batch
     * that does not pay. The second caller waits and then sweeps what is left.
     * The TTL bounds how long a crashed run can hold every other payout; the
     * wait bounds how long a caller queues before a LockTimeoutException, which
     * the run commands already turn into a failed batch and a non-zero exit.
     *
     * @param  callable(): PayoutBatch  $sweep
     */
    private function withSweepLock(callable $sweep): PayoutBatch
    {
        return Cache::lock(self::SWEEP_LOCK_KEY, self::SWEEP_LOCK_TTL_SECONDS)
            ->block(self::SWEEP_LOCK_WAIT_SECONDS, $sweep);
    }

    /**
     * Admin-initiated approval. What follows depends on how payouts leave the
     * company (`payout.gateway`):
     *
     *   razorpay    — the batch goes to `dispatched` and a queued job hands
     *                 every line item to RazorpayX. Line items stay `pending`
     *                 until each payout webhook confirms settlement.
     *   manual_neft — the batch goes to `approved` and waits. Finance
     *                 downloads the NEFT CSV, uploads it to the bank, and
     *                 imports the bank's response file, which is what marks
     *                 each line transferred or failed.
     *
     * In neither mode does approval itself mark a line transferred. It used
     * to, which meant "an admin clicked a button" and "the distributor has
     * the money" were the same recorded fact — they are not.
     */
    public function approve(PayoutBatch $batch, int $approvedByUserId): PayoutBatch
    {
        if ($batch->status !== PayoutBatch::STATUS_PENDING) {
            return $batch;
        }

        // Approval can now sweep the ledger itself, because a hold that has
        // cleared is released into this batch. That makes it a third writer
        // against the shared monthly ceiling, so it queues behind the batch
        // runs on the same lock.
        return $this->withSweepLock(fn (): PayoutBatch => $this->approveLocked($batch, $approvedByUserId));
    }

    /** {@see approve()} — its body, run while the sweep lock is held. */
    private function approveLocked(PayoutBatch $batch, int $approvedByUserId): PayoutBatch
    {
        // Last chance to make the recorded state true. A distributor whose KYC
        // was approved or whose bank details arrived after the batch was built
        // is paid by THIS batch instead of waiting for the next one, and a hold
        // that still stands is restated — approval must not sign off a reason
        // that stopped being true days ago (QA F93).
        $reevaluated = $this->releaseClearedHolds($batch);

        if ($reevaluated['released'] > 0 || $reevaluated['restated'] > 0) {
            // Totals and the paying-distributor count changed under the batch;
            // the confirmation an admin just saw is re-derived from the lines
            // that now exist before it is signed off.
            $this->finalizeBatchTotals($batch);
            $batch->refresh();
        }

        $razorpay = $this->payoutSettings->isRazorpay();
        $before = AuditDigests::of($batch);

        DB::transaction(function () use ($batch, $approvedByUserId, $razorpay): void {
            $batch->update([
                'status' => $razorpay ? PayoutBatch::STATUS_DISPATCHED : PayoutBatch::STATUS_APPROVED,
                'approved_by' => $approvedByUserId,
                'approved_at' => now(),
            ]);
        });

        AuditLog::create([
            'actor_id' => $approvedByUserId,
            'action' => 'payout.batch.approved',
            'subject_type' => 'payout_batch',
            'subject_id' => (int) $batch->id,
            'before_hash' => $before,
            'after_hash' => AuditDigests::of($batch),
            'details' => [
                'batch_type' => $batch->batch_type,
                'batch_date' => $batch->batch_date->toDateString(),
                'gateway' => $this->payoutSettings->gateway(),
                'status' => $batch->status,
                'distributor_count' => $batch->distributor_count,
                'total_net_paise' => $batch->total_net_paise,
                'holds_released_at_approval' => $reevaluated['released'],
                'holds_restated_at_approval' => $reevaluated['restated'],
            ],
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);

        if ($razorpay) {
            // Dispatched outside the transaction: the compensation worker must
            // not pick the job up before the batch row it reads is committed.
            DispatchRazorpayPayoutsJob::dispatch((int) $batch->id, $approvedByUserId);
        }

        return $batch->refresh();
    }

    /**
     * Lifetime money actually settled to a distributor's bank: the sum of every
     * transferred line item's net, after repurchase, admin charge and TDS.
     * Pending and failed lines are excluded — nothing left the company for them.
     *
     * The wallet page's "Total paid out" and the ID card's "Total Withdrawal
     * Income" both read this, so the status filter lives in one place.
     */
    public function totalTransferredPaise(int $distributorId): int
    {
        return (int) PayoutLineItem::where('distributor_id', $distributorId)
            ->where('status', PayoutLineItem::STATUS_TRANSFERRED)
            ->sum('net_transferred_paise');
    }

    /**
     * Admin charge for a set of bonus streams: the rate is levied only on the
     * grosses whose per-bonus `applies_to` toggle is ON (admins can exempt a
     * stream from the admin charge), then the total is capped.
     *
     * @param  list<array{0: BonusType, 1: int}>  $streams  [bonus type, gross paise] pairs
     */
    private function adminChargeFor(array $streams, int $rateBp, int $capPaise): int
    {
        $chargeableBase = 0;
        foreach ($streams as [$type, $gross]) {
            if ($this->plan->adminChargeAppliesTo($type)) {
                $chargeableBase += $gross;
            }
        }

        return (int) min((int) round($chargeableBase * $rateBp / 10_000), $capPaise);
    }

    /**
     * The wallet side of a paid line item: the admin charge, the TDS and the
     * net bank transfer as three separate debits that together remove exactly
     * the post-repurchase balance.
     *
     * Each references the LINE ITEM rather than the batch — the ledger's unique
     * index on (type, reference_type, reference_id) would otherwise collide on
     * the second distributor in the batch.
     */
    private function writePayoutDebits(
        int $distributorId,
        int $lineItemId,
        int $adminChargePaise,
        int $tdsPaise,
        int $netPaise,
        int $adminRateBp,
        int $tdsRateBp,
    ): void {
        if ($adminChargePaise > 0) {
            $this->wallet->debit(
                distributorId: $distributorId,
                amountPaise: $adminChargePaise,
                type: 'admin_charge_debit',
                referenceId: $lineItemId,
                referenceType: 'payout_line_item',
                memo: 'Admin charge ('.$this->rateLabel($adminRateBp).')',
            );
        }

        if ($tdsPaise > 0) {
            $this->wallet->debit(
                distributorId: $distributorId,
                amountPaise: $tdsPaise,
                type: 'tds_debit',
                referenceId: $lineItemId,
                referenceType: 'payout_line_item',
                memo: 'TDS ('.$this->rateLabel($tdsRateBp).')',
            );
        }

        $this->wallet->debit(
            distributorId: $distributorId,
            amountPaise: $netPaise,
            type: 'payout_debit',
            referenceId: $lineItemId,
            referenceType: 'payout_line_item',
        );
    }

    /**
     * Split a pooled deduction across weighted rows so the parts sum to exactly
     * the pool: floor every share, then hand the rounding remainder out to the
     * largest fractional parts first. A pool with no weight behind it (every
     * weight zero) is spread evenly rather than lost.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    private function apportion(int $poolPaise, array $weights): array
    {
        $count = count($weights);

        if ($count === 0 || $poolPaise <= 0) {
            return array_fill(0, max(0, $count), 0);
        }

        $totalWeight = array_sum($weights);

        if ($totalWeight <= 0) {
            $weights = array_fill(0, $count, 1);
            $totalWeight = $count;
        }

        $shares = [];
        $remainders = [];
        foreach ($weights as $index => $weight) {
            $exact = $poolPaise * $weight / $totalWeight;
            $shares[$index] = (int) floor($exact);
            $remainders[$index] = $exact - $shares[$index];
        }

        arsort($remainders);
        $leftover = $poolPaise - array_sum($shares);
        foreach (array_keys($remainders) as $index) {
            if ($leftover <= 0) {
                break;
            }
            $shares[$index]++;
            $leftover--;
        }

        ksort($shares);

        return array_values($shares);
    }

    /**
     * "300" basis points → "3%", "250" → "2.5%". Used only for ledger memos.
     */
    private function rateLabel(int $rateBp): string
    {
        return rtrim(rtrim(number_format($rateBp / 100, 2, '.', ''), '0'), '.').'%';
    }

    /**
     * A bank account is "on file" when the encrypted column holds anything at
     * all — including the Phase-1 'stub' placeholder, which represents details
     * captured before real encryption went live. NULL/empty means the optional
     * bank step was skipped and payouts must be held (STATUS_NO_BANK_ACCOUNT).
     */
    private function hasBankAccountOnFile(int $distributorId): bool
    {
        $raw = DB::table('distributors')->where('id', $distributorId)->value('bank_account_enc');

        return $raw !== null && $raw !== '';
    }

    /**
     * KYC is "verified" when the distributor's user account is active — the
     * same definition {@see RequireKycApproval}
     * uses ('active' carries the "Verified" pill). Until then the bank release
     * is held (STATUS_KYC_PENDING); income still accrues and stays visible.
     */
    private function isKycVerified(int $distributorId): bool
    {
        return DB::table('distributors')
            ->join('users', 'users.id', '=', 'distributors.user_id')
            ->where('distributors.id', $distributorId)
            ->value('users.status') === 'active';
    }

    /**
     * Public so the distributor's own profile page can show a real last-4
     * instead of a meaningless literal mask (F73) — the batch NEFT export is
     * not the only place this number is needed.
     */
    public function bankLast4ForDistributor(int $distributorId): ?string
    {
        $raw = DB::table('distributors')->where('id', $distributorId)->value('bank_account_enc');

        if ($raw === null || $raw === 'stub') {
            return null;
        }

        // The column holds PII ciphertext — the last 4 must come from the
        // DECRYPTED account number or the NEFT export shows garbage that
        // finance cannot reconcile against the bank. A ciphertext that no
        // longer decrypts is a hard stop for THIS distributor (LOG-2): a
        // silent null here used to flow a blank account number into the
        // NEFT file. Never log the ciphertext itself.
        try {
            $accountNumber = PiiCrypter::decryptString((string) $raw);
        } catch (Throwable $failure) {
            Log::critical('Bank account decryption failed — payout held for distributor', [
                'distributor_id' => $distributorId,
                'context' => 'bank_decryption_failure',
                'error' => $failure->getMessage(),
            ]);

            throw new BankDecryptionException($distributorId);
        }

        return mb_strlen($accountNumber) >= 4 ? mb_substr($accountNumber, -4) : null;
    }

    /**
     * The three fields a bank needs to execute one line of the NEFT file
     * (QA F44/F95): the account number, the IFSC, and the beneficiary name as
     * the bank itself holds it.
     *
     * The account number is PII ciphertext and a failure to decrypt it is the
     * same hard stop as everywhere else — a blank account number in a payment
     * instruction is a silently unpaid distributor, so the caller is made to
     * handle it. The IFSC is a public branch code, stored plain.
     *
     * The beneficiary name falls back to the distributor's registered full
     * name when they have not given one: that is what the file carried before
     * the column existed, so nothing regresses for the base that pre-dates it.
     * A name whose ciphertext will not open falls back the same way rather than
     * stopping the payment — a name mismatch is a bank rejection, a missing
     * account number is a wrong payment.
     *
     * @return array{account_number: string, ifsc: string, beneficiary_name: string}
     *
     * @throws BankDecryptionException
     */
    public function bankInstructionForDistributor(int $distributorId): array
    {
        $row = DB::table('distributors')
            ->join('users', 'users.id', '=', 'distributors.user_id')
            ->where('distributors.id', $distributorId)
            ->selectRaw('distributors.bank_account_enc, distributors.bank_beneficiary_name_enc, distributors.bank_ifsc, users.full_name')
            ->first();

        $raw = $row?->bank_account_enc;

        if ($row === null || $raw === null || $raw === 'stub') {
            throw new BankDecryptionException($distributorId);
        }

        try {
            $accountNumber = PiiCrypter::decryptString((string) $raw);
        } catch (Throwable $failure) {
            Log::critical('Bank account decryption failed — payout held for distributor', [
                'distributor_id' => $distributorId,
                'context' => 'bank_decryption_failure',
                'error' => $failure->getMessage(),
            ]);

            throw new BankDecryptionException($distributorId);
        }

        return [
            'account_number' => $accountNumber,
            'ifsc' => (string) ($row->bank_ifsc ?? ''),
            'beneficiary_name' => $this->beneficiaryName($distributorId, $row->bank_beneficiary_name_enc, (string) $row->full_name),
        ];
    }

    /** {@see bankInstructionForDistributor()} — the name half, which never throws. */
    private function beneficiaryName(int $distributorId, mixed $ciphertext, string $fullName): string
    {
        if ($ciphertext === null || $ciphertext === '' || $ciphertext === 'stub') {
            return $fullName;
        }

        try {
            $name = trim(PiiCrypter::decryptString((string) $ciphertext));
        } catch (Throwable $failure) {
            Log::warning('Beneficiary name decryption failed — falling back to the registered name', [
                'distributor_id' => $distributorId,
                'context' => 'bank_decryption_failure',
                'error' => $failure->getMessage(),
            ]);

            return $fullName;
        }

        return $name !== '' ? $name : $fullName;
    }
}
