<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * How much work the compensation queue still holds at the instant the nightly
 * run wants to price a day.
 *
 * 00:05 was chosen so an order paid at 23:58 could land its PropagateGroupBvJob
 * before the cut-off (routes/console.php). Five minutes is a guess about the
 * worker, and a backed-up worker lands BV on a day already cut off — a day
 * whose pools are frozen once and never repriced (E4, 2026-09-26 review). So
 * the night waits for the queue, up to a cap, and refuses past it.
 *
 * Every job on the queue counts, not only BV: a manual engine chain or a
 * rebuild queued at 23:59 is exactly what the run must not start beside.
 */
final class CompensationQueueBacklog
{
    public const QUEUE = 'compensation';

    /** The run must finish before the 02:30 snapshot and the 03:00 weekly run. */
    public const DEFAULT_MAX_WAIT_SECONDS = 1200;

    public const DEFAULT_POLL_SECONDS = 15;

    public function __construct(
        private readonly int $maxWaitSeconds = self::DEFAULT_MAX_WAIT_SECONDS,
        private readonly int $pollSeconds = self::DEFAULT_POLL_SECONDS,
    ) {}

    /** Jobs on the compensation queue right now — waiting or reserved. */
    public function depth(): int
    {
        try {
            return DB::table((string) config('queue.connections.database.table', 'jobs'))
                ->where('queue', self::QUEUE)
                ->count();
        } catch (Throwable $e) {
            // A safety net, not a gate: a jobs table that cannot be read must
            // not hold the night on top of everything else it breaks.
            Log::warning('compensation.queue_backlog.unreadable', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Poll until the queue is empty or the wait is exhausted. Returns the depth
     * left (0 = drained). $onTick receives (int $depth, int $waitedSeconds) once
     * per poll, for the console.
     */
    public function waitUntilDrained(?callable $onTick = null): int
    {
        $waited = 0;
        $depth = $this->depth();

        while ($depth > 0 && $waited < $this->maxWaitSeconds) {
            if ($onTick !== null) {
                $onTick($depth, $waited);
            }

            sleep(max(1, $this->pollSeconds));
            $waited += max(1, $this->pollSeconds);
            $depth = $this->depth();
        }

        return $depth;
    }

    public function maxWaitSeconds(): int
    {
        return $this->maxWaitSeconds;
    }
}
