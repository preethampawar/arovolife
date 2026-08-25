<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Recompute;

use App\Modules\Compensation\Services\DTOs\RecomputeReport;
use App\Modules\Compliance\Models\AuditLog;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The one sequence a full compensation recompute follows. The artisan command
 * and the admin button both call this — neither owns a second copy of the
 * order of operations, because getting that order wrong is how you corrupt the
 * carry-forward chain.
 *
 * TESTING ONLY. Scheduled for deletion once the compensation plan is signed
 * off; see docs/runbooks/artisan-commands.md.
 */
final class CompensationRecomputeRunner
{
    public function __construct(
        private readonly RecomputeGuard $guard,
        private readonly CompensationStateWiper $wiper,
        private readonly WindowedStateWiper $windowedWiper,
        private readonly GroupBvReplayService $groupBv,
        private readonly EngineReplayService $engines,
        private readonly DatabaseManager $db,
        private readonly RecomputeProgress $progress,
    ) {}

    /**
     * @param  Carbon|null  $from  first date to replay; null starts at the first
     *                             BV date
     * @param  list<string>|null  $onlyEngineKeys  replay only these engines
     * @param  bool  $windowed  keep the history before $from instead of wiping
     *                          it — only the derived rows from $from onwards are
     *                          destroyed and rebuilt. Requires $from. The mode
     *                          is explicit and never inferred: "replay from
     *                          Tuesday" and "rebuild only Tuesday onwards" are
     *                          different operations and one silently becoming
     *                          the other would corrupt the carry-forward chain.
     * @param  Closure(string): void|null  $progress
     *
     * @throws RecomputeNotPermitted
     */
    public function run(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?int $actorUserId = null,
        ?Closure $progress = null,
        ?array $onlyEngineKeys = null,
        bool $windowed = false,
    ): RecomputeReport {
        $this->guard->ensurePermitted();

        $log = $progress ?? static fn (string $_m): null => null;
        $startedAt = microtime(true);
        $warnings = [];

        $this->progress->start();

        // Back-dated transitions would otherwise mail every distributor about
        // repurchase cycles that opened weeks ago. The event bus stays live —
        // listeners like ReleaseHeldGsbOnReactivation are part of a correct
        // recomputation — only the transport is muted.
        Notification::fake();
        config(['mail.default' => 'array']);

        try {
            $windowed = $windowed && $from !== null;

            if ($windowed) {
                // A monthly engine's period is a whole month, so a window that
                // opens mid-month can only be rebuilt from that month's first
                // day — unless the month is still in flight, where the replay's
                // catch-up pass recomputes it regardless of the start day.
                $requestedFrom = $from->copy()->startOfDay();
                $from = $requestedFrom->isSameMonth(Carbon::today())
                    ? $requestedFrom
                    : $requestedFrom->copy()->startOfMonth();

                if (! $from->equalTo($requestedFrom)) {
                    $warnings[] = sprintf(
                        'Window widened to %s: %s falls in a closed month, whose monthly bonuses can only be rebuilt whole.',
                        $from->toDateString(),
                        $requestedFrom->toDateString(),
                    );
                }
            }

            $log($windowed
                ? sprintf('Removing BV-derived state from %s...', $from->toDateString())
                : 'Wiping BV-derived state...');
            $this->progress->phase($windowed ? 'Removing BV-derived state in the window' : 'Wiping BV-derived state');
            $rowsRemoved = $windowed
                ? $this->windowedWiper->wipe($from, $log)
                : $this->wiper->wipe($log);
            $this->progress->wiped(array_sum($rowsRemoved));

            [$from, $to, $windowWarnings] = $this->resolveWindow($from, $to);
            $warnings = [...$warnings, ...$windowWarnings];

            $log(sprintf('Replaying %s → %s', $from->toDateString(), $to->toDateString()));

            $log('Re-deriving group BV from paid orders...');
            $ordersPropagated = $this->groupBv->replay($log, $windowed ? $from : null);

            $log('Replaying engines day by day...');
            $replay = $this->engines->replay($from, $to, $log, $onlyEngineKeys);

            if ($replay['skipped'] !== []) {
                $warnings[] = 'Engines not replayed: '.implode(', ', $replay['skipped'])
                    .'. Their results for this window are now missing, not merely stale.';
            }

            // Land on the real clock: today's repurchase status is what the
            // dashboards and the next scheduled cut-off will read.
            Carbon::setTestNow();
            $log('Rebuilding current repurchase state...');
            $this->progress->phase('Rebuilding current repurchase state');
            Artisan::call('repurchase:evaluate');

            $report = new RecomputeReport(
                mode: $windowed ? RecomputeReport::MODE_WINDOWED : RecomputeReport::MODE_FULL,
                from: $from,
                to: $to,
                rowsRemoved: $rowsRemoved,
                ordersPropagated: $ordersPropagated,
                daysReplayed: $replay['days'],
                enginesRun: $replay['engines'],
                warnings: $warnings,
                durationSeconds: round(microtime(true) - $startedAt, 1),
            );

            $this->audit($report, $actorUserId);
            $this->progress->complete($report);

            return $report;
        } catch (Throwable $e) {
            $this->progress->fail($e->getMessage());

            throw $e;
        } finally {
            // A replay that dies mid-flight must never leave the process — or a
            // queue worker reusing it — on a fake clock.
            Carbon::setTestNow();
        }
    }

