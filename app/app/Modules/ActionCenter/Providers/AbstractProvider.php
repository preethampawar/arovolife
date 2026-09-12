<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Providers;

use App\Modules\ActionCenter\Contracts\ActionProvider;
use App\Modules\ActionCenter\Support\Severity;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The shared half of every provider (plan §9, slice A1): snooze exclusion,
 * SLA-based severity promotion and the age helpers.
 *
 * A concrete provider supplies the eight descriptive methods plus `count()`
 * and `items()`; everything below is inherited and should not be re-derived.
 */
abstract class AbstractProvider implements ActionProvider
{
    /** Most types are not statutory, so they may be snoozed. */
    public function statutory(): bool
    {
        return false;
    }

    /** Null = a backlog with no clock, so nothing is ever promoted. */
    public function slaHours(): ?int
    {
        return null;
    }

    /** Overridden by providers whose module sits behind a feature flag. */
    public function enabled(): bool
    {
        return true;
    }

    public function targetRoute(): ?string
    {
        return null;
    }

    /**
     * The `subject_type` this provider snoozes against — short and stable,
     * because it is stored in `action_center_snoozes` and must survive a class
     * being moved. Defaults to the last dot-segment-free part of the key.
     */
    abstract public function subjectType(): string;

    /**
     * Drop rows whose subject is under a live snooze.
     *
     * A `whereNotExists` rather than a `whereNotIn`: the count must stay one
     * indexed query whatever the size of the snooze table, and the subquery
     * rides the (`action_key`, `snoozed_until`) index.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @param  string  $idColumn  fully qualified id column of the subject table
     * @return TBuilder
     */
    protected function excludeSnoozed(Builder $query, string $idColumn): Builder
    {
        $query->whereNotExists(function (Builder $sub) use ($idColumn): void {
            $sub->select(DB::raw('1'))
                ->from('action_center_snoozes')
                ->whereColumn('action_center_snoozes.subject_id', $idColumn)
                ->where('action_center_snoozes.subject_type', $this->subjectType())
                ->where('action_center_snoozes.action_key', $this->key())
                ->where('action_center_snoozes.snoozed_until', '>', now());
        });

        return $query;
    }

    /**
     * The instant an item must have occurred before to breach the SLA — i.e.
     * `now - slaHours`. Null when the type has no clock.
     */
    protected function slaCutoff(): ?CarbonImmutable
    {
        $hours = $this->slaHours();

        return $hours === null ? null : CarbonImmutable::now()->subHours($hours);
    }

    /** When an item that started at `$occurredAt` falls due. Null without a clock. */
    protected function dueAt(?DateTimeInterface $occurredAt): ?CarbonImmutable
    {
        $hours = $this->slaHours();

        if ($hours === null || $occurredAt === null) {
            return null;
        }

        return CarbonImmutable::instance($occurredAt)->addHours($hours);
    }

    /**
     * The type's ceiling, promoted one level for an item already past its due
     * time (plan §10.6). Severity is derived on every read; nothing stores it.
     */
    protected function itemSeverity(?DateTimeInterface $dueAt): string
    {
        if ($dueAt !== null && CarbonImmutable::instance($dueAt)->isPast()) {
            return Severity::promote($this->severity());
        }

        return $this->severity();
    }

    /** Whole hours since `$occurredAt`; 0 for anything in the future. */
    protected function ageHours(?DateTimeInterface $occurredAt): int
    {
        if ($occurredAt === null) {
            return 0;
        }

        $seconds = CarbonImmutable::now()->getTimestamp() - $occurredAt->getTimestamp();

        return $seconds <= 0 ? 0 : intdiv($seconds, 3600);
    }

    /** "3 days" / "5 hours" — the plain age string the UI prints next to a row. */
    protected function ageLabel(?DateTimeInterface $occurredAt): string
    {
        $hours = $this->ageHours($occurredAt);

        if ($hours < 24) {
            return $hours === 1 ? '1 hour' : "{$hours} hours";
        }

        $days = intdiv($hours, 24);

        return $days === 1 ? '1 day' : "{$days} days";
    }
}
