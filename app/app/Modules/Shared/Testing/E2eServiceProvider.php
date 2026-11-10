<?php

declare(strict_types=1);

namespace App\Modules\Shared\Testing;

use App\Modules\Shared\SharedServiceProvider;
use App\Modules\Shared\Testing\Console\E2eClockCommand;
use App\Modules\Shared\Testing\Console\E2eEnginesCommand;
use App\Modules\Shared\Testing\Console\E2eFeatureCommand;
use App\Modules\Shared\Testing\Console\E2eResetCommand;
use App\Modules\Shared\Testing\Console\E2eScenarioCommand;
use App\Modules\Shared\Testing\Console\E2eSnapshotCommand;
use App\Modules\Shared\Testing\Console\E2eWhoamiCommand;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Monolog\Handler\NullHandler;
use PDO;

/**
 * Registered only by {@see SharedServiceProvider} and only
 * when `ARV_E2E=1`. Turns the e2e container into a sealed copy of the app:
 * its own database (guarded), its own disks, its own log, no outbound HTTP,
 * no live gateways, and a frozen clock. Nothing here runs on dev, staging or
 * production — the flag is never set there, and a set flag on a non-`_e2e`
 * database refuses to boot.
 */
final class E2eServiceProvider extends ServiceProvider
{
    /** Disks that the web server serves under /storage on the e2e stack. */
    private const PUBLIC_DISKS = ['public' => '', 'catalog' => '/catalog'];

    public function register(): void
    {
        $database = E2eMode::assertSafe($this->app);

        $this->remapDisks();
        $this->remapLogs();

        config([
            // The recompute tooling may only ever target this database here,
            // whatever the shared .env allows for the dev database.
            'arovolife.recompute.allowed_databases' => [$database],

            // No live gateway can be reached even if a setting is flipped on.
            'arovolife.payments.razorpay.key_id' => '',
            'arovolife.payments.razorpay.key_secret' => '',
            'arovolife.payments.razorpay.webhook_secret' => '',
            'arovolife.payments.razorpay_payouts.key_id' => '',
            'arovolife.payments.razorpay_payouts.key_secret' => '',
            'arovolife.payments.razorpay_payouts.webhook_secret' => '',
            'arovolife.fulfilment.shiprocket.email' => '',
            'arovolife.fulfilment.shiprocket.password' => '',
        ]);

        E2eMode::freeze(E2eMode::readClock());
        $this->syncDatabaseClock();
    }

    /**
     * Make MySQL's clock follow the app's. Freezing Carbon alone leaves the
     * server on the real date: NOW()/CURDATE() and every CURRENT_TIMESTAMP
     * column default (ledger_entries, wallet_ledger_entries, orders,
     * group_bv_credits, distributors… 66 tables) would still read today, so a
     * row inserted without an explicit timestamp would drift a money fixture.
     * `SET TIMESTAMP` moves all of those for the session. It is re-sent before
     * a query only when the app clock has moved (an engine replay steps it),
     * and cleared back to the real time when no clock is frozen.
     */
    private function syncDatabaseClock(): void
    {
        $sent = [];

        $this->app->make('db')->connection()->beforeExecuting(
            static function (string $query, array $bindings, Connection $connection) use (&$sent): void {
                // After db:wipe / a lost connection the handle is gone until
                // run() reconnects — which happens after this callback, so
                // reconnect here or the first query would miss the clock.
                if ($connection->getRawPdo() === null) {
                    $connection->reconnect();
                }

                $pdo = $connection->getPdo();

                if (! $pdo instanceof PDO) {
                    return;
                }

                $value = Carbon::hasTestNow()
                    ? sprintf('%.6F', Carbon::now()->getPreciseTimestamp(6) / 1_000_000)
                    : 'DEFAULT';
                $key = spl_object_id($pdo);

                if (($sent[$key] ?? null) !== $value) {
                    $pdo->exec('SET TIMESTAMP = '.$value);
                    $sent[$key] = $value;
                }
            },
        );
    }

    public function boot(): void
    {
        // Any server-side call through the HTTP client throws instead of
        // leaving the machine. The container also sits on an internal-only
        // Docker network, so a client that bypasses the facade fails too.
        Http::preventStrayRequests();

        if ($this->app->runningInConsole()) {
            $this->commands([
                E2eWhoamiCommand::class,
                E2eClockCommand::class,
                E2eSnapshotCommand::class,
                E2eResetCommand::class,
                E2eScenarioCommand::class,
                E2eEnginesCommand::class,
                E2eFeatureCommand::class,
            ]);
        }
    }

    private function remapDisks(): void
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        foreach ((array) config('filesystems.disks') as $name => $disk) {
            $root = E2eMode::root("disks/{$name}");
            $remapped = [
                'driver' => 'local',
                'root' => $root,
                'throw' => $disk['throw'] ?? false,
                'report' => false,
            ];

            // Kept (inert on a local driver) so the boot-time S3 check in
            // AppServiceProvider still sees its keys; the values are the
            // container's dummy, unroutable ones.
            foreach (['key', 'secret', 'region', 'bucket'] as $credential) {
                if (isset($disk[$credential])) {
                    $remapped[$credential] = $disk[$credential];
                }
            }

            if (array_key_exists($name, self::PUBLIC_DISKS)) {
                $remapped['root'] = E2eMode::root('disks/public'.self::PUBLIC_DISKS[$name]);
                $remapped['url'] = $appUrl.'/storage'.self::PUBLIC_DISKS[$name];
                $remapped['visibility'] = 'public';
            }

            if (isset($disk['serve'])) {
                $remapped['serve'] = $disk['serve'];
            }

            config(["filesystems.disks.{$name}" => $remapped]);
        }
    }

    private function remapLogs(): void
    {
        foreach ((array) config('logging.channels') as $name => $channel) {
            $driver = $channel['driver'] ?? null;

            if (in_array($driver, ['single', 'daily'], true)) {
                config(["logging.channels.{$name}.path" => storage_path('logs/e2e/'.$name.'.log')]);
            } elseif ($driver !== 'stack') {
                // Slack, syslog, papertrail, stderr…: nothing leaves the stack.
                config(["logging.channels.{$name}" => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
            }
        }

        config([
            'logging.channels.e2e' => [
                'driver' => 'single',
                'path' => storage_path('logs/e2e/laravel.log'),
                'level' => 'debug',
            ],
            'logging.default' => 'e2e',
            'logging.viewer_directory' => storage_path('logs/e2e'),
        ]);
    }
}
