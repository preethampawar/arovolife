<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Recompute;

use App\Modules\Compensation\Services\DTOs\RecomputeReport;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

/**
 * Live progress for a running recompute, so the admin console can show what the
 * replay is doing instead of a spinner that might mean anything.
 *
 * Cache-backed rather than a table: the replay truncates engine_runs and every
 * other derived table as its first act, so progress cannot live anywhere it
 * would wipe. It is also genuinely ephemeral — nobody needs yesterday's
 * progress bar.
 *
 * The runner writes here on every step regardless of who invoked it, so the CLI
 * and the queued job publish identical state; the console additionally prints
 * lines. One code path, two renderings.
 *
 * TESTING ONLY — removed with the rest of the recompute scaffold at sign-off.
 */
final class RecomputeProgress
{
    private const KEY = 'compensation:recompute:progress';

    private const TTL_SECONDS = 7200;

    /**
     * How long a 'running' state may go without a heartbeat before the reader
     * treats it as dead.
     *
     * A replay killed from outside the process — `queue:listen` enforcing its
     * 60s child-process timeout, an OOM, a deploy restarting the worker — never
     * reaches fail(), so the last state it wrote stays 'running' until the TTL
     * expires two hours later. The bar sits frozen and the operator cannot tell
     * a dead run from a slow one.
     *
     * Generous on purpose. Every step of the replay publishes, and the longest
     * legitimate gap is one day of engines, but calling a live run dead is the
     * expensive mistake: the console unblocks the button off this, and two
     * concurrent replays would interleave their day loops.
     */
    private const STALE_AFTER_SECONDS = 900;

    /**
     * Share of the bar each phase owns. The day loop dominates the wall clock,
     * so it gets most of the bar; the wipe is near-instant but visible.
     */
    private const WEIGHT_WIPE = 5;

    private const WEIGHT_PROPAGATE = 15;

    private const WEIGHT_REPLAY = 75;

    public const STATE_RUNNING = 'running';

    public const STATE_COMPLETE = 'complete';

    public const STATE_FAILED = 'failed';

    public function __construct(private readonly Cache $cache) {}

    /**
     * Published by the controller the moment the job is dispatched, so the page
     * the operator lands on after the redirect shows *this* run rather than the
     * finished summary of the previous one. Without it the poller reads the
     * stale 'complete' state, renders it, and stops — the new run then never
     * appears, because nothing is polling by the time the worker picks it up.
     */
    public function queued(): void
    {
        $this->write($this->initialState('Queued', 'waiting for the queue worker to pick it up'));
    }

    public function start(): void
    {
        $this->write($this->initialState('Starting'));
    }

    /**
     * @return array<string, mixed>
     */
    private function initialState(string $phase, ?string $detail = null): array
    {
        return [
            'state' => self::STATE_RUNNING,
            'phase' => $phase,
            'detail' => $detail,
            'percent' => 0,
            'days_total' => 0,
            'days_done' => 0,
            'orders_total' => 0,
            'orders_done' => 0,
            'current_date' => null,
            'current_engines' => [],
            'engine_runs' => 0,
            'rows_removed' => 0,
            'started_at' => $this->realNow()->toIso8601String(),
            'finished_at' => null,
            'error' => null,
            'summary' => null,
        ];
    }

    public function phase(string $phase, ?string $detail = null): void
    {
        $this->merge(['phase' => $phase, 'detail' => $detail]);
    }

    public function wiped(int $rowsRemoved): void
    {
        $this->merge([
            'phase' => 'Wiped BV-derived state',
            'detail' => number_format($rowsRemoved).' rows removed',
            'rows_removed' => $rowsRemoved,
            'percent' => self::WEIGHT_WIPE,
        ]);
    }

    public function ordersTotal(int $total): void
    {
        $this->merge(['orders_total' => $total]);
    }

    public function ordersProgressed(int $done): void
    {
        $state = $this->read();
        $total = (int) ($state['orders_total'] ?? 0);

        $share = $total > 0 ? (int) round(self::WEIGHT_PROPAGATE * min($done, $total) / $total) : self::WEIGHT_PROPAGATE;

        $this->merge([
            'phase' => 'Re-deriving group BV from paid orders',
            'detail' => $total > 0 ? "{$done} of {$total} orders" : "{$done} orders",
            'orders_done' => $done,
            'percent' => self::WEIGHT_WIPE + $share,
        ]);
    }

    public function daysTotal(int $total): void
    {
        $this->merge(['days_total' => $total]);
    }

