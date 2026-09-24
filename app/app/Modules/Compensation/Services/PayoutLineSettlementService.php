<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Exceptions\PayoutLineActionRefused;
use App\Modules\Compensation\Jobs\RetryRazorpayPayoutJob;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The manual controls that finish a payout line: mark paid, mark failed, mark
 * returned, send again, check with Razorpay.
 *
 * The wallet is not touched here, ever. The money left the wallet when the
 * batch was built (the `payout_debit` / `admin_charge_debit` / `tds_debit`
 * entries). These actions record what the bank did with it and, when it
 * bounced, put the SAME line back in front of the bank — never a reversal and
 * never a second debit.
 *
 * Every action requires an approved batch (`approved_at` set): a batch the
 * builder left `partially_failed` or `failed` before anyone signed it off is
 * not a payment instruction, and nothing here may make it look like one.
 *
 * In Razorpay mode a `pending` line is never marked by hand. It is either with
 * Razorpay (ask Razorpay — {@see checkWithRazorpay()}) or still queued for the
 * dispatch job, which would send the real transfer after a person had already
 * recorded it as paid or failed.
 *
 * Each method re-reads the line under a row lock, re-checks the state it was
 * asked to move from, writes one audit row, then recomputes the batch status.
 * A refusal throws {@see PayoutLineActionRefused} before anything is written.
 */
final class PayoutLineSettlementService
{
    public function __construct(
        private readonly RazorpayPayoutDispatchService $dispatcher,
        private readonly RazorpayPayoutGateway $gateway,
        private readonly PayoutGatewaySettings $settings,
    ) {}

    /**
     * Record a transfer the bank confirmed outside the response file.
     *
     * Not named markPaid(): that name is pinned to the order choke point
     * (MarkPaidChokePointTest), which must stay a plain source scan.
     */
    public function markLinePaid(PayoutLineItem $line, string $utr, int $actorId): PayoutLineItem
    {
        $utr = strtoupper(trim($utr));

        if ($utr === '' || strlen($utr) > 64 || preg_match('/^[A-Z0-9]+$/', $utr) !== 1) {
            throw new PayoutLineActionRefused('Enter the bank reference (UTR) exactly as the bank gave it — letters and digits only, up to 64 characters.');
        }

        return $this->transition($line, $actorId, 'payout.line_item.marked_paid', function (PayoutLineItem $locked) use ($utr): array {
            $this->refuseInRazorpayMode('Mark paid');
            $this->requireStatus($locked, [PayoutLineItem::STATUS_PENDING, PayoutLineItem::STATUS_FAILED], 'Only a line still waiting for the bank, or one recorded as failed, can be marked paid.');

            if (self::utrIsRecorded($utr)) {
                throw new PayoutLineActionRefused("The bank reference {$utr} already settles another payout line. One transfer settles one line — check the reference.");
            }

            $previousReason = $locked->failure_reason;

            $locked->forceFill([
                'status' => PayoutLineItem::STATUS_TRANSFERRED,
                'utr_number' => $utr,
                'failure_reason' => null,
            ])->save();

            return ['utr_number' => $utr, 'previous_failure_reason' => $previousReason];
        });
    }

    /** Record a transfer the bank reported as failed outside the response file. */
    public function markFailed(PayoutLineItem $line, string $reason, int $actorId): PayoutLineItem
    {
        $reason = $this->reason($reason);

        return $this->transition($line, $actorId, 'payout.line_item.marked_failed', function (PayoutLineItem $locked) use ($reason): array {
            $this->refuseInRazorpayMode('Mark failed');
            $this->requireStatus($locked, [PayoutLineItem::STATUS_PENDING], 'Only a line still waiting for the bank can be marked failed.');

            $locked->forceFill([
                'status' => PayoutLineItem::STATUS_FAILED,
                'failure_reason' => $reason,
            ])->save();

            return ['failure_reason' => $reason];
        });
    }

    /**
     * The bank sent back a transfer already recorded as paid. The line becomes
     * failed so it can be sent again; its old UTR is kept in the audit row and
     * cleared from the line, so the re-sent transfer can carry its own.
     */
    public function markReturned(PayoutLineItem $line, string $reason, int $actorId): PayoutLineItem
    {
        $reason = $this->reason($reason);

        return $this->transition($line, $actorId, 'payout.line_item.marked_returned', function (PayoutLineItem $locked) use ($reason): array {
            // Razorpay reports a returned transfer itself (payout.reversed).
            $this->refuseInRazorpayMode('Mark returned');
            $this->requireStatus($locked, [PayoutLineItem::STATUS_TRANSFERRED], 'Only a line recorded as paid can be marked returned.');

            $previousUtr = $locked->utr_number;

            $locked->forceFill([
                'status' => PayoutLineItem::STATUS_FAILED,
                'utr_number' => null,
                'failure_reason' => $reason,
            ])->save();

            return ['failure_reason' => $reason, 'previous_utr' => $previousUtr];
        });
    }

