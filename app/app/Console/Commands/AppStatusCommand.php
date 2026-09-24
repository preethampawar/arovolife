<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Read-only service health report: database, cache, maintenance mode, pending
 * migrations, queue workers, queue backlog, failed jobs and the scheduler.
 *
 * `app:deploy` runs it last, in a fresh process so it reports on the code and
 * config the deploy just installed. It is equally safe to run by hand at any
 * time on any environment — it writes nothing but one throwaway cache key.
 *
 * Exits non-zero when any check FAILs; a WARN never changes the exit code.
 */
final class AppStatusCommand extends Command
{
    /** Written every minute by the scheduler (routes/console.php). Unix timestamp. */
    public const SCHEDULER_HEARTBEAT_KEY = 'ops:scheduler:heartbeat';

    /** The three named queues, each drained by its own worker (ADR-0011). */
    private const QUEUES = ['otp', 'default', 'compensation'];

    protected $signature = 'app:status
        {--wait=0 : Seconds to keep polling for queue workers that are not up yet (a queue:restart takes up to a minute to come back under cron)}';

    protected $description = 'Report the health of the database, cache, queue workers, scheduler and migrations.';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    public function handle(): int
    {
        $this->checkApp();
        $this->checkDatabase();
        $this->checkCache();
        $this->checkMigrations();
        $this->checkWorkers(max(0, (int) $this->option('wait')));
        $this->checkBacklog();
        $this->checkFailedJobs();
        $this->checkScheduler();

        $this->table(['Check', 'Status', 'Detail'], $this->rows);

        $failed = count(array_filter($this->rows, static fn (array $r): bool => $r[1] === 'FAIL'));
        $warned = count(array_filter($this->rows, static fn (array $r): bool => $r[1] === 'WARN'));

        if ($failed > 0) {
            $this->error("✘ {$failed} check(s) failed, {$warned} warning(s).");

            return self::FAILURE;
        }

        $this->info($warned > 0 ? "✓ all checks passed, {$warned} warning(s)." : '✓ all checks passed.');

        return self::SUCCESS;
    }

    private function checkApp(): void
    {
        $commit = $this->shell(['git', 'rev-parse', '--short', 'HEAD']) ?? 'unknown';
        $this->pass('Release', 'env='.config('app.env').", commit={$commit}, php=".PHP_VERSION);

        app()->isDownForMaintenance()
            ? $this->failure('Maintenance mode', 'application is DOWN — run php artisan up')
            : $this->pass('Maintenance mode', 'up');
    }

    private function checkDatabase(): void
    {
        try {
            $started = microtime(true);
            DB::select('select 1');
            $ms = (int) round((microtime(true) - $started) * 1000);
            $this->pass('Database', DB::connection()->getDriverName().' '.DB::connection()->getDatabaseName()." ({$ms} ms)");
        } catch (Throwable $e) {
            $this->failure('Database', $this->short($e));
        }
    }

    private function checkCache(): void
    {
        $store = (string) config('cache.default');
        $key = 'ops:status:probe:'.Str::random(8);
        $token = Str::random(16);

        try {
            Cache::put($key, $token, 30);
            $read = Cache::get($key);
            Cache::forget($key);

            $read === $token
                ? $this->pass('Cache', "{$store} store read/write OK; session driver ".config('session.driver'))
                : $this->failure('Cache', "{$store} store accepted a write but returned nothing");
        } catch (Throwable $e) {
            $this->failure('Cache', "{$store}: ".$this->short($e));
        }
    }

