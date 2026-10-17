<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutBuildFailure;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Shared\Logging\PiiScrubberProcessor;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Writes and resolves {@see PayoutBuildFailure} rows — the record of a
 * distributor the payout sweep skipped so the rest of the batch could be paid
 * (user request 2026-10-17).
 *
 * Only the sweep, a retry and the approval call it, all under the payout sweep
 * lock, so two writers never race on one row.
 */
final class PayoutBuildFailureRecorder
{
    public function __construct(private readonly EngineRunContext $engineRunContext) {}

    /** `error_detail` is a MEDIUMTEXT; a runaway trace is cut well inside it. */
    private const DETAIL_MAX_BYTES = 262_144;

    /** `message` is a TEXT (64 KB). */
    private const MESSAGE_MAX_BYTES = 60_000;

    /**
     * A bank account number is 9–18 digits. The log scrubber matches PAN and
     * Aadhaar by shape and account numbers only by key, which a free-text
     * exception has none of — so stored error text drops every long digit run.
     * Over-redacts an amount in paise above ₹1 crore; an error log losing a
     * number is an inconvenience, an account number on an admin page is not.
     */
    private const ACCOUNT_NUMBER_RE = '/(?<!\d)\d{9,18}(?!\d)/';

    /** IFSC: four letters, a zero, six letters or digits. */
    private const IFSC_RE = '/\b[A-Za-z]{4}0[A-Za-z0-9]{6}\b/';

    /** How deep the previous-exception chain is walked, as the log scrubber does. */
    private const MAX_CHAIN_DEPTH = 10;

    /**
     * Record (or re-record) a distributor whose line threw. The first failure
     * creates the row; a later one on the same batch replaces the error, bumps
     * `attempts` and re-opens the row.
     */
    public function record(PayoutBatch $batch, int $distributorId, Throwable $e): PayoutBuildFailure
    {
        $now = now();
        $message = self::cut(self::scrub($e->getMessage()), self::MESSAGE_MAX_BYTES);
        $detail = self::cut(self::scrub(self::render($e)), self::DETAIL_MAX_BYTES);

        $failure = PayoutBuildFailure::query()
            ->where('payout_batch_id', $batch->id)
            ->where('distributor_id', $distributorId)
            ->first();

        if ($failure === null) {
            $failure = PayoutBuildFailure::create([
                'payout_batch_id' => $batch->id,
                'distributor_id' => $distributorId,
                'status' => PayoutBuildFailure::STATUS_OPEN,
                'attempts' => 1,
                'exception_class' => $e::class,
                'message' => $message,
                'error_detail' => $detail,
                'first_failed_at' => $now,
                'last_failed_at' => $now,
            ]);

            $this->audit('payout.build_failure.recorded', $failure, null, [
                'exception_class' => $e::class,
            ]);

            return $failure;
        }

        $failure->update([
            'status' => PayoutBuildFailure::STATUS_OPEN,
            'resolution' => null,
            'resolved_at' => null,
            'resolved_line_item_id' => null,
            'resolved_by_batch_id' => null,
            'attempts' => $failure->attempts + 1,
            'exception_class' => $e::class,
            'message' => $message,
            'error_detail' => $detail,
            'last_failed_at' => $now,
        ]);

        return $failure;
    }

    /**
     * A distributor's line now exists in `$batch` — the sweep, a re-sweep or a
     * retry built it: close their failure on that batch as built, whatever the
     * line's status (a held line is still a line the batch page explains).
     */
    public function resolveBuilt(PayoutBatch $batch, int $distributorId): void
    {
        $failure = PayoutBuildFailure::query()
            ->where('payout_batch_id', $batch->id)
            ->where('distributor_id', $distributorId)
            ->whereIn('status', PayoutBuildFailure::UNRESOLVED_STATUSES)
            ->first();

        if ($failure === null) {
            return;
        }

        $lineId = PayoutLineItem::query()
            ->where('payout_batch_id', $batch->id)
            ->where('distributor_id', $distributorId)
            ->value('id');

        $this->resolve($failure, PayoutBuildFailure::RESOLUTION_BUILT, $batch, $lineId === null ? null : (int) $lineId);
    }

