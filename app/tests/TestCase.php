<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the application for a test, then HARD-GUARD that the database is
     * the isolated in-memory SQLite declared in phpunit.xml.
     *
     * Why this exists: the dev/prod MySQL database name (`arovolife`) is
     * injected as a container OS env var by docker-compose, which OVERRIDES
     * both phpunit.xml and .env.testing. With cached config this silently made
     * the test suite run against the real dev database, and `RefreshDatabase`
     * executed `migrate:fresh` — which WIPED it (this happened twice).
     *
     * This guard runs inside createApplication(), BEFORE any RefreshDatabase
     * trait can touch the connection, and aborts loudly unless the resolved
     * database is an ISOLATED test database — either sqlite `:memory:` or a
     * name ending in `_test` (e.g. `arovolife_test`). The dev DB `arovolife`
     * can therefore never be reached by the test suite.
     *
     * Run tests with the dedicated test DB, e.g.:
     *   docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test \
     *     -e DB_HOST=db arovolife-app php artisan test
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $default = config('database.default');

        // Read the name off the LIVE connection, not out of config. Every
        // connection carries `'url' => env('DB_URL')`, and when DB_URL is set
        // Laravel's ConfigurationUrlParser overrides the database at connect
        // time while config() still returns the stale DB_DATABASE — so a
        // config-only check can read `arovolife_test` while the handle it
        // hands RefreshDatabase is pointed at `arovolife`. That is precisely
        // the wipe this guard exists to prevent.
        $database = (string) $app->make('db')->connection()->getDatabaseName();
        $isolated = $database === ':memory:' || str_ends_with($database, '_test');

        if (! $isolated) {
            throw new \RuntimeException(
                "REFUSING TO RUN TESTS: the test database is '{$database}' (connection '{$default}'). "
                ."Tests must run on an ISOLATED database (':memory:' or a name ending in '_test') — "
                .'never the dev/prod database, which RefreshDatabase would WIPE. Run with '
                .'`-e DB_DATABASE=arovolife_test` (see the test-isolation memory).'
            );
        }

        // Force the queue to run synchronously during tests. phpunit.xml and
        // .env.testing both declare QUEUE_CONNECTION=sync, but the container
        // injects QUEUE_CONNECTION=database as an OS env var which OVERRIDES
        // them (same override that caused the DB-wipe above). Without sync,
        // every `ShouldQueue` mail/notification listener is pushed to the DB
        // queue and never runs inside the test, so `Notification::fake()`
        // assertions for freeze/unfreeze/(de)activate/terminate mails fail.
        config(['queue.default' => 'sync']);

        // The recompute/reset tooling refuses unless the CONNECTED database is
        // named as destroyable. The guard above has just proven this one is an
        // isolated test database, so declaring it here is honest — and it keeps
        // the gate itself under test rather than bypassed.
        config(['arovolife.recompute.allowed_databases' => [$database]]);

        // Restore the intended `testing` environment. phpunit.xml sets
        // APP_ENV=testing, but the container's OS env (APP_ENV=local) wins —
        // even PHPUnit's force="true" does not stick here (see the DB_* entries
        // in phpunit.xml). With env != testing, `runningUnitTests()` is false,
        // so the CSRF middleware (PreventRequestForgery) is NOT auto-skipped
        // and unauthenticated test POSTs fail with HTTP 419. Pinning the `env`
        // binding makes runningUnitTests() true again.
        $app->instance('env', 'testing');
        config(['app.env' => 'testing']);

        // Laravel skips rerouteSymfonyCommandEvents() when runningUnitTests(),
        // so CommandStarting/CommandFinished never fire under PHPUnit and the
        // RecordEngineRun listener — the ONLY writer of `engine_runs` — records
        // nothing. Every engine-run assertion would then be vacuously false
        // while the same code works in production. Re-bridge the events so the
        // run log behaves under test exactly as it does under cron.
        $app->make(Kernel::class)->rerouteSymfonyCommandEvents();

        return $app;
    }
}
