<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Platform;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Compensation\Services\EngineHealthService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A compensation engine run that failed, never ran when scheduled, or is
 * stuck (plan §4, Platform) — read straight from `EngineHealthService`, the
 * same report the daily health digest builds from, rather than re-querying
 * `engine_runs`.
 *
 * Snooze exclusion is intentionally NOT applied: the report's items have no
 * single subject table row to check against, and a stuck/missing item has no
 * id at all until it is stamped. A future slice can widen `AbstractProvider`
 * if engine items ever need to be individually snoozed.
 */
final class EngineRunsFailedProvider extends AbstractProvider
{
    public function __construct(private readonly EngineHealthService $health) {}

    public function key(): string
    {
        return 'platform.engine_runs_failed';
    }

    public function group(): string
    {
        return ActionGroup::PLATFORM;
    }

    public function label(): string
    {
        return 'Compensation engine runs need attention';
    }

    public function description(): string
    {
        return 'A compensation engine run failed, never ran, or is stuck. Fix it from the engine runs console.';
    }

    public function permission(): string
    {
        return 'finance.record';
    }

    public function severity(): string
    {
        return Severity::CRITICAL;
    }

    public function subjectType(): string
    {
        return 'engine_run';
    }

    public function targetRoute(): string
    {
        return 'admin.compensation.engine-runs.index';
    }

    public function count(): int
    {
        return $this->health->report(Carbon::now())->total();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        $report = $this->health->report(Carbon::now());
        $items = collect();
        $id = 0;

        foreach ($report->failures as $failure) {
            $items->push(new ActionItem(
                subjectType: $this->subjectType(),
                subjectId: ++$id,
                title: $failure['engine'],
                subtitle: 'Failed for '.$failure['period'].': '.$failure['error'],
                occurredAt: Carbon::createFromFormat('d M Y H:i', $failure['started_at']) ?: now(),
                dueAt: null,
                severity: $this->severity(),
                url: route($this->targetRoute()),
                meta: ['key' => $failure['key'], 'period' => $failure['period_value']],
            ));
        }

        foreach ($report->missing as $missing) {
            $items->push(new ActionItem(
                subjectType: $this->subjectType(),
                subjectId: ++$id,
                title: $missing['engine'],
                subtitle: 'Never ran for '.$missing['period'].' (due '.$missing['due_at'].')',
                occurredAt: now(),
                dueAt: null,
                severity: $this->severity(),
                url: route($this->targetRoute()),
                meta: ['key' => $missing['key'], 'period' => $missing['period_value']],
            ));
        }

        foreach ($report->stuck as $stuck) {
            $items->push(new ActionItem(
                subjectType: $this->subjectType(),
                subjectId: ++$id,
                title: $stuck['engine'],
                subtitle: 'Stuck running since '.$stuck['started_at'],
                occurredAt: Carbon::createFromFormat('d M Y H:i', $stuck['started_at']) ?: now(),
                dueAt: null,
                severity: $this->severity(),
                url: route($this->targetRoute()),
                meta: ['key' => $stuck['key'], 'period' => $stuck['period_value']],
            ));
        }

        return $items->sortBy('occurredAt')->take($limit)->values();
    }
}