    /**
     * Close every open failure on an EARLIER batch of `$batch`'s type whose
     * distributor this batch has now paid. Only a line that swept the credits
     * counts — `pending` (paying) or `income_cap_forfeited` (swept and closed).
     * A held or below-minimum line leaves the credits in the wallet, so the
     * failure stays open until a batch actually takes them. Both sweep windows
     * are cumulative ("earned on or before"), which is why the later batch
     * picks up what the earlier one skipped.
     *
     * @return int how many failures were resolved
     */
    public function resolveCarriedBy(PayoutBatch $batch): int
    {
        $sweepingLines = PayoutLineItem::query()
            ->where('payout_batch_id', $batch->id)
            ->whereIn('status', [PayoutLineItem::STATUS_PENDING, PayoutLineItem::STATUS_INCOME_CAP_FORFEITED])
            ->pluck('id', 'distributor_id');

        if ($sweepingLines->isEmpty()) {
            return 0;
        }

        $resolved = 0;

        PayoutBuildFailure::query()
            ->whereIn('status', PayoutBuildFailure::UNRESOLVED_STATUSES)
            ->where('payout_batch_id', '!=', $batch->id)
            ->whereHas('payoutBatch', fn ($query) => $query
                ->where('batch_type', $batch->batch_type)
                ->whereDate('batch_date', '<', $batch->batch_date->toDateString()))
            ->orderBy('id')
            ->chunkById(500, function ($failures) use ($sweepingLines, $batch, &$resolved): void {
                foreach ($failures as $failure) {
                    /** @var PayoutBuildFailure $failure */
                    $lineId = $sweepingLines->get($failure->distributor_id);

                    if ($lineId === null) {
                        continue;
                    }

                    $this->resolve($failure, PayoutBuildFailure::RESOLUTION_PAID_BY_LATER_BATCH, $batch, (int) $lineId);
                    $resolved++;
                }
            });

        return $resolved;
    }

    /**
     * `$batch` is being un-built: its sweep marks come off the credits, so a
     * failure on an earlier batch that this one had carried is owed again.
     *
     * @return int how many failures were re-opened
     */
    public function reopenCarriedBy(PayoutBatch $batch): int
    {
        return PayoutBuildFailure::query()
            ->where('resolved_by_batch_id', $batch->id)
            ->where('payout_batch_id', '!=', $batch->id)
            ->update([
                'status' => PayoutBuildFailure::STATUS_OPEN,
                'resolution' => null,
                'resolved_line_item_id' => null,
                'resolved_by_batch_id' => null,
                'resolved_at' => null,
                'updated_at' => now(),
            ]);
    }

    /** A `retrying` row this old has lost its job (a killed worker never runs `failed()`). */
    public const STALE_RETRY_MINUTES = 15;

