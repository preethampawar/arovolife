<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Exceptions\BankDecryptionException;
use App\Modules\Compensation\Exceptions\BankValidationException;
use App\Modules\Compensation\Models\PayoutBankFileRow;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends exactly one line item to RazorpayX, shared by the per-line dispatch
 * job and the retry job so the two can never drift.
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
    public const FAILED_STATES = ['rejected', 'cancelled', 'reversed', 'failed'];

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
        // The batch job loads its lines up front and may reach this one long
        // after; re-read it so a line settled or changed meanwhile is never
        // sent. Only a waiting line (first dispatch) or a failed one (retry)
        // may go to the bank.
        $fresh = PayoutLineItem::find($line->id);
        if ($fresh === null) {
            return false;
        }
        $line->setRawAttributes($fresh->getAttributes(), true);

        if (! in_array($line->status, [PayoutLineItem::STATUS_PENDING, PayoutLineItem::STATUS_FAILED], true)) {
            return $line->status === PayoutLineItem::STATUS_TRANSFERRED;
        }

        // Crash-resume guard: a payout id means Razorpay already has this
        // transfer. Re-sending it is a second credit to the distributor.
        if ($line->razorpay_payout_id !== null && $line->razorpay_payout_id !== '') {
            return true;
        }

        // An attempt already handed to the bank in an NEFT file may have been
        // paid by the bank, which Razorpay cannot see. Never send it from here;
        // the bank's answer is recorded on the line first.
        if (self::inBankFile($line)) {
            Log::warning('RazorpayX payout not sent — line is in an NEFT bank file for this attempt', [
                'payout_line_item_id' => $line->id,
                'attempt' => (int) $line->retry_count,
            ]);

            AuditLog::create([
                'actor_id' => $actorId,
                'action' => 'payout.line_item.dispatch_skipped',
                'subject_type' => 'payout_line_item',
                'subject_id' => (int) $line->id,
                'details' => [
                    'payout_batch_id' => $line->payout_batch_id,
                    'distributor_id' => $line->distributor_id,
                    'cause' => 'in_bank_file',
                    'attempt' => (int) $line->retry_count,
                ],
                'ip' => app()->runningInConsole() ? null : request()->ip(),
            ]);

            return false;
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

        // Set while Razorpay is being asked whether this line's transfer
        // already exists, so a failure there is told apart from a failed send.
        $lookingUp = false;

        try {
            $contactId = $this->gateway->ensureContact($distributor);
            $fundAccountId = $this->gateway->ensureFundAccount($distributor, $contactId);

            // Ask Razorpay first. A previous attempt may have been accepted and
            // then lost its response (a killed job, a connection dropped past
            // the transport retries); that payout carries this line's reference
            // id. A live or settled one is adopted, never sent again. Only when
            // every payout under the reference is dead (rejected, cancelled,
            // reversed, failed) is a new one created.
            $lookingUp = true;
            $live = array_values(array_filter(
                $this->gateway->findPayoutsForLine($line),
                static fn (array $payout): bool => ! in_array($payout['status'], self::FAILED_STATES, true),
            ));
            $lookingUp = false;

            if (count($live) > 1) {
                Log::critical('RazorpayX holds more than one live payout for one line — nothing sent', [
                    'payout_line_item_id' => $line->id,
                    'razorpay_payout_ids' => array_column($live, 'id'),
                ]);
                $this->hold($line, PayoutLineItem::STATUS_FAILED,
                    'Razorpay holds more than one live transfer for this line ('.implode(', ', array_column($live, 'id')).'). Nothing was sent; check both with Razorpay before doing anything else.',
                    $actorId, 'gateway_multiple_live');

                return false;
            }

            $existing = $live[0] ?? null;
            if ($existing !== null && ! $this->matchesLine($existing, $line)) {
                Log::critical('RazorpayX payout under this reference does not match the line — nothing sent', [
                    'payout_line_item_id' => $line->id,
                    'razorpay_payout_id' => $existing['id'],
                    'gateway_amount_paise' => $existing['amount'],
                    'line_amount_paise' => $line->net_transferred_paise,
                ]);
                $this->hold($line, PayoutLineItem::STATUS_FAILED,
                    'Razorpay holds a transfer under this line\'s reference ('.$existing['id'].') that does not match its amount. Nothing was sent; check it with Razorpay.',
                    $actorId, 'gateway_lookup_mismatch');

                return false;
            }

            $adopted = $existing !== null;
            $payout = $existing ?? $this->gateway->createPayout($line, $distributor, $fundAccountId, $attempt);
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
            Log::critical($lookingUp ? 'RazorpayX payout lookup failed — nothing sent' : 'RazorpayX payout dispatch failed', [
                'payout_line_item_id' => $line->id,
                'distributor_id' => $line->distributor_id,
                'error' => $e->getMessage(),
            ]);

            // Not knowing whether the transfer exists means not sending it.
            if ($lookingUp) {
                $this->hold($line, PayoutLineItem::STATUS_FAILED,
                    'Razorpay could not confirm whether this transfer already exists; nothing was sent.',
                    $actorId, 'gateway_lookup_failed');
            } else {
                $this->hold($line, PayoutLineItem::STATUS_FAILED, $this->readableFailure($e), $actorId, 'gateway_error');
            }

            return false;
        }

        $state = strtolower($payout['status']);
        $terminalFailure = in_array($state, self::FAILED_STATES, true);

        $line->forceFill([
            'razorpay_payout_id' => $payout['id'],
            'razorpay_contact_id' => $contactId,
            // An adopted payout keeps what it was actually sent with.
            'razorpay_fund_account_id' => $existing['fund_account_id'] ?? $fundAccountId,
            'transfer_mode' => $existing['mode'] ?? strtolower($this->settings->modeFor((int) $line->net_transferred_paise)),
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
                'razorpay_fund_account_id' => $line->razorpay_fund_account_id,
                'gateway_status' => $state,
                'transfer_mode' => $line->transfer_mode,
                'attempt' => $attempt,
                'adopted_existing' => $adopted,
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
     * Never runs on a batch nobody has approved: the builder can leave an
     * unapproved batch `partially_failed` or `failed`, and recomputing it here
     * would relabel a payment no one signed as one awaiting the bank.
     *
     * A settled batch moves again when a line does: a transfer returned by the
     * bank turns `completed` into `partially_failed`/`failed`, and a failed
     * line sent again puts the batch back to awaiting the bank.
     */
    public function refreshBatchStatus(PayoutBatch $batch): void
    {
        if ($batch->approved_at === null) {
            return;
        }

        if (! in_array($batch->status, [
            PayoutBatch::STATUS_APPROVED,
            PayoutBatch::STATUS_DISPATCHED,
            PayoutBatch::STATUS_PARTIALLY_FAILED,
            PayoutBatch::STATUS_FAILED,
            PayoutBatch::STATUS_COMPLETED,
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
            // A line is back with the bank. A batch already waiting stays as
            // it is; a settled one returns to waiting.
            if (in_array($batch->status, [PayoutBatch::STATUS_APPROVED, PayoutBatch::STATUS_DISPATCHED], true)) {
                return;
            }

            $status = $this->settings->isRazorpay() ? PayoutBatch::STATUS_DISPATCHED : PayoutBatch::STATUS_APPROVED;
        } else {
            $status = match (true) {
                $failed > 0 && $transferred > 0 => PayoutBatch::STATUS_PARTIALLY_FAILED,
                $failed > 0 => PayoutBatch::STATUS_FAILED,
                default => PayoutBatch::STATUS_COMPLETED,
            };
        }

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
                'pending' => $pending,
            ],
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    /**
     * Whether this line's current attempt was handed to the bank in an NEFT
     * file — the same test the bank-file export uses to mark a line as
     * already sent.
     */
    public static function inBankFile(PayoutLineItem $line): bool
    {
        return PayoutBankFileRow::query()
            ->where('payout_line_item_id', $line->id)
            ->where('attempt', (int) $line->retry_count)
            ->where('result', PayoutBankFileRow::RESULT_SENT)
            ->exists();
    }

    /**
     * A payout found under this line's reference is adopted only when it is
     * provably this line's: same reference, same amount.
     *
     * @param  array{reference_id: string, amount: int|null}  $payout
     */
    private function matchesLine(array $payout, PayoutLineItem $line): bool
    {
        return $payout['reference_id'] === RazorpayPayoutGateway::referenceFor((int) $line->id)
            && $payout['amount'] === (int) $line->net_transferred_paise;
    }

    /**
     * A line job was killed (timeout, worker restart) before Razorpay
     * answered. The line is still `pending` with no payout id, which nothing
     * would ever pick up again; `failed` puts it in front of the auto-retry
     * sweep and "Send again", both safe because every send asks Razorpay for
     * the line's reference first.
     *
     * @return bool whether the line was held (false when it had moved on)
     */
    public function holdInterrupted(PayoutLineItem $line, ?int $actorId): bool
    {
        $fresh = PayoutLineItem::find($line->id);
        if ($fresh === null
            || $fresh->status !== PayoutLineItem::STATUS_PENDING
            || ($fresh->razorpay_payout_id !== null && $fresh->razorpay_payout_id !== '')) {
            return false;
        }

        $this->hold($fresh, PayoutLineItem::STATUS_FAILED,
            'The transfer job was interrupted before Razorpay answered. The next send checks Razorpay for this transfer first.',
            $actorId, 'job_interrupted');

        return true;
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
