<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Compensation\Services\Recompute\RecomputeGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * The gate on the scale harness: may this process write ten lakh synthetic
 * distributors into the database it is connected to?
 *
 * Modelled on {@see RecomputeGuard}
 * and stricter in one respect. The recompute is destructive but legitimate on a
 * named test database; the scale seeder is not legitimate on ANY database
 * anybody uses. Its population is fiction — ADNs that belong to nobody, orders
 * nobody placed — and mixed into real rows it is unrecoverable by any means
 * short of a restore.
 *
 * Four locks, all of which must open:
 *   1. Never in production.
 *   2. `arovolife` and `arovolife_test` are refused outright. The first is a
 *      developer's working data; the second is shared with every suite run and
 *      is rebuilt by migrations, not by judgement.
 *   3. `config('arovolife.scale.database')` must be set — empty means the
 *      harness is unavailable, which is the default.
 *   4. The CONNECTED database must be exactly that name.
 *
 * Lock 2 is not redundant with 4. An operator setting COMP_SCALE_DATABASE by
 * hand at 2am is precisely who this is for.
 */
final class ScaleEnvironment
{
    /**
     * Databases the harness refuses whatever it is configured with.
     *
     * `ahdhesuhty` is STAGING — the Cloudways-local database it moved to on
     * 29 August 2026. It does not look like a staging database, which is
     * exactly why it is named here: `arovolife_staging` (which this list used
     * to carry) has not existed since, so the list was blocking a name and
     * missing the data. Staging holds real distributor PII, real cooling-off
     * windows and a real TDS trail.
     *
     * @var list<string>
     */
    private const NEVER = ['arovolife', 'arovolife_test', 'arovolife_staging', 'ahdhesuhty'];

    public function __construct(
        private readonly Application $app,
        private readonly DatabaseManager $db,
    ) {}

    public function isPermitted(): bool
    {
        return $this->refusalReason() === null;
    }

    /** @throws RuntimeException */
    public function ensurePermitted(): void
    {
        $reason = $this->refusalReason();

        if ($reason !== null) {
            throw new RuntimeException($reason);
        }
    }

    public function targetDatabase(): string
    {
        return (string) $this->db->connection()->getDatabaseName();
    }

    /**
     * May this process EMPTY the synthetic tables?
     *
     * A name blocklist answers "is this the database I was told to use". It
     * cannot answer "is this the database I think it is" — a rename, a copied
     * `.env`, a restored dump and the list is describing something that is no
     * longer there. So the truncate asks the DATA: every distributor in this
     * database must be one this harness wrote, and the harness writes ADNs
     * prefixed `SC`. An empty table passes; one real distributor does not.
     *
     * @return string|null The reason to refuse, or null when it may.
     */
    public function truncateRefusalReason(): ?string
    {
        $reason = $this->refusalReason();

        if ($reason !== null) {
            return $reason;
        }

        $foreign = $this->db->connection()
            ->table('distributors')
            ->where(function ($query): void {
                $query->whereNull('adn')->orWhere('adn', 'not like', 'SC%');
            })
            ->count();

        if ($foreign > 0) {
            return sprintf(
                'Refusing to empty [%s]: it holds %s distributor(s) this harness did not write. Every synthetic '
                .'ADN starts with SC, and these do not — so this is somebody\'s real data, whatever the database '
                .'is called.',
                $this->targetDatabase(),
                number_format($foreign),
            );
        }

        return null;
    }

    /** The operator-facing reason this may not run here, or null when it may. */
    public function refusalReason(): ?string
    {
        if ($this->app->environment('production')) {
            return 'The scale harness never runs in production.';
        }

        $connected = $this->targetDatabase();

        if (in_array($connected, self::NEVER, true)) {
            return sprintf(
                'Refusing to write a synthetic population into [%s]. That database holds data somebody depends on; '
                .'the harness needs one of its own.',
                $connected,
            );
        }

        $configured = (string) config('arovolife.scale.database', '');

        if ($configured === '') {
            return 'No scale database is configured. Set COMP_SCALE_DATABASE to a database that exists only for '
                .'this harness — the default is empty precisely so it cannot run by accident.';
        }

        if ($connected !== $configured) {
            return sprintf(
                'Connected to [%s] but the scale database is [%s]. Run this with an explicit override, e.g. '
                .'docker exec -e DB_DATABASE=%s arovolife-app php artisan …',
                $connected,
                $configured,
                $configured,
            );
        }

        return null;
    }
}