    /**
     * Put a failed line back in front of the bank.
     *
     * Manual NEFT: the line returns to `pending` and is in the next bank file.
     * Razorpay: the line is re-dispatched. When it still holds a payout id,
     * Razorpay is asked first — the id is only dropped once Razorpay confirms
     * that payout is dead, or a second transfer could follow a live one.
     *
     * @param  bool  $respectRetryLimit  false for a person's per-line decision;
     *                                   true for the bulk button (the nightly
     *                                   sweep has its own limit)
     */
    public function sendAgain(PayoutLineItem $line, int $actorId, bool $respectRetryLimit = false): PayoutLineItem
    {
        $isRazorpay = $this->settings->isRazorpay();

        // Asked outside the transaction: a network call must not sit inside a
        // row lock. The state is re-checked under the lock below.
        $gatewayState = null;
        if ($isRazorpay && $this->hasPayoutId($line)) {
            $gatewayState = $this->gatewayState((string) $line->razorpay_payout_id);

            if (! in_array($gatewayState, RazorpayPayoutDispatchService::FAILED_STATES, true)) {
                throw new PayoutLineActionRefused("Razorpay still reports this transfer as \"{$gatewayState}\". It can only be sent again once Razorpay reports it failed or reversed.");
            }
        }

        $sent = $this->transition($line, $actorId, 'payout.line_item.sent_again', function (PayoutLineItem $locked) use ($isRazorpay, $respectRetryLimit, $gatewayState, $line): array {
            $this->requireStatus($locked, [PayoutLineItem::STATUS_FAILED], 'Only a failed line can be sent again.');

            if ((int) $locked->net_transferred_paise <= 0) {
                throw new PayoutLineActionRefused('This line has nothing to transfer.');
            }

            $details = [
                'mode' => $isRazorpay ? 'razorpay' : 'manual_neft',
                'previous_failure_reason' => $locked->failure_reason,
                'attempt' => (int) $locked->retry_count + 1,
            ];

            if (! $isRazorpay) {
                // `dispatched_at` is left alone: in manual mode it is not ours
                // (the TDS register reads it), and "was this line in an earlier
                // bank file" is answered from the stored files instead.
                $locked->forceFill([
                    'status' => PayoutLineItem::STATUS_PENDING,
                    'retry_count' => (int) $locked->retry_count + 1,
                    'last_retried_at' => now(),
                    'failure_reason' => null,
                ])->save();

                return $details;
            }

            if ($respectRetryLimit && (int) $locked->retry_count >= $this->settings->maxRetries()) {
                throw new PayoutLineActionRefused('This line has reached the retry limit ('.$this->settings->maxRetries().').');
            }

            // The payout id changed since Razorpay was asked — someone else
            // acted on this line. Start again rather than drop an id we have
            // not checked.
            if ((string) $locked->razorpay_payout_id !== (string) $line->razorpay_payout_id) {
                throw new PayoutLineActionRefused('This line changed while it was being checked. Reload the page and try again.');
            }

            $details['previous_razorpay_payout_id'] = $locked->razorpay_payout_id;
            $details['gateway_status'] = $gatewayState;

            // The retry job bumps retry_count before calling Razorpay, so the
            // next attempt carries a new idempotency key: a new payout, which
            // is exactly what a confirmed-dead one needs.
            $locked->forceFill([
                'razorpay_payout_id' => null,
                'dispatched_at' => null,
            ])->save();

            return $details;
        });

        if ($isRazorpay) {
            RetryRazorpayPayoutJob::dispatch((int) $sent->id, $actorId, ! $respectRetryLimit);
        }

        return $sent;
    }

    /**
     * Ask Razorpay where a transfer stands — the remedy for a webhook that
     * never arrived. Always writes an audit row, even when nothing changed.
     *
     * @return string what happened: transferred, failed, or the in-flight state
     */
    public function checkWithRazorpay(PayoutLineItem $line, int $actorId): string
    {
        if (! $this->settings->isRazorpay()) {
            throw new PayoutLineActionRefused('Check with Razorpay is only available while the payout gateway is Razorpay.');
        }

        if ($line->status !== PayoutLineItem::STATUS_PENDING || ! $this->hasPayoutId($line)) {
            throw new PayoutLineActionRefused('Only a line that is with Razorpay and still waiting for confirmation can be checked.');
        }

        try {
            $payout = $this->gateway->fetchPayout((string) $line->razorpay_payout_id);
        } catch (Throwable $e) {
            throw new PayoutLineActionRefused('Razorpay could not be reached: '.mb_substr(trim($e->getMessage()), 0, 200));
        }

        $state = strtolower((string) ($payout['status'] ?? ''));
        $utr = isset($payout['utr']) && $payout['utr'] !== '' ? strtoupper(trim((string) $payout['utr'])) : null;
        $outcome = $state;

        $this->transition($line, $actorId, 'payout.line_item.gateway_checked', function (PayoutLineItem $locked) use ($state, $utr, $line, &$outcome): array {
            $details = ['gateway_status' => $state, 'razorpay_payout_id' => $locked->razorpay_payout_id];

            // The webhook may have settled it while we were asking.
            if ($locked->status !== PayoutLineItem::STATUS_PENDING
                || (string) $locked->razorpay_payout_id !== (string) $line->razorpay_payout_id) {
                $outcome = 'unchanged';

                return $details + ['result' => 'already_settled'];
            }

            if ($state === 'processed') {
                $locked->forceFill([
                    'status' => PayoutLineItem::STATUS_TRANSFERRED,
                    'utr_number' => $utr ?? $locked->utr_number,
                    'failure_reason' => null,
                ])->save();
                $outcome = PayoutLineItem::STATUS_TRANSFERRED;

                return $details + ['result' => 'transferred', 'utr_number' => $utr];
            }

            if (in_array($state, RazorpayPayoutDispatchService::FAILED_STATES, true)) {
                $locked->forceFill([
                    'status' => PayoutLineItem::STATUS_FAILED,
                    'failure_reason' => "Razorpay reports the transfer as {$state}.",
                ])->save();
                $outcome = PayoutLineItem::STATUS_FAILED;

                return $details + ['result' => 'failed'];
            }

            return $details + ['result' => 'in_progress'];
        });

        return $outcome;
    }