    /**
     * @param  list<string>  $engines  artisan signatures fired on this date
     */
    public function dayReplayed(string $date, array $engines, int $index, int $engineRuns): void
    {
        $state = $this->read();
        $total = (int) ($state['days_total'] ?? 0);

        $share = $total > 0 ? (int) round(self::WEIGHT_REPLAY * min($index, $total) / $total) : 0;

        $this->merge([
            'phase' => 'Replaying engines day by day',
            'detail' => $total > 0 ? "day {$index} of {$total}" : "day {$index}",
            'days_done' => $index,
            'current_date' => $date,
            'current_engines' => $engines,
            'engine_runs' => $engineRuns,
            'percent' => self::WEIGHT_WIPE + self::WEIGHT_PROPAGATE + $share,
        ]);
    }

    public function complete(RecomputeReport $report): void
    {
        $this->merge([
            'state' => self::STATE_COMPLETE,
            'phase' => 'Complete',
            'detail' => null,
            'percent' => 100,
            'current_date' => null,
            'current_engines' => [],
            'finished_at' => $this->realNow()->toIso8601String(),
            'summary' => [
                'from' => $report->from->toDateString(),
                'to' => $report->to->toDateString(),
                'days' => $report->daysReplayed,
                'orders' => $report->ordersPropagated,
                'engine_runs' => $report->totalEngineRuns(),
                'rows_removed' => $report->totalRowsRemoved(),
                'duration_seconds' => $report->durationSeconds,
                'warnings' => $report->warnings,
            ],
        ]);
    }

    public function fail(string $error): void
    {
        $this->merge([
            'state' => self::STATE_FAILED,
            'phase' => 'Failed',
            'detail' => null,
            'error' => $error,
            'finished_at' => $this->realNow()->toIso8601String(),
        ]);
    }

    /**
     * The published state, with a stalled run reported as a failed one.
     *
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        $state = $this->readRaw();

        return $state === null ? null : $this->failIfStalled($state);
    }

    /**
     * Exactly what is in the cache, with no stall detection. Only merge()
     * should use this: folding a downgraded state back into the cache would
     * make the failure permanent for a run that is merely between heartbeats.
     *
     * @return array<string, mixed>|null
     */
    private function readRaw(): ?array
    {
        $state = $this->cache->get(self::KEY);

        return is_array($state) ? $state : null;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function failIfStalled(array $state): array
    {
        if (($state['state'] ?? null) !== self::STATE_RUNNING) {
            return $state;
        }

        $heartbeat = $state['heartbeat_at'] ?? null;

        if (! is_string($heartbeat)) {
            return $state;
        }

        $silentFor = Carbon::parse($heartbeat)->diffInSeconds($this->realNow(), absolute: false);

        if ($silentFor <= self::STALE_AFTER_SECONDS) {
            return $state;
        }

        return [
            ...$state,
            'state' => self::STATE_FAILED,
            'phase' => 'Failed',
            'detail' => null,
            'finished_at' => $heartbeat,
            'error' => sprintf(
                'the replay stopped reporting %d minutes ago during "%s" and the worker process is gone. '
                .'It was almost certainly killed from outside — `queue:listen` kills each job at 60s, so this '
                .'job needs `queue:work` or the artisan command. The database is wiped and only partly rebuilt; '
                .'run the recompute again to finish it.',
                (int) round($silentFor / 60),
                $state['phase'] ?? 'unknown phase',
            ),
        ];
    }

    public function isRunning(): bool
    {
        return ($this->read()['state'] ?? null) === self::STATE_RUNNING;
    }

    public function clear(): void
    {
        $this->cache->forget(self::KEY);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function write(array $state): void
    {
        // The replay travels the clock backwards, and most progress updates are
        // published from inside that travelled section. A cache TTL is computed
        // against Carbon::now(), so a write made "on 5 June" expires two hours
        // after 5 June — which is already long past by the time the clock is
        // restored, silently erasing the progress the moment the run finishes.
        // Write against the real clock, then hand the fake one back.
        $travelled = Carbon::getTestNow();

        try {
            Carbon::setTestNow();
            $state['heartbeat_at'] = Carbon::now()->toIso8601String();
            $this->cache->put(self::KEY, $state, self::TTL_SECONDS);
        } finally {
            Carbon::setTestNow($travelled);
        }
    }

    /**
     * The wall clock, never the one the replay has travelled to. Heartbeats and
     * staleness are about how long a process has really been silent.
     */
    private function realNow(): Carbon
    {
        $travelled = Carbon::getTestNow();

        try {
            Carbon::setTestNow();

            return Carbon::now();
        } finally {
            Carbon::setTestNow($travelled);
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function merge(array $changes): void
    {
        $this->write([...($this->readRaw() ?? []), ...$changes]);
    }
}
