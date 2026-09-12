<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\People;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A frozen distributor with no decision (unfreeze or termination) for 14
 * days (plan §4, People).
 *
 * There is no existing worklist for "how long has this account been
 * blocked" — `AdminDashboardController` counts `frozen_users` but not their
 * age. `AdminDistributorController::freeze()` is the only place a freeze is
 * recorded, always as an `admin.distributor.frozen` audit row against
 * `subject_type = 'distributor'`; since the account is still frozen, no
 * later `admin.distributor.unfrozen` row exists for it, so the freeze row's
 * own age is the age of the open decision. This condition is therefore
 * defined here rather than copied from a precedent.
 */
final class FrozenStaleProvider extends AbstractProvider
{
    private const STALE_DAYS = 14;

    public function key(): string
    {
        return 'distributors.frozen_stale';
    }

    public function group(): string
    {
        return ActionGroup::PEOPLE;
    }

    public function label(): string
    {
        return 'Frozen accounts with no decision';
    }

    public function description(): string
    {
        return 'Distributors blocked for 14 days or more with no unfreeze or termination decision. Decide from the distributor record.';
    }

    public function permission(): string
    {
        return 'compliance.discipline';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function slaHours(): int
    {
        return self::STALE_DAYS * 24;
    }

    public function subjectType(): string
    {
        return 'distributor';
    }

    public function targetRoute(): string
    {
        return 'admin.distributors.show';
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderBy('frozen_at')
            ->limit($limit)
            ->get()
            ->map(function (object $row): ActionItem {
                $frozenAt = Carbon::parse($row->frozen_at);
                $dueAt = $this->dueAt($frozenAt);

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $row->id,
                    title: (string) $row->adn,
                    subtitle: 'Blocked '.$this->ageLabel($frozenAt).' ago, no decision yet',
                    occurredAt: $frozenAt,
                    dueAt: $dueAt,
                    severity: $this->itemSeverity($dueAt),
                    url: route($this->targetRoute(), ['id' => $row->id]),
                    meta: ['adn' => (string) $row->adn],
                );
            })
            ->values();
    }

    /**
     * The distributor's own row joined to the `created_at` of its most
     * recent `admin.distributor.frozen` audit entry — a plain query-builder
     * result (not an Eloquent hydration) so `count()` stays one indexed
     * query with no model overhead.
     */
    private function baseQuery(): QueryBuilder
    {
        $latestFreeze = DB::table('audit_log')
            ->selectRaw('subject_id, MAX(created_at) as frozen_at')
            ->where('action', 'admin.distributor.frozen')
            ->where('subject_type', 'distributor')
            ->groupBy('subject_id');

        $query = DB::table('distributors')
            ->join('users', 'users.id', '=', 'distributors.user_id')
            ->joinSub($latestFreeze, 'latest_freeze', 'latest_freeze.subject_id', '=', 'distributors.id')
            ->where('users.status', 'frozen')
            ->where('latest_freeze.frozen_at', '<=', $this->slaCutoff())
            ->select(['distributors.id', 'distributors.adn', 'latest_freeze.frozen_at']);

        $this->excludeSnoozed($query, 'distributors.id');

        return $query;
    }
}
