<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Rebuild;

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutGatewayEvent;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Support\FrozenPayoutGuard;
use App\Modules\Compensation\Support\MonthlyEngineCompletionGate;
use Closure;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Rebuild one payout batch — a Tuesday's weekly batch, or a crediting month's
 * monthly batch — by taking it apart and building it again.
 *
 * One class for both kinds because they are the same operation on the same row:
 * find the batch, refuse if anyone has signed it off, un-build it through
 * {@see PayoutService::unbuildBatch()} and re-run the command that made it. Only
 * three things differ — which batch the period names, which command re-makes it,
 * and the monthly one's extra warning about the crediting gate — so a second
 * copy of the refusal list would be a second place for one of them to stop
 * refusing an approved batch.
 *
 * Nothing here deletes a credit. The batch's stamp on the credits it swept is
 * removed and the credits themselves stay exactly where they were (D10); only
 * the batch's OWN debits, its line items and its row go, because the re-run
 * derives all three again (DN-1). That is what "re-freeze every distributor's
 * amount" means here: the new line items ARE the re-frozen amounts.
 */
final class BatchRebuilder
{
    public function __construct(private readonly PayoutService $payouts) {}

    public function plan(RebuildKind $kind, Carbon $period): RebuildPlan
    {
        $period = $this->normalise($kind, $period);
        $batch = $this->batchFor($kind, $period);

        $rows = [];
        $unsweeps = 0;

        if ($batch !== null) {
            $summary = $this->payouts->unbuildPreview($batch);
            $unsweeps = $summary['entries_unswept'];
            $rows = [
                'wallet_ledger_entries' => $summary['debits_deleted'] + $summary['forfeits_deleted'],
                'payout_line_items' => $summary['line_items'],
                'payout_batches' => 1,
            ];
        }

        return new RebuildPlan(
            $kind,
            $period,
            $this->refusals($kind, $period, $batch),
            $batch === null ? [] : $this->warnings($kind, $period, $batch),
            array_filter($rows, static fn (int $count): bool => $count > 0),
            $unsweeps,
        );
    }

    /**
     * @param  Closure(string): void  $log
     * @param  Closure(): void  $verify  Re-asks the refusals before the first delete; throws if anything moved.
     */
    public function wipe(RebuildKind $kind, Carbon $period, int $actorId, Closure $log, Closure $verify): RebuildResult
    {
        $period = $this->normalise($kind, $period);

        // Before the batch is taken apart: an approval landed between the
        // preview and the confirm is exactly what must not be un-built, and the
        // sweep lock inside unbuildBatch() catches only the last instant of it.
        $verify();

        $batch = $this->batchFor($kind, $period);

        if ($batch === null) {
            return new RebuildResult;
        }

        $summary = $this->payouts->unbuildBatch($batch, $actorId, sprintf(
            'Rebuilding the %s payout batch for %s',
            $kind === RebuildKind::Week ? 'weekly' : 'monthly',
            $kind === RebuildKind::Week ? $period->toDateString() : $period->format('F Y'),
        ));

        $log(sprintf(
            '  %-28s batch #%d removed; %d credit(s) un-swept, %d debit(s) deleted',
            'payout_batches',
            $batch->id,
            $summary['entries_unswept'],
            $summary['debits_deleted'] + $summary['forfeits_deleted'],
        ));

        return new RebuildResult(array_filter([
            'wallet_ledger_entries' => $summary['debits_deleted'] + $summary['forfeits_deleted'],
            'payout_line_items' => $summary['line_items'],
            'payout_batches' => 1,
        ], static fn (int $count): bool => $count > 0));
    }