    private function checkMigrations(): void
    {
        try {
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                $this->failure('Migrations', 'migrations table missing');

                return;
            }

            $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
            $pending = array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));

            $pending === []
                ? $this->pass('Migrations', count($files).' applied, none pending')
                : $this->failure('Migrations', count($pending).' pending, first: '.$pending[0]);
        } catch (Throwable $e) {
            $this->failure('Migrations', $this->short($e));
        }
    }

    /**
     * Looks for a live `queue:work … --queue=<name>` process per queue. Works
     * for both launchers in use: Cloudways Supervisord (production) and the
     * flock'd crontab (staging), which can take up to a minute to respawn a
     * worker after queue:restart — hence the optional polling window.
     */
    private function checkWorkers(int $wait): void
    {
        $deadline = time() + $wait;

        do {
            $counts = $this->workerCounts();

            if ($counts === null || ! in_array(0, $counts, true) || time() >= $deadline) {
                break;
            }

            sleep(5);
        } while (true);

        if ($counts === null) {
            $this->caution('Queue workers', 'could not list processes (ps unavailable)');

            return;
        }

        foreach ($counts as $queue => $n) {
            $n > 0
                ? $this->pass("Worker: {$queue}", "{$n} process(es)")
                : $this->failure("Worker: {$queue}", 'no queue:work process found for this queue');
        }
    }

    /** @return array<string, int>|null */
    private function workerCounts(): ?array
    {
        $out = $this->shell(['ps', '-eo', 'args=']);

        if ($out === null) {
            return null;
        }

        $counts = array_fill_keys(self::QUEUES, 0);

        foreach (explode("\n", $out) as $line) {
            if (! str_contains($line, 'queue:work') || ! preg_match('/--queue[= ]([\w,-]+)/', $line, $m)) {
                continue;
            }

            foreach (explode(',', $m[1]) as $queue) {
                if (isset($counts[$queue])) {
                    $counts[$queue]++;
                }
            }
        }

        return $counts;
    }

    private function checkBacklog(): void
    {
        try {
            $rows = DB::table('jobs')
                ->selectRaw('queue, count(*) as n, min(available_at) as oldest')
                ->groupBy('queue')
                ->get();
        } catch (Throwable $e) {
            $this->caution('Queue backlog', $this->short($e));

            return;
        }

        if ($rows->isEmpty()) {
            $this->pass('Queue backlog', 'empty');

            return;
        }

        $parts = [];
        $stale = false;
        foreach ($rows as $row) {
            $age = max(0, time() - (int) $row->oldest);
            $stale = $stale || $age > 900;
            $parts[] = "{$row->queue}={$row->n} (oldest ".intdiv($age, 60).' min)';
        }

        // A long engine chain legitimately holds `compensation` for a while,
        // so an old job is a warning to look, not a failure.
        $stale
            ? $this->caution('Queue backlog', implode(', ', $parts))
            : $this->pass('Queue backlog', implode(', ', $parts));
    }

    private function checkFailedJobs(): void
    {
        try {
            $total = DB::table('failed_jobs')->count();
            $recent = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        } catch (Throwable $e) {
            $this->caution('Failed jobs', $this->short($e));

            return;
        }

        $recent > 0
            ? $this->caution('Failed jobs', "{$recent} in the last 24h, {$total} total — php artisan queue:failed")
            : $this->pass('Failed jobs', "none in the last 24h ({$total} total)");
    }

    private function checkScheduler(): void
    {
        try {
            $beat = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);
        } catch (Throwable $e) {
            $this->failure('Scheduler', $this->short($e));

            return;
        }

        // Redis hands integers back as numeric strings.
        if (! is_numeric($beat)) {
            // First deploy of the heartbeat, or a cache flush: not proof of a dead cron yet.
            $this->caution('Scheduler', 'no heartbeat recorded yet — re-run app:status in two minutes');

            return;
        }

        $age = time() - (int) $beat;
        $age <= 180
            ? $this->pass('Scheduler', "last tick {$age}s ago")
            : $this->failure('Scheduler', "last tick {$age}s ago — is the schedule:run cron job installed?");
    }

    /** @param  list<string>  $cmd */
    private function shell(array $cmd): ?string
    {
        try {
            $process = new Process($cmd, base_path(), null, null, 10.0);
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function short(Throwable $e): string
    {
        return Str::limit($e->getMessage(), 120);
    }

    private function pass(string $check, string $detail): void
    {
        $this->rows[] = [$check, 'OK', $detail];
    }

    private function caution(string $check, string $detail): void
    {
        $this->rows[] = [$check, 'WARN', $detail];
    }

    private function failure(string $check, string $detail): void
    {
        $this->rows[] = [$check, 'FAIL', $detail];
    }
}
