<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Post-git-deploy orchestrator.
 *
 * One artisan command that runs every step the staging / production server
 * needs after Cloudways pulls the repo: composer install, vite build,
 * migrations (under maintenance mode if requested), the idempotent
 * ProductionSeeder, cache rebuilds, queue restart, and an optional HTTP
 * smoke test.
 *
 * Wired into the Cloudways Deploy Hook as:
 *
 *     cd /home/master/applications/ahdhesuhty/public_html/app \
 *       && php artisan app:deploy --maintenance \
 *           --health-url=https://phplaravel-1611779-6390605.cloudwaysapps.com/
 *
 * Refuses to run unless APP_ENV is staging or production, so a stray
 * invocation against a developer laptop can't blow away the local DB.
 *
 * Every step is idempotent; safe to re-run on the same commit.
 */
final class DeployCommand extends Command
{
    protected $signature = 'app:deploy
        {--skip-composer  : Skip composer install --no-dev}
        {--skip-npm       : Skip npm ci + npm run build}
        {--skip-migrate   : Skip php artisan migrate --force}
        {--skip-seed      : Skip php artisan db:seed ProductionSeeder}
        {--skip-cache     : Skip config/route/view/event cache rebuild}
        {--skip-queue     : Skip php artisan queue:restart}
        {--maintenance    : Wrap migrations in php artisan down/up}
        {--health-url=    : URL to GET as the final smoke test (200/30x = pass)}';

    protected $description = 'Run the post-git-deploy pipeline (composer, npm, migrate, seed, cache, queue, smoke test).';

    /** Steps that should hard-fail the whole run when they error. */
    private bool $failed = false;

    public function handle(): int
    {
        $this->logBoth('▶ deploy started');

        if (! $this->preflight()) {
            return self::FAILURE;
        }

        if (! $this->option('skip-composer')) {
            $this->runProcess('composer install', ['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--prefer-dist']);
        } else {
            $this->logBoth('  ↷ composer install skipped');
        }

        if (! $this->option('skip-npm')) {
            $this->runNpm();
        } else {
            $this->logBoth('  ↷ npm build skipped');
        }

        $this->stepSoft('storage:link', fn () => Artisan::call('storage:link'));

        $maintenance = (bool) $this->option('maintenance') && ! $this->option('skip-migrate');

        try {
            if ($maintenance) {
                $this->logBoth('  ⏸ entering maintenance mode');
                Artisan::call('down', ['--render' => 'errors::503', '--retry' => 15]);
            }

            if (! $this->option('skip-migrate')) {
                $this->stepHard('migrate', function (): void {
                    $this->logBoth('  pretend run:');
                    Artisan::call('migrate', ['--pretend' => true]);
                    $this->logBoth(Artisan::output());
                    Artisan::call('migrate', ['--force' => true]);
                    $this->logBoth(Artisan::output());
                });
            } else {
                $this->logBoth('  ↷ migrate skipped');
            }

            if (! $this->option('skip-seed') && ! $this->failed) {
                $this->stepHard('db:seed ProductionSeeder', function (): void {
                    Artisan::call('db:seed', ['--class' => 'ProductionSeeder', '--force' => true]);
                    $this->logBoth(Artisan::output());
                });
            } elseif ($this->option('skip-seed')) {
                $this->logBoth('  ↷ ProductionSeeder skipped');
            }
        } finally {
            if ($maintenance) {
                $this->logBoth('  ⏵ leaving maintenance mode');
                Artisan::call('up');
            }
        }

        if (! $this->option('skip-cache') && ! $this->failed) {
            $this->stepHard('config:cache', fn () => Artisan::call('config:cache'));
            $this->stepHard('route:cache', fn () => Artisan::call('route:cache'));
            $this->stepHard('view:cache', fn () => Artisan::call('view:cache'));
            $this->stepSoft('event:cache', fn () => Artisan::call('event:cache'));
        } elseif ($this->option('skip-cache')) {
            $this->logBoth('  ↷ cache rebuild skipped');
        }

        if (! $this->option('skip-queue')) {
            $this->stepSoft('queue:restart', fn () => Artisan::call('queue:restart'));
        } else {
            $this->logBoth('  ↷ queue:restart skipped');
        }

        $healthUrl = (string) $this->option('health-url');
        if ($healthUrl !== '' && ! $this->failed) {
            $this->stepHard('smoke test', function () use ($healthUrl): void {
                $resp = Http::timeout(15)->get($healthUrl);
                $this->logBoth("  GET {$healthUrl} → HTTP {$resp->status()}");
                if (! in_array($resp->status(), [200, 301, 302], true)) {
                    throw new \RuntimeException("smoke test failed: HTTP {$resp->status()}");
                }
            });
        }

        if ($this->failed) {
            $this->logBoth('✘ deploy finished with errors');

            return self::FAILURE;
        }

        $this->logBoth('✓ deploy complete');

        return self::SUCCESS;
    }