    /** @return list<string> */
    private function refusals(RebuildKind $kind, Carbon $period, ?PayoutBatch $batch): array
    {
        if ($batch === null) {
            return [$kind === RebuildKind::Week
                ? sprintf(
                    'No weekly batch is dated %s; nothing to remove — the weekly run builds a missing Tuesday on its '
                    .'own (or `compensation:weekly-run --date=%s`).',
                    $period->toDateString(),
                    $period->toDateString(),
                )
                : sprintf(
                    'No monthly batch is dated %s for %s; nothing to remove — the monthly run builds it from the 8th '
                    .'once every crediting engine is green (or `compensation:monthly-run --date=<tonight>`).',
                    $period->copy()->addMonthNoOverflow()->toDateString(),
                    $period->format('F Y'),
                ),
            ];
        }

        if ($batch->status === PayoutBatch::STATUS_PROCESSING) {
            return [sprintf(
                'Batch #%d is stuck in processing — run `payout:reopen-stuck-batch --type=%s --date=%s '
                .'--actor=<your user id>` first, then rebuild.',
                $batch->id,
                $batch->batch_type,
                $batch->batch_date?->toDateString() ?? '',
            )];
        }

        $refusals = [];

        if (FrozenPayoutGuard::isFrozen($batch)) {
            $refusals[] = sprintf(
                'Batch #%d is %s%s; a batch finance has signed off is not rebuilt — retry its failed lines from the '
                .'Payouts page.',
                $batch->id,
                $batch->status,
                $batch->approved_at !== null ? ' (approved '.$batch->approved_at->format('d M Y H:i').')' : '',
            );
        }

        if (PayoutGatewayEvent::query()->where('payout_batch_id', $batch->id)->exists()
            || PayoutGatewayEvent::query()->whereIn('payout_line_item_id', $batch->lineItems()->select('id'))->exists()) {
            $refusals[] = sprintf('Batch #%d has gateway events; it reached Razorpay.', $batch->id);
        }

        return $refusals;
    }

    /** @return list<string> */
    private function warnings(RebuildKind $kind, Carbon $period, PayoutBatch $batch): array
    {
        $warnings = [];
        $later = $this->payouts->laterBatchesAfter($batch);

        $rebuildable = $later->reject(static fn (PayoutBatch $row): bool => FrozenPayoutGuard::isFrozen($row));

        if ($rebuildable->isNotEmpty()) {
            $warnings[] = sprintf(
                'The income-cap headroom of later batches changes. Rebuild these too, oldest first: %s.',
                $rebuildable->map(fn (PayoutBatch $row): string => sprintf(
                    '#%d %s %s → `%s`',
                    $row->id,
                    $row->batch_type,
                    $row->batch_date?->toDateString() ?? '',
                    $this->rebuildCommandFor($row),
                ))->implode('; '),
            );
        }

        foreach ($later as $row) {
            if (FrozenPayoutGuard::isFrozen($row)) {
                $warnings[] = sprintf(
                    'Batch #%d (%s %s) was approved after this one; its cap allocation stands and this batch is '
                    .'measured against it.',
                    $row->id,
                    $row->batch_type,
                    $row->batch_date?->toDateString() ?? '',
                );
            }
        }

        $warnings[] = 'The rebuilt batch records you as its maker; a second person must approve it.';

        if ($kind === RebuildKind::Payout
            && ($blocker = MonthlyEngineCompletionGate::blockingFailure($period)) !== null) {
            $warnings[] = sprintf(
                'The payout close will refuse (%s); the batch is removed and the monthly run rebuilds it once the '
                .'crediting is green.',
                $blocker['engine_key'],
            );
        }

        return $warnings;
    }

    /** The rebuild command that would take a later batch apart in its turn. */
    private function rebuildCommandFor(PayoutBatch $batch): string
    {
        if ($batch->batch_type === PayoutBatch::TYPE_MONTHLY) {
            return sprintf(
                'compensation:rebuild-payout --month=%s',
                ($batch->batch_date?->copy() ?? Carbon::today())->subMonthNoOverflow()->format('Y-m'),
            );
        }

        return sprintf('compensation:rebuild-week --date=%s', $batch->batch_date?->toDateString() ?? '');
    }

    /**
     * The batch the period names: a weekly one dated the Tuesday itself, a
     * monthly one dated the 1st of the month AFTER the crediting month, which is
     * the month the money moves in.
     */
    private function batchFor(RebuildKind $kind, Carbon $period): ?PayoutBatch
    {
        return match ($kind) {
            RebuildKind::Week => PayoutBatch::query()
                ->where('batch_type', PayoutBatch::TYPE_WEEKLY)
                ->whereDate('batch_date', $period->toDateString())
                ->orderByDesc('id')
                ->first(),
            RebuildKind::Payout => FrozenPayoutGuard::batchFor($period),
            default => throw new InvalidArgumentException("[{$kind->value}] is not a payout-batch rebuild."),
        };
    }

    private function normalise(RebuildKind $kind, Carbon $period): Carbon
    {
        return $kind === RebuildKind::Week
            ? $period->copy()->startOfDay()
            : $period->copy()->startOfMonth();
    }
}
