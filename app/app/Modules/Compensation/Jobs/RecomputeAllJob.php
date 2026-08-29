<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Jobs;

use App\Modules\Compensation\Services\Recompute\CompensationRecomputeRunner;
use App\Modules\Compensation\Services\Recompute\RecomputeProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a full compensation recompute off the admin button.
 *
 * Queued because the replay takes minutes — far longer than a web request
 * should hold open, and a request timeout mid-replay is the one genuinely
 * dangerous failure mode (wiped, half-replayed).
 *
 * `$tries = 1` on purpose: a retry would resume against a database the first
 * attempt had already wiped and partly rebuilt, double-applying propagation and
 * corrupting the carry-forward chain. One attempt, fail loudly. Recovery is to
 * click the button again — every run begins by removing the rows it is about to
 * rebuild, so a re-run is a clean start rather than a resume.
 *
 * TESTING ONLY. Deleted with the rest of the recompute scaffold at sign-off.
 */
final class RecomputeAllJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public const LOCK_KEY = 'compensation:recompute:all';

    public int $tries = 1;

    public int $timeout = 7200;

    /**
     * @param  string|null  $from  first date to replay (Y-m-d)
     * @param  string|null  $to  last date to replay (Y-m-d)
     * @param  list<string>|null  $onlyEngineKeys  replay only these engines
     * @param  bool  $windowed  keep the history before $from rather than wiping it
     */
    public function __construct(
        private readonly ?int $actorUserId = null,
        private readonly ?string $from = null,
        private readonly ?string $to = null,
        private readonly ?array $onlyEngineKeys = null,
        private readonly bool $windowed = false,
    ) {
        // Hours of work in the worst case — the clearest reason the compensation
        // queue is separate from everything a distributor waits on.
        $this->onQueue('compensation');
    }

    public function handle(CompensationRecomputeRunner $runner): void
    {
        // Two concurrent replays would interleave their day loops and destroy
        // the carry-forward chain. The controller takes the same lock before
        // dispatching, so a second click is refused rather than queued.
        $lock = Cache::lock(self::LOCK_KEY, $this->timeout);

        if (! $lock->get()) {
            Log::warning('compensation.recompute.skipped', ['reason' => 'already running']);

            return;
        }

        try {
            $report = $runner->run(
                from: $this->from === null ? null : Carbon::parse($this->from),
                to: $this->to === null ? null : Carbon::parse($this->to),
                actorUserId: $this->actorUserId,
                progress: static function (string $message): void {
                    Log::info('compensation.recompute', ['message' => $message]);
                },
                onlyEngineKeys: $this->onlyEngineKeys,
                windowed: $this->windowed,
            );

            Log::info('compensation.recompute.complete', [
                'from' => $report->from->toDateString(),
                'to' => $report->to->toDateString(),
                'days' => $report->daysReplayed,
                'rows_removed' => $report->totalRowsRemoved(),
                'engine_runs' => $report->totalEngineRuns(),
                'duration_seconds' => $report->durationSeconds,
                'mode' => $report->mode,
            ]);
        } catch (Throwable $e) {
            Log::error('compensation.recompute.failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Anything that escapes handle() — the guard refusing before the runner
     * has started the progress record, the worker's timeout killing the
     * process, a stale worker on an old allow-list — would otherwise leave
     * the admin page reading "Queued" forever. The runner marks its own
     * failures; this covers the ones raised before or around it.
     */
    public function failed(Throwable $e): void
    {
        app(RecomputeProgress::class)->fail($e->getMessage());
    }
}
