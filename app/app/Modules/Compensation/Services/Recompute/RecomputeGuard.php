<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Recompute;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;

/**
 * The single place that answers "is a full compensation recompute permitted
 * here?". The artisan command, the admin controller and the Blade view all ask
 * this class — none of them re-implements the environment or config check, so
 * there is exactly one way for the answer to be yes.
 *
 * Three independent locks, all of which must open:
 *   1. Never in production. No override, no flag, no force.
 *   2. config('arovolife.recompute.enabled') — off unless deliberately set.
 *   3. The CONNECTED DATABASE must be named in
 *      config('arovolife.recompute.allowed_databases').
 *
 * Lock 3 exists because locks 1 and 2 both describe the BUILD, not the data.
 * APP_ENV is a label an operator sets; it cannot tell you that the database
 * behind it holds real distributor PII, real cooling-off windows, real
 * invoices and a real TDS trail — and staging does. Naming the permitted
 * databases makes the gate answerable from the data rather than the label, so
 * a correctly-flagged build pointed at the wrong database still refuses.
 *
 * The fourth lock (an interactive confirmation naming the target database)
 * lives on the command itself, because only the CLI can ask a question. {@see
 * targetDatabase()} is what it prints: an operator must see WHICH database
 * they are about to destroy, not merely be asked whether they are sure.
 */
final class RecomputeGuard
{
    public function __construct(
        private readonly Application $app,
        private readonly DatabaseManager $db,
    ) {}

    /** True when a recompute may run here. Safe to call from a Blade view. */
    public function isPermitted(): bool
    {
        return ! $this->isProduction()
            && (bool) config('arovolife.recompute.enabled', false)
            && $this->isAllowedDatabase();
    }

    /**
     * Throw with the operator-facing reason, or return cleanly.
     *
     * @throws RecomputeNotPermitted
     */
    public function ensurePermitted(): void
    {
        if ($this->isProduction()) {
            throw RecomputeNotPermitted::inProduction();
        }

        if (! (bool) config('arovolife.recompute.enabled', false)) {
            throw RecomputeNotPermitted::notEnabled();
        }

        if (! $this->isAllowedDatabase()) {
            throw RecomputeNotPermitted::databaseNotAllowed(
                $this->targetDatabase(),
                $this->allowedDatabases(),
            );
        }
    }

    /**
     * Is the database we are actually connected to one an operator has
     * declared destroyable? An empty allow-list means no database is.
     */
    private function isAllowedDatabase(): bool
    {
        return in_array($this->targetDatabase(), $this->allowedDatabases(), true);
    }

    /** @return list<string> */
    private function allowedDatabases(): array
    {
        /** @var list<string> $allowed */
        $allowed = (array) config('arovolife.recompute.allowed_databases', []);

        return array_values($allowed);
    }

    /**
     * The contract only guarantees environment(); isProduction() lives on the
     * concrete Application, and this class is bound to the interface.
     */
    private function isProduction(): bool
    {
        return $this->app->environment('production');
    }

    /**
     * The database a recompute would destroy — shown in the confirmation, and
     * the value lock 3 tests.
     *
     * Read from the live connection, NOT from config. Every connection in
     * config/database.php carries `'url' => env('DB_URL')`, and when DB_URL is
     * set Laravel's ConfigurationUrlParser overrides the database at connect
     * time while `config(...)` still returns the stale DB_DATABASE. A stale
     * DB_DATABASE naming an allow-listed database, with DB_URL pointing
     * somewhere else, would defeat lock 3 and lock 4 together: the operator
     * types the name the page shows them and a different database is destroyed.
     */
    public function targetDatabase(): string
    {
        return $this->db->connection()->getDatabaseName();
    }

    /** The connection name, printed alongside the database for clarity. */
    public function targetConnection(): string
    {
        return $this->db->getDefaultConnection();
    }
}