    /**
     * Claim a failure for a retry. Conditional, so a double click or two
     * admins on the same row queue exactly one job: only an open row — or a
     * `retrying` row whose job has gone quiet — can be claimed.
     */
    public function claimForRetry(PayoutBuildFailure $failure, ?int $actorId): bool
    {
        $before = AuditDigests::of($failure);

        $claimed = PayoutBuildFailure::query()
            ->whereKey($failure->id)
            ->where(fn ($query) => $query
                ->where('status', PayoutBuildFailure::STATUS_OPEN)
                ->orWhere(fn ($stale) => $stale
                    ->where('status', PayoutBuildFailure::STATUS_RETRYING)
                    ->where('retry_requested_at', '<', now()->subMinutes(self::STALE_RETRY_MINUTES))))
            ->update([
                'status' => PayoutBuildFailure::STATUS_RETRYING,
                'retry_requested_by' => $actorId,
                'retry_requested_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $failure->refresh();
        $this->audit('payout.build_failure.retry_requested', $failure, $before, [], $actorId);

        return true;
    }

    /**
     * A retry that did not build: back to open. `$note` (scrubbed) replaces the
     * message when the reason is the retry's own — a lock timeout, an approved
     * batch — rather than the distributor's error, which stays in the detail.
     */
    public function reopen(PayoutBuildFailure $failure, ?string $note = null): void
    {
        $changes = ['status' => PayoutBuildFailure::STATUS_OPEN];

        if ($note !== null) {
            $changes['message'] = self::cut(self::scrub($note), self::MESSAGE_MAX_BYTES);
        }

        if (! $failure->isResolved()) {
            $failure->update($changes);
        }
    }

    private function resolve(PayoutBuildFailure $failure, string $resolution, PayoutBatch $byBatch, ?int $lineId): void
    {
        $before = AuditDigests::of($failure);

        $failure->update([
            'status' => PayoutBuildFailure::STATUS_RESOLVED,
            'resolution' => $resolution,
            'resolved_line_item_id' => $lineId,
            'resolved_by_batch_id' => (int) $byBatch->id,
            'resolved_at' => now(),
        ]);

        $this->audit('payout.build_failure.resolved', $failure, $before, [
            'resolution' => $resolution,
            'resolved_by_batch_id' => (int) $byBatch->id,
            'attempts' => $failure->attempts,
        ]);
    }

    /**
     * The whole exception and its chain — class, message, file:line and every
     * stack frame — but never the frames' arguments. `getTraceAsString()` and
     * `(string) $e` print string arguments, which is exactly where a PAN or an
     * account number passed into a function would show up.
     */
    public static function render(Throwable $e): string
    {
        $out = [];
        $current = $e;

        for ($depth = 0; $current !== null && $depth <= self::MAX_CHAIN_DEPTH; $depth++) {
            $out[] = ($depth === 0 ? '' : "\nCaused by: ").$current::class.': '.$current->getMessage();
            $out[] = '  at '.$current->getFile().':'.$current->getLine();

            foreach ($current->getTrace() as $i => $frame) {
                $where = isset($frame['file']) ? $frame['file'].'('.($frame['line'] ?? '?').')' : '[internal]';
                $call = ($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'].'()';
                $out[] = '  #'.$i.' '.$where.': '.$call;
            }

            $current = $current->getPrevious();
        }

        return implode("\n", $out);
    }

    /**
     * Everything an exception names, with PAN, Aadhaar and account numbers
     * removed. Public so the retry job's `failed()` hook stores the same.
     */
    public static function scrub(string $text): string
    {
        $text = PiiScrubberProcessor::scrubText($text);
        $text = (string) preg_replace(self::IFSC_RE, '[REDACTED:IFSC]', $text);

        return (string) preg_replace(self::ACCOUNT_NUMBER_RE, '[REDACTED:NUMBER]', $text);
    }

    private static function cut(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return mb_strcut($text, 0, $maxBytes - 40, 'UTF-8')."\n… [truncated at ".intdiv($maxBytes, 1024).' KB]';
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function audit(string $action, PayoutBuildFailure $failure, ?string $before, array $details, ?int $actorId = null): void
    {
        $authenticated = Auth::id();

        AuditLog::create([
            // A retry runs on the queue worker, which has no session: the job
            // binds the admin who clicked onto the run context.
            'actor_id' => $actorId ?? (is_numeric($authenticated) ? (int) $authenticated : $this->engineRunContext->actorId()),
            'action' => $action,
            'subject_type' => 'payout_build_failure',
            'subject_id' => (int) $failure->id,
            'before_hash' => $before,
            'after_hash' => AuditDigests::of($failure),
            'details' => array_merge([
                'payout_batch_id' => $failure->payout_batch_id,
                'distributor_id' => $failure->distributor_id,
            ], $details),
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