    /**
     * Whether a bank reference already settles any payout line. A plain
     * equality so `uniq_payout_line_items_utr` serves it; the column's
     * collation is case-insensitive and references are stored trimmed.
     */
    public static function utrIsRecorded(string $utr): bool
    {
        return PayoutLineItem::query()->where('utr_number', trim($utr))->exists();
    }

    /**
     * Lock the line, run the change, audit it, then recompute the batch.
     *
     * @param  callable(PayoutLineItem): array<string, mixed>  $change  returns the audit details
     */
    private function transition(PayoutLineItem $line, int $actorId, string $action, callable $change): PayoutLineItem
    {
        try {
            $locked = $this->lockAndChange($line, $actorId, $action, $change);
        } catch (UniqueConstraintViolationException) {
            // Two people recording the same UTR on two lines at once: the
            // check passed for both, the unique index stopped the second.
            throw new PayoutLineActionRefused('That bank reference (UTR) was just recorded on another payout line. One transfer settles one line — check the reference.');
        }

        $batch = PayoutBatch::find($locked->payout_batch_id);
        if ($batch !== null) {
            $this->dispatcher->refreshBatchStatus($batch);
        }

        return $locked;
    }

    /**
     * {@see transition()} — the locked read, the change and its audit row, in
     * one transaction.
     *
     * @param  callable(PayoutLineItem): array<string, mixed>  $change
     */
    private function lockAndChange(PayoutLineItem $line, int $actorId, string $action, callable $change): PayoutLineItem
    {
        return DB::transaction(function () use ($line, $actorId, $action, $change): PayoutLineItem {
            /** @var PayoutLineItem $locked */
            $locked = PayoutLineItem::query()->lockForUpdate()->findOrFail($line->id);

            $batch = PayoutBatch::findOrFail($locked->payout_batch_id);
            if ($batch->approved_at === null) {
                throw new PayoutLineActionRefused('This batch has not been approved, so none of its lines can be settled or sent. Approve the batch first.');
            }

            $before = AuditDigests::of($locked);
            $fromStatus = $locked->status;

            $details = $change($locked);

            AuditLog::create([
                'actor_id' => $actorId,
                'action' => $action,
                'subject_type' => 'payout_line_item',
                'subject_id' => (int) $locked->id,
                'before_hash' => $before,
                'after_hash' => AuditDigests::of($locked),
                'details' => [
                    'payout_batch_id' => (int) $locked->payout_batch_id,
                    'distributor_id' => (int) $locked->distributor_id,
                    'net_transferred_paise' => (int) $locked->net_transferred_paise,
                    'from_status' => $fromStatus,
                    'to_status' => $locked->status,
                ] + $details,
                'ip' => app()->runningInConsole() ? null : request()->ip(),
            ]);

            return $locked;
        });
    }

    /** @param  list<string>  $allowed */
    private function requireStatus(PayoutLineItem $line, array $allowed, string $message): void
    {
        if (! in_array($line->status, $allowed, true)) {
            throw new PayoutLineActionRefused($message.' This line is '.str_replace('_', ' ', $line->status).'.');
        }
    }

    private function refuseInRazorpayMode(string $action): void
    {
        if ($this->settings->isRazorpay()) {
            throw new PayoutLineActionRefused("{$action} is for Manual NEFT. In Razorpay mode Razorpay reports each transfer itself — use Check with Razorpay on a line that is waiting.");
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new PayoutLineActionRefused('Give the reason the bank gave, so the next person can see why.');
        }

        return mb_substr($reason, 0, 500);
    }

    private function hasPayoutId(PayoutLineItem $line): bool
    {
        return $line->razorpay_payout_id !== null && $line->razorpay_payout_id !== '';
    }

    private function gatewayState(string $payoutId): string
    {
        try {
            $payout = $this->gateway->fetchPayout($payoutId);
        } catch (Throwable $e) {
            throw new PayoutLineActionRefused('Razorpay could not be reached, so the earlier transfer could not be confirmed as failed: '.mb_substr(trim($e->getMessage()), 0, 200));
        }

        return strtolower((string) ($payout['status'] ?? ''));
    }
}
