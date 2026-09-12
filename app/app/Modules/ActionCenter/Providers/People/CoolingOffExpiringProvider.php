<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\People;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * An active distributor whose 30-day cooling-off window closes within 7 days
 * (plan §4, People) — the same `users.status = active` and
 * `cooling_off_end_at` window `AdminDashboardController` counts as
 * "Cooling-Off Expiring".
 */
final class CoolingOffExpiringProvider extends AbstractProvider
{
    private const WINDOW_DAYS = 7;

    public function key(): string
    {
        return 'distributors.cooling_off_expiring';
    }

    public function group(): string
    {
        return ActionGroup::PEOPLE;
    }

    public function label(): string
    {
        return 'Cooling-off expiring soon';
    }

    public function description(): string
    {
        return 'Active distributors whose 30-day cooling-off window closes within 7 days. No action is required unless they cancel.';
    }

    public function permission(): string
    {
        return 'compliance.discipline';
    }

    public function severity(): string
    {
        return Severity::INFO;
    }

    public function slaHours(): int
    {
        return self::WINDOW_DAYS * 24;
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
        return $this->baseQuery()->toBase()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderBy('distributors.cooling_off_end_at')
            ->limit($limit)
            ->get(['distributors.id', 'distributors.adn', 'distributors.cooling_off_end_at'])
            ->map(function (Distributor $distributor): ActionItem {
                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $distributor->id,
                    title: (string) $distributor->adn,
                    subtitle: 'Cooling-off ends '.Carbon::instance($distributor->cooling_off_end_at)->diffForHumans(),
                    occurredAt: Carbon::now(),
                    dueAt: $distributor->cooling_off_end_at,
                    severity: $this->severity(),
                    url: route($this->targetRoute(), ['id' => $distributor->id]),
                    meta: ['adn' => (string) $distributor->adn],
                );
            })
            ->values();
    }

    /** @return Builder<Distributor> */
    private function baseQuery(): Builder
    {
        $query = Distributor::query()
            ->join('users', 'users.id', '=', 'distributors.user_id')
            ->where('users.status', 'active')
            ->where('distributors.cooling_off_end_at', '>', now())
            ->where('distributors.cooling_off_end_at', '<=', now()->addDays(self::WINDOW_DAYS));

        $this->excludeSnoozed($query->getQuery(), 'distributors.id');

        return $query;
    }
}
