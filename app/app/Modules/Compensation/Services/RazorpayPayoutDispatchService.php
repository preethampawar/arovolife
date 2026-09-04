<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Exceptions\BankDecryptionException;
use App\Modules\Compensation\Exceptions\BankValidationException;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends exactly one line item to RazorpayX, shared by the batch dispatch job
 * and the retry job so the two can never drift.
 *
 * Never throws: a failure is recorded on the line item and swallowed, because
 * one distributor's bad IFSC must not strand the other four hundred payouts
 * in the batch.
 *
 * The money left the wallet at batch computation time (`swept_by_payout_batch_id`
 * plus the `payout_debit` ledger entry). Nothing here touches the wallet — a
 * failed dispatch leaves a line item ops can retry, not a reversal.
 */
final class RazorpayPayoutDispatchService
{
    public const AUDIT_DISPATCHED = 'payout.line_item.dispatched';

    public const AUDIT_RETRY_DISPATCHED = 'payout.line_item.retry_dispatched';

    /** Razorpay payout states that are terminal failures. */
    private const FAILED_STATES = ['rejected', 'cancelled', 'reversed', 'failed'];

    public function __construct(
        private readonly RazorpayPayoutGateway $gateway,
        private readonly PayoutGatewaySettings $settings,
    ) {}