    private function preflight(): bool
    {
        $env = (string) config('app.env');
        if (! in_array($env, ['staging', 'production'], true)) {
            $this->error("Refusing to deploy: APP_ENV='{$env}' (must be staging or production).");

            return false;
        }
        $this->logBoth("▶ pre-flight: APP_ENV={$env}, OK");

        return true;
    }

    /** Hard step — abort the rest of the run if it throws. */
    private function stepHard(string $label, callable $fn): void
    {
        if ($this->failed) {
            return;
        }
        $this->logBoth("▶ {$label}");
        try {
            $fn();
            $this->logBoth("  ✓ {$label}");
        } catch (Throwable $e) {
            $this->failed = true;
            $this->logBoth("  ✘ {$label} — {$e->getMessage()}");
        }
    }

    /** Soft step — log a warning but keep going. */
    private function stepSoft(string $label, callable $fn): void
    {
        $this->logBoth("▶ {$label}");
        try {
            $fn();
            $this->logBoth("  ✓ {$label}");
        } catch (Throwable $e) {
            $this->logBoth("  ⚠ {$label} — {$e->getMessage()} (non-fatal)");
        }
    }

    /**
     * Run a shell command (composer, npm) and stream output to the console + log.
     *
     * @param  list<string>  $cmd
     */
    private function runProcess(string $label, array $cmd): void
    {
        if ($this->failed) {
            return;
        }
        $this->logBoth("▶ {$label}");

        $process = new Process($cmd, base_path(), null, null, 600.0);
        try {
            $process->mustRun(function (string $type, string $buffer): void {
                $this->logBoth(rtrim($buffer));
            });
            $this->logBoth("  ✓ {$label}");
        } catch (Throwable $e) {
            $this->failed = true;
            $this->logBoth("  ✘ {$label} — {$e->getMessage()}");
        }
    }

    private function runNpm(): void
    {
        if (! file_exists(base_path('package.json'))) {
            $this->logBoth('  ↷ no package.json — skipping npm');

            return;
        }

        $cmd = file_exists(base_path('package-lock.json'))
            ? ['npm', 'ci', '--silent']
            : ['npm', 'install', '--silent'];
        $this->runProcess(implode(' ', $cmd), $cmd);

        if (! $this->failed) {
            $this->runProcess('npm run build', ['npm', 'run', 'build']);
        }
    }

    /** Print to console + append to storage/logs/deploy.log with an ISO-8601 timestamp. */
    private function logBoth(string $line): void
    {
        $stamp = now()->utc()->toIso8601String();
        $this->line($line);
        try {
            Log::build([
                'driver' => 'single',
                'path' => storage_path('logs/deploy.log'),
                'level' => 'info',
            ])->info("[{$stamp}] {$line}");
        } catch (Throwable) {
            // Logger failure must never abort the deploy itself.
        }
    }
}
