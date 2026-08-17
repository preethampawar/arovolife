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
        private readonly GroupBvReplayService $groupBv,
        private readonly EngineReplayService $engines,
        private readonly DatabaseManager $db,
        private readonly RecomputeProgress $progress,
    ) {}

    /**
     * @param  Closure(string): void|null  $progress
     *
     * @throws RecomputeNotPermitted
     */
    public function run(
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?int $actorUserId = null,
        ?Closure $progress = null,
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
            $log('Wiping BV-derived state...');
            $this->progress->phase('Wiping BV-derived state');
            $rowsRemoved = $this->wiper->wipe($log);
            $this->progress->wiped(array_sum($rowsRemoved));

            [$from, $to, $windowWarnings] = $this->resolveWindow($from, $to);
            $warnings = [...$warnings, ...$windowWarnings];

            $log(sprintf('Replaying %s → %s', $from->toDateString(), $to->toDateString()));

            $log('Re-deriving group BV from paid orders...');
            $ordersPropagated = $this->groupBv->replay($log);

            $log('Replaying engines day by day...');
            $replay = $this->engines->replay($from, $to, $log);

            // Land on the real clock: today's repurchase status is what the
            // dashboards and the next scheduled cut-off will read.
            Carbon::setTestNow();
            $log('Rebuilding current repurchase state...');
            $this->progress->phase('Rebuilding current repurchase state');
            Artisan::call('repurchase:evaluate');

            $report = new RecomputeReport(
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
     * effective_at), to yesterday. A cut-off for today would freeze a partial
     * day, and credited results are never recomputed.
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
                $from = Carbon::yesterday();
            } else {
                $from = Carbon::parse(min($candidates))->startOfDay();
            }
        }

        $to ??= Carbon::yesterday()->startOfDay();

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
                'note' => 'TESTING-ONLY full compensation recompute — every BV-derived row was '
                    .'destroyed and rebuilt from the surviving orders and BV ledger.',
            ],
        ]);
    }
}