    /**
     * @param  string  $auditAction  self::AUDIT_DISPATCHED or self::AUDIT_RETRY_DISPATCHED
     * @return bool whether the transfer is now with the bank
     */
    public function dispatch(PayoutLineItem $line, ?int $actorId, string $auditAction): bool
    {
        // Crash-resume guard: a payout id means Razorpay already has this
        // transfer. Re-sending it is a second credit to the distributor.
        if ($line->razorpay_payout_id !== null && $line->razorpay_payout_id !== '') {
            return true;
        }

        $distributor = Distributor::with('user')->find($line->distributor_id);

        if ($distributor === null) {
            $this->hold($line, PayoutLineItem::STATUS_FAILED,
                'Distributor record not found — payout cannot be dispatched.', $actorId, 'distributor_missing');

            return false;
        }

        // The attempt number is what makes the idempotency key deterministic:
        // a crash-resumed retry of the SAME attempt reuses the key and gets
        // Razorpay's cached payout back instead of creating a second one.
        $attempt = (int) $line->retry_count;

        try {
            $contactId = $this->gateway->ensureContact($distributor);
            $fundAccountId = $this->gateway->ensureFundAccount($distributor, $contactId);
            $payout = $this->gateway->createPayout($line, $distributor, $fundAccountId, $attempt);
        } catch (BankDecryptionException) {
            // The critical log already fired inside the gateway. Held, not
            // failed: nothing is retryable until ops re-capture the details.
            $this->hold($line, PayoutLineItem::STATUS_BANK_DECRYPT_FAILED,
                'Bank account on file could not be decrypted — re-capture bank details.',
                $actorId, 'bank_decrypt_failed');

            return false;
        } catch (BankValidationException $e) {
            $this->hold($line, PayoutLineItem::STATUS_FAILED, $e->getMessage(), $actorId, 'bank_validation:'.$e->field);

            return false;
        } catch (Throwable $e) {
            Log::critical('RazorpayX payout dispatch failed', [
                'payout_line_item_id' => $line->id,
                'distributor_id' => $line->distributor_id,
                'error' => $e->getMessage(),
            ]);

            $this->hold($line, PayoutLineItem::STATUS_FAILED, $this->readableFailure($e), $actorId, 'gateway_error');

            return false;
        }

        $state = strtolower($payout['status']);
        $terminalFailure = in_array($state, self::FAILED_STATES, true);

        $line->forceFill([
            'razorpay_payout_id' => $payout['id'],
            'razorpay_contact_id' => $contactId,
            'razorpay_fund_account_id' => $fundAccountId,
            'transfer_mode' => strtolower($this->settings->modeFor((int) $line->net_transferred_paise)),
            'dispatched_at' => now(),
            // Status stays `pending` until the webhook confirms settlement —
            // "sent to the bank" is not "the distributor has the money".
            'status' => match (true) {
                $state === 'processed' => PayoutLineItem::STATUS_TRANSFERRED,
                $terminalFailure => PayoutLineItem::STATUS_FAILED,
                default => PayoutLineItem::STATUS_PENDING,
            },
            'utr_number' => $payout['utr'] ?? $line->utr_number,
            'failure_reason' => $terminalFailure ? 'Razorpay rejected the transfer ('.$state.').' : null,
        ])->save();

        AuditLog::create([
            'actor_id' => $actorId,
            'action' => $auditAction,
            'subject_type' => 'payout_line_item',
            'subject_id' => (int) $line->id,
            'details' => [
                'payout_batch_id' => $line->payout_batch_id,
                'distributor_id' => $line->distributor_id,
                'net_transferred_paise' => $line->net_transferred_paise,
                'razorpay_payout_id' => $payout['id'],
                'razorpay_contact_id' => $contactId,
                'razorpay_fund_account_id' => $fundAccountId,
                'gateway_status' => $state,
                'transfer_mode' => $line->transfer_mode,
                'attempt' => $attempt,
            ],
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);

        return ! $terminalFailure;
    }

    /**
     * Recompute a batch's status from its line items.
     *
     * Only the three transfer statuses count. Wallet-held lines (web_only,
     * kyc_pending, no_bank_account, bank_decrypt_failed) and below_minimum
     * lines never leave the company, so a batch made entirely of them is
     * settled the moment it is approved.
     *
     * Never runs on a batch that is still awaiting approval, and never
     * downgrades one that has already settled.
     */
    public function refreshBatchStatus(PayoutBatch $batch): void
    {
        if (! in_array($batch->status, [
            PayoutBatch::STATUS_APPROVED,
            PayoutBatch::STATUS_DISPATCHED,
            PayoutBatch::STATUS_PARTIALLY_FAILED,
            PayoutBatch::STATUS_FAILED,
        ], true)) {
            return;
        }

        $counts = PayoutLineItem::where('payout_batch_id', $batch->id)
            ->selectRaw("
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS still_pending,
                SUM(CASE WHEN status = 'transferred' THEN 1 ELSE 0 END) AS transferred,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
            ")
            ->first();

        $pending = (int) ($counts->still_pending ?? 0);
        $transferred = (int) ($counts->transferred ?? 0);
        $failed = (int) ($counts->failed ?? 0);

        if ($pending > 0) {
            return;
        }

        $status = match (true) {
            $failed > 0 && $transferred > 0 => PayoutBatch::STATUS_PARTIALLY_FAILED,
            $failed > 0 => PayoutBatch::STATUS_FAILED,
            default => PayoutBatch::STATUS_COMPLETED,
        };

        if ($batch->status === $status) {
            return;
        }

        $before = $batch->status;
        $batch->update(['status' => $status]);

        AuditLog::create([
            'actor_id' => app()->runningInConsole() ? null : auth()->id(),
            'action' => 'payout.batch.settled',
            'subject_type' => 'payout_batch',
            'subject_id' => (int) $batch->id,
            'details' => [
                'before' => $before,
                'after' => $status,
                'transferred' => $transferred,
                'failed' => $failed,
            ],
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    /**
     * Record why this line item did not go out. `failed` is retryable once
     * ops fix the cause; `bank_decrypt_failed` is a hold, not a retry
     * candidate — the auto-retry sweep deliberately ignores it.
     */
    private function hold(PayoutLineItem $line, string $status, string $reason, ?int $actorId, string $cause): void
    {
        $reason = mb_substr($reason, 0, 500);

        $line->forceFill([
            'status' => $status,
            'failure_reason' => $reason,
        ])->save();

        AuditLog::create([
            'actor_id' => $actorId,
            'action' => 'payout.line_item.dispatch_failed',
            'subject_type' => 'payout_line_item',
            'subject_id' => (int) $line->id,
            'details' => [
                'payout_batch_id' => $line->payout_batch_id,
                'distributor_id' => $line->distributor_id,
                'status' => $status,
                'cause' => $cause,
                'failure_reason' => $reason,
                'retry_count' => $line->retry_count,
            ],
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    /**
     * A message an admin can act on. Gateway exception messages carry only
     * the gateway's own description — never a request body — so they are safe
     * to surface, but they are trimmed to fit the column.
     */
    private function readableFailure(Throwable $e): string
    {
        $message = trim($e->getMessage());

        return $message !== '' ? $message : 'Payout dispatch failed — see the payments log.';
    }
}