    /**
     * The window to replay: from the first BV or first paid order (whichever is
     * earlier, since propagation keys on paid_at while the pools key on
     * effective_at), through today.
     *
     * Today is included even though it is a partial day. Production stops at
     * yesterday because a frozen result is never recomputed, so freezing half a
     * day would underpay it permanently — but here every run begins by wiping
     * the lot, so "partial" only ever means "as at the moment you clicked", and
     * the next click supersedes it. Testing the plan on data up to and including
     * today is the entire point of the tool.
     *
     * @return array{0: Carbon, 1: Carbon, 2: list<string>}
     */
    private function resolveWindow(?Carbon $from, ?Carbon $to): array
    {
        $warnings = [];

        if ($from === null) {
            $firstBv = $this->db->table('bv_ledger_entries')->min('effective_at');
            $firstOrder = $this->db->table('orders')->where('status', 'paid')->min('paid_at');

            $candidates = array_filter([$firstBv, $firstOrder]);

            if ($candidates === []) {
                $warnings[] = 'No BV and no paid orders — there is nothing to replay.';
                $from = Carbon::today();
            } else {
                $from = Carbon::parse(min($candidates))->startOfDay();
            }
        }

        $to ??= Carbon::today()->startOfDay();

        if ($to->lt($from)) {
            $warnings[] = sprintf(
                'Replay window ends (%s) before it starts (%s) — nothing was replayed.',
                $to->toDateString(),
                $from->toDateString(),
            );
        }

        return [$from, $to, $warnings];
    }

    private function audit(RecomputeReport $report, ?int $actorUserId): void
    {
        AuditLog::create([
            'actor_id' => $actorUserId,
            'action' => 'compensation.recompute_all',
            'subject_type' => 'platform',
            'subject_id' => 0,
            'details' => [
                'from' => $report->from->toDateString(),
                'to' => $report->to->toDateString(),
                'rows_removed' => $report->rowsRemoved,
                'total_rows_removed' => $report->totalRowsRemoved(),
                'orders_propagated' => $report->ordersPropagated,
                'days_replayed' => $report->daysReplayed,
                'engines_run' => $report->enginesRun,
                'warnings' => $report->warnings,
                'duration_seconds' => $report->durationSeconds,
                'mode' => $report->mode,
                'note' => $report->mode === RecomputeReport::MODE_WINDOWED
                    ? 'TESTING-ONLY windowed compensation recompute — BV-derived rows from the '
                        .'window start onwards were destroyed and rebuilt from the surviving '
                        .'orders and BV ledger; earlier history was left intact.'
                    : 'TESTING-ONLY full compensation recompute — every BV-derived row was '
                        .'destroyed and rebuilt from the surviving orders and BV ledger.',
            ],
        ]);
    }
}
