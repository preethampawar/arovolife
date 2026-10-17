<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Jobs\RetryPayoutBuildFailureJob;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutBuildFailure;
use App\Modules\Compensation\Services\PayoutBuildFailureRecorder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Payouts → Build failures: every distributor a payout batch skipped because
 * their line could not be built, with the whole error and a Retry (user
 * 2026-10-17). `finance.record` — the role that runs batches; a retry builds a
 * line, which is making, not checking.
 */
final class AdminPayoutBuildFailureController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $request->validate([
            'status' => ['nullable', 'in:open,resolved,all'],
            'type' => ['nullable', 'in:weekly,monthly'],
            'batch' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $status = (string) $request->query('status', 'open');
        $type = $request->query('type');
        $batchId = $request->integer('batch') ?: null;
        $q = $request->query('q');

        $rows = PayoutBuildFailure::query()
            ->with(['payoutBatch', 'resolvedByBatch', 'distributor.user'])
            ->when($status === 'open', fn ($b) => $b->whereIn('status', PayoutBuildFailure::UNRESOLVED_STATUSES))
            ->when($status === 'resolved', fn ($b) => $b->where('status', PayoutBuildFailure::STATUS_RESOLVED))
            ->when($type !== null, fn ($b) => $b->whereHas('payoutBatch', fn ($p) => $p->where('batch_type', $type)))
            ->when($batchId !== null, fn ($b) => $b->where('payout_batch_id', $batchId))
            ->when($q, fn ($b) => $b->whereHas('distributor', fn ($d) => $d->where('adn', 'like', '%'.$q.'%')))
            ->when($request->string('from')->toString(), fn ($b, string $from) => $b->whereDate('last_failed_at', '>=', $from))
            ->when($request->string('to')->toString(), fn ($b, string $to) => $b->whereDate('last_failed_at', '<=', $to))
            ->orderByDesc('last_failed_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.compensation.payout-build-failures.index', [
            'rows' => $rows,
            'status' => $status,
            'type' => $type,
            'batchId' => $batchId,
            'q' => $q,
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'openCount' => PayoutBuildFailure::query()->whereIn('status', PayoutBuildFailure::UNRESOLVED_STATUSES)->count(),
            'staleMinutes' => PayoutBuildFailureRecorder::STALE_RETRY_MINUTES,
        ]);
    }

    public function retry(Request $request, PayoutBuildFailure $failure, PayoutBuildFailureRecorder $recorder): RedirectResponse
    {
        $batch = $failure->payoutBatch;

        if ($batch === null || $batch->approved_at !== null) {
            return back()->with('error', 'This batch was approved without this distributor, so nothing can be added to it. Their earnings stay in their wallet and are paid by the next batch.');
        }

        $actorId = (int) $request->user()->id;

        if (! $recorder->claimForRetry($failure, $actorId)) {
            return back()->with('error', 'This distributor is already being retried, or no longer needs it. Refresh the page in a moment.');
        }

        RetryPayoutBuildFailureJob::dispatch((int) $failure->id, $actorId);

        return back()->with('success', 'Retry queued for '.($failure->distributor->adn ?? 'the distributor').'. Refresh in a minute to see the result.');
    }

    public function retryBatch(Request $request, PayoutBatch $batch, PayoutBuildFailureRecorder $recorder): RedirectResponse
    {
        if ($batch->approved_at !== null) {
            return back()->with('error', 'This batch was approved without these distributors, so nothing can be added to it. They are paid by the next batch.');
        }

        $actorId = (int) $request->user()->id;
        $queued = 0;

        PayoutBuildFailure::query()
            ->where('payout_batch_id', $batch->id)
            ->whereIn('status', PayoutBuildFailure::UNRESOLVED_STATUSES)
            ->orderBy('id')
            ->each(function (PayoutBuildFailure $failure) use ($recorder, $actorId, &$queued): void {
                if ($recorder->claimForRetry($failure, $actorId)) {
                    RetryPayoutBuildFailureJob::dispatch((int) $failure->id, $actorId);
                    $queued++;
                }
            });

        return back()->with($queued > 0 ? 'success' : 'error', $queued > 0
            ? $queued.' retr'.($queued === 1 ? 'y' : 'ies').' queued. Refresh in a minute to see the results.'
            : 'Nothing to retry: every failure in this batch is already being retried or resolved.');
    }
}
