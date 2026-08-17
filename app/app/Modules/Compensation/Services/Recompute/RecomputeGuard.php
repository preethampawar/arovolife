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
 * Two independent locks, both of which must open:
 *   1. Never in production. No override, no flag, no force.
 *   2. config('arovolife.recompute.enabled') — off unless deliberately set.
 *
 * The third lock (an interactive confirmation naming the target database) lives
 * on the command itself, because only the CLI can ask a question. {@see
 * targetDatabase()} is what it prints: the staging database holds real
 * distributor PII, so an operator must see WHICH database they are about to
 * destroy, not merely be asked whether they are sure.
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
            && (bool) config('arovolife.recompute.enabled', false);
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
    }

    /**
     * The contract only guarantees environment(); isProduction() lives on the
     * concrete Application, and this class is bound to the interface.
     */
    private function isProduction(): bool
    {
        return $this->app->environment('production');
    }

    /** The database a recompute would destroy — shown in the confirmation. */
    public function targetDatabase(): string
    {
        $connection = (string) config('database.default');

        return (string) config("database.connections.{$connection}.database");
    }

    /** The connection name, printed alongside the database for clarity. */
    public function targetConnection(): string
    {
        return $this->db->getDefaultConnection();
    }
}
