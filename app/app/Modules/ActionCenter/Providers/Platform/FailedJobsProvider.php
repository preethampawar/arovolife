<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers\Platform;

use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rows in the queue's `failed_jobs` table (plan §4, Platform). There is no
 * admin screen for this — `targetRoute()` is null and the row is fixed with
 * `php artisan queue:retry` / `queue:forget` from a terminal, which is why
 * this type is gated on `audit.read` rather than a screen permission.
 */
final class FailedJobsProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'platform.failed_jobs';
    }

    public function group(): string
    {
        return ActionGroup::PLATFORM;
    }

    public function label(): string
    {
        return 'Failed queue jobs';
    }

    public function description(): string
    {
        return 'Jobs the queue could not complete after all retries. Inspect and retry them from the server (queue:retry / queue:forget).';
    }

    public function permission(): string
    {
        return 'audit.read';
    }

    public function severity(): string
    {
        return Severity::WARNING;
    }

    public function subjectType(): string
    {
        return 'failed_job';
    }

    public function targetRoute(): ?string
    {
        return null;
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }

    /** @return Collection<int, ActionItem> */
    public function items(int $limit = 50): Collection
    {
        return $this->baseQuery()
            ->orderBy('failed_jobs.failed_at')
            ->limit($limit)
            ->get(['failed_jobs.id', 'failed_jobs.queue', 'failed_jobs.payload', 'failed_jobs.failed_at'])
            ->map(function (object $row): ActionItem {
                $failedAt = Carbon::parse($row->failed_at);
                $class = $this->jobClass((string) $row->payload);

                return new ActionItem(
                    subjectType: $this->subjectType(),
                    subjectId: (int) $row->id,
                    title: $class,
                    subtitle: 'Failed on the "'.$row->queue.'" queue '.$this->ageLabel($failedAt).' ago',
                    occurredAt: $failedAt,
                    dueAt: null,
                    severity: $this->severity(),
                    url: null,
                    meta: ['queue' => (string) $row->queue, 'class' => $class],
                );
            })
            ->values();
    }

    private function jobClass(string $payload): string
    {
        /** @var array{displayName?: string}|null $decoded */
        $decoded = json_decode($payload, true);

        return $decoded['displayName'] ?? 'Unknown job';
    }

    private function baseQuery(): QueryBuilder
    {
        $query = DB::table('failed_jobs');

        $this->excludeSnoozed($query, 'failed_jobs.id');

        return $query;
    }
}
