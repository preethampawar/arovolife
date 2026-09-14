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
 * TEST ENVIRONMENTS ONLY — {@see RecomputeGuard} refuses in production, where
 * the engines stay write-once and forward-only. On dev and staging this is the
 * whole compensation runner: it wipes the derived rows and replays every engine
 * at the instant the scheduler would have fired it, up to a
 * {@see RecomputeHorizon}. See docs/architecture/adr-0014-test-environment-recompute.md.
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
        private readonly RecomputeState $state,
    ) {}

    /**
     * @param  Carbon|null  $from  first date to replay; null starts at the first
     *                             BV date
     * @param  RecomputeHorizon  $horizon  how far the scheduler's calendar is
     *                                     replayed — see the enum. `Now` is
     *                                     production-faithful; the other two
     *                                     simulate firings that have not
     *                                     happened and mark the environment
     *                                     projected.
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
        RecomputeHorizon $horizon = RecomputeHorizon::Now,
        ?int $actorUserId = null,
        ?Closure $progress = null,
        ?array $onlyEngineKeys = null,
        bool $windowed = false,
    ): RecomputeReport {
        $this->guard->ensurePermitted();

        $log = $progress ?? static fn (string $_m): null => null;
        $startedAt = microtime(true);
        $warnings = [];
        $callerClock = Carbon::getTestNow();
        $horizonInstant = $horizon->instantFrom(Carbon::now());

        $this->progress->start();

        // Back-dated transitions would otherwise mail every distributor about
        // repurchase cycles that opened weeks ago. The event bus stays live —
        // listeners like PropagateGroupBvOnOrderPaid are part of a correct
        // recomputation — only the transport is muted.
        //
        // Both mutes are captured first and restored in the `finally` below.
        // A queue worker is a long-lived process that handles many jobs, so a
        // swap left in place outlives this replay: every later
        // SendQueuedNotifications in that worker resolves the NotificationFake
        // and dies with "Argument #1 ($manager) must be of type ChannelManager".
        // Those notifications are then lost — the job fails after the event
        // that produced it has already been consumed.
        $realNotificationChannelManager = Notification::getFacadeRoot();
        $realMailer = config('mail.default');

        Notification::fake();
        config(['mail.default' => 'array']);

        try {
            $windowed = $windowed && $from !== null;

            if ($windowed) {
                // A monthly engine's period is a whole month, so a window that
                // opens mid-month can only be rebuilt from that month's first
                // day — unless the month is still in flight, whose engines have
                // not fired at all yet (they fire on the 1st of the next month),
                // so there is nothing to rebuild whole.
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

            $from = $this->resolveFrom($from, $warnings);

            if ($horizon->isProjected()) {
                $warnings[] = sprintf(
                    'Projected through %s. Every engine after %s fired on a simulated clock over the orders that '
                        .'exist now, so the cycles, bonuses, wallet balances and payout batches it produced are '
                        .'provisional. The scheduled engines are paused on this environment until the nightly reset '
                        .'or an "up to now" recompute.',
                    $horizonInstant->format('d M Y H:i'),
                    Carbon::now()->format('d M Y H:i'),
                );
            }

            $log(sprintf(
                'Replaying %s → %s (%s)',
                $from->toDateString(),
                $horizonInstant->format('Y-m-d H:i'),
                $horizon->value,
            ));

            $log('Re-deriving group BV from paid orders...');
            $ordersPropagated = $this->groupBv->replay($log, $windowed ? $from : null);

            $log('Replaying engines at the instants the scheduler would have used...');
            $replay = $this->engines->replay($from, $horizonInstant, $log, $onlyEngineKeys);

            if ($replay['skipped'] !== []) {
                $warnings[] = 'Engines not replayed: '.implode(', ', $replay['skipped'])
                    .'. Their results for this window are now missing, not merely stale.';
            }

            // Land on the real clock: today's repurchase status is what the
            // dashboards and the next scheduled cut-off will read.
            //
            // Only for the `now` horizon. A projection has already evaluated
            // cycles at instants beyond today; re-running the evaluation as at
            // today would stamp today's purchases onto cycles the replay has
            // moved past, which is the one thing the command's own description
            // warns against.
            if (! $horizon->isProjected()) {
                Carbon::setTestNow($callerClock);
                $log('Rebuilding current repurchase state...');
                $this->progress->phase('Rebuilding current repurchase state');
                Artisan::call('repurchase:evaluate');
            }

            $report = new RecomputeReport(
                mode: $windowed ? RecomputeReport::MODE_WINDOWED : RecomputeReport::MODE_FULL,
                from: $from,
                to: $horizonInstant->copy()->startOfDay(),
                horizon: $horizon,
                simulatedThrough: $horizon->isProjected() ? $horizonInstant->copy() : null,
                rowsRemoved: $rowsRemoved,
                ordersPropagated: $ordersPropagated,
                daysReplayed: $replay['days'],
                enginesRun: $replay['engines'],
                warnings: $warnings,
                durationSeconds: round(microtime(true) - $startedAt, 1),
            );

            $this->audit($report, $actorUserId);
            $this->state->forget();
            $this->progress->complete($report);

            return $report;
        } catch (Throwable $e) {
            $this->progress->fail($e->getMessage());

            throw $e;
        } finally {
            // A replay that dies mid-flight must never leave the process — or a
            // queue worker reusing it — on a fake clock, on a fake notification
            // channel manager, or on the array mailer. The caller's own clock is
            // put back, not cleared: a test that pinned one keeps it.
            Carbon::setTestNow($callerClock);
            Notification::swap($realNotificationChannelManager);
            config(['mail.default' => $realMailer]);
        }
    }

    /**
     * The first day to replay: the caller's date, or the first BV / first paid
     * order (whichever is earlier, since propagation keys on paid_at while the
     * pools key on effective_at).
     *
     * There is no `to`: where a replay stops is a {@see RecomputeHorizon}, and
     * the horizon is an INSTANT rather than a date because the engines that
     * settle a day fire in the small hours of the next one.
     *
     * @param  list<string>  $warnings
     */
    private function resolveFrom(?Carbon $from, array &$warnings): Carbon
    {
        if ($from !== null) {
            return $from;
        }

        $firstBv = $this->db->table('bv_ledger_entries')->min('effective_at');
        $firstOrder = $this->db->table('orders')->where('status', 'paid')->min('paid_at');

        $candidates = array_filter([$firstBv, $firstOrder]);

        if ($candidates === []) {
            $warnings[] = 'No BV and no paid orders — there is nothing to replay.';

            return Carbon::today();
        }

        return Carbon::parse(min($candidates))->startOfDay();
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
                'horizon' => $report->horizon->value,
                'simulated_through' => $report->simulatedThrough?->toDateTimeString(),
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
