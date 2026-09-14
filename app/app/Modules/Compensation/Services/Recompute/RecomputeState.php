<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Recompute;

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Does this database currently hold SIMULATED compensation figures?
 *
 * A recompute at {@see RecomputeHorizon::Now} leaves a database that matches
 * what the scheduler would have produced: it can be browsed, demoed and paid
 * from like any other. A recompute at `today` or `projection` fires engines at
 * instants that have not arrived — next Tuesday's payout sweep, the 1st's
 * monthly close, the 8th's batch — over the orders that exist right now. Those
 * rows are ordinary rows: they change what a distributor's wallet reads at
 * checkout, which cycles are forfeited, and what every income page shows. So
 * the environment has to say so, loudly, and two things follow from the same
 * answer:
 *
 *  1. A banner on every page (admin and distributor), because a figure that is
 *     a projection must never be read as a fact — hard rule 3 does not soften
 *     on staging.
 *  2. The scheduled engines PAUSE. They would otherwise run tonight against a
 *     carry-forward store that has already been advanced past them, and
 *     GsbCutoffService aborts on exactly that ("a later cut-off already
 *     advanced the carry-forward store"). Pausing also closes the old
 *     "never recompute between 00:00 and 00:10" rule (F125) by construction.
 *
 * The answer is derived, never stored as its own flag: the audit row a
 * recompute writes is the durable record of what was asked for, and a state
 * flag beside it could disagree with it. `queued` counts as well as completed —
 * a projection that died half-way still left simulated rows behind, and the
 * environment must stay paused until something replaces them.
 *
 * Production asks this question on every page render, so it must cost NOTHING
 * there: {@see RecomputeGuard::isPermitted()} is false in production and every
 * method returns before it touches the cache or the database.
 */
final class RecomputeState
{
    /** Recompute audit actions, newest of either wins. */
    private const ACTIONS = ['compensation.recompute_all', 'compensation.recompute_all.queued'];

    private const CACHE_KEY = 'compensation:recompute:horizon-state';

    /** Long enough to spare every page render a query, short enough that a run lands quickly. */
    private const TTL_SECONDS = 60;

    public function __construct(
        private readonly RecomputeGuard $guard,
        private readonly Cache $cache,
        private readonly RecomputeProgress $progress,
    ) {}

    /**
     * May the scheduler's compensation engines run on this environment now?
     *
     * Always true in production. On a test environment, false while a
     * projection is standing or while a replay is in flight — in both cases the
     * derived state is either ahead of the scheduler or being rewritten
     * underneath it.
     */
    public function schedulerEnginesAllowed(): bool
    {
        if (! $this->guard->isPermitted()) {
            return true;
        }

        // FAIL CLOSED, unlike the banner. A cache or database blip at 00:10 IST
        // must not let the cut-off run against a carry-forward store a
        // projection has already advanced past: a skipped nightly run on a test
        // environment is recoverable by re-running it, a corrupted
        // carry-forward chain is recoverable only by a full recompute.
        //
        // Uncached and read directly, for the same reason: the cache is Redis
        // under `allkeys-lfu` (ADR-0011), so an eviction must not be able to
        // answer "not projected" on this path.
        try {
            if ($this->readFromAuditLog()['projected'] ?? false) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return ! $this->progress->isRunning();
    }

    /** Is simulated compensation state standing in this database? */
    public function isProjected(): bool
    {
        return $this->read() !== null;
    }

    /** The instant a standing projection replayed to, or null when there is none. */
    public function projectedThrough(): ?Carbon
    {
        $state = $this->read();

        return isset($state['simulated_through'])
            ? Carbon::parse((string) $state['simulated_through'])
            : null;
    }

    /** When the standing projection was run — the "as at" of every figure it produced. */
    public function projectedAt(): ?Carbon
    {
        $state = $this->read();

        return isset($state['ran_at']) ? Carbon::parse((string) $state['ran_at']) : null;
    }

    /** Drop the cached answer — called whenever a recompute is queued or finishes. */
    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * The standing projection, or null when the database is production-faithful.
     *
     * @return array{simulated_through: string|null, ran_at: string, horizon: string}|null
     */
    private function read(): ?array
    {
        if (! $this->guard->isPermitted()) {
            return null;
        }

        try {
            /** @var array{projected: bool, simulated_through?: string|null, ran_at?: string, horizon?: string} $state */
            $state = $this->cache->remember(
                self::CACHE_KEY,
                self::TTL_SECONDS,
                fn (): array => $this->readFromAuditLog(),
            );
        } catch (Throwable) {
            // A missing audit table (a half-migrated environment) must never
            // take a page down over a banner.
            return null;
        }

        if (($state['projected'] ?? false) !== true) {
            return null;
        }

        return [
            'simulated_through' => $state['simulated_through'] ?? null,
            'ran_at' => $state['ran_at'] ?? '',
            'horizon' => $state['horizon'] ?? RecomputeHorizon::Now->value,
        ];
    }

    /**
     * @return array{projected: bool, simulated_through?: string|null, ran_at?: string, horizon?: string}
     */
    private function readFromAuditLog(): array
    {
        $row = AuditLog::query()
            ->whereIn('action', self::ACTIONS)
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return ['projected' => false];
        }

        $details = is_array($row->details) ? $row->details : [];

        // Rows written before horizons existed described a run that stopped at
        // "now" unless its To date said otherwise; read them as production-
        // faithful rather than invent a projection out of a legacy field.
        $horizon = RecomputeHorizon::fromValue(
            is_string($details['horizon'] ?? null) ? $details['horizon'] : null,
        );

        if (! $horizon->isProjected()) {
            return ['projected' => false];
        }

        return [
            'projected' => true,
            'horizon' => $horizon->value,
            'simulated_through' => is_string($details['simulated_through'] ?? null)
                ? $details['simulated_through']
                : null,
            'ran_at' => $row->created_at?->toDateTimeString() ?? '',
        ];
    }
}
