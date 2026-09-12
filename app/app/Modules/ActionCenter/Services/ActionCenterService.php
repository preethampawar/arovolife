<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Services;

use App\Modules\ActionCenter\Contracts\ActionProvider;
use App\Modules\ActionCenter\Exceptions\CannotSnoozeStatutoryAction;
use App\Modules\ActionCenter\Exceptions\InvalidSnoozeWindow;
use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\ActionItem;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The read side of the Action Center plus its one write (plan §6).
 *
 * `summary()` is the only thing cached — 60 seconds, per viewer, because the
 * set of providers a viewer may see is itself permission-dependent. `items()`
 * is never cached: a manager opening a type page is asking what is true now.
 */
final class ActionCenterService
{
    public const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly ActionCenterRegistry $registry,
        private readonly ActionCenterSettings $settings,
    ) {}

    /**
     * Per group, the provider rows this user may see: count, derived severity
     * and the age of the oldest outstanding item. Groups with nothing in them
     * are dropped — silence means nothing to do (plan §10.5).
     *
     * @return Collection<string, array<int, array<string, mixed>>>
     */
    public function summary(User $user): Collection
    {
        /** @var array<string, array<int, array<string, mixed>>> $cached */
        $cached = Cache::remember(
            self::cacheKey($user),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->buildSummary($user),
        );

        return Collection::make($cached);
    }

    /** The viewer's critical count, for the sidebar badge. Reads the same cache. */
    public function criticalCount(User $user): int
    {
        return (int) $this->summary($user)
            ->flatten(1)
            ->where('severity', Severity::CRITICAL)
            ->sum(fn (array $row): int => (int) $row['count']);
    }

    /**
     * A type's items, oldest first. Never cached.
     *
     * @return Collection<int, ActionItem>
     */
    public function items(User $user, string $key, int $limit = 50): Collection
    {
        return $this->registry->findFor($user, $key)->items($limit);
    }

    /**
     * The `$limit` oldest critical items across every group this user may
     * see (plan §7, dashboard card). Never cached, like `items()` — a
     * dashboard is asking what is true now, and this is a small, bounded
     * fan-out (one `items()` call per visible provider, itself already
     * capped).
     *
     * @return Collection<int, ActionItem>
     */
    public function oldestCriticalItems(User $user, int $limit = 5): Collection
    {
        $items = Collection::make();

        foreach ($this->registry->for($user) as $provider) {
            $items = $items->concat(
                $provider->items($limit)->filter(fn (ActionItem $item): bool => $item->severity === Severity::CRITICAL)
            );
        }

        return $items
            ->sortBy(fn (ActionItem $item): int => $item->occurredAt->getTimestamp())
            ->take($limit)
            ->values();
    }

    /**
     * Defer one subject of one action type.
     *
     * Statutory types refuse (plan §5) and the window is capped by
     * `action_center.max_snooze_days`. The row and its audit entry are written
     * together: a deferral nobody can attribute is not a deferral.
     */
    public function snooze(
        User $actor,
        string $key,
        string $subjectType,
        int $subjectId,
        int $days,
        string $reason,
    ): ActionCenterSnooze {
        $provider = $this->registry->find($key);

        if ($provider->statutory()) {
            throw CannotSnoozeStatutoryAction::for($key);
        }

        $max = $this->settings->maxSnoozeDays();

        if ($days < 1 || $days > $max) {
            throw InvalidSnoozeWindow::for($days, $max);
        }

        $until = now()->addDays($days);

        $snooze = DB::transaction(function () use ($actor, $key, $subjectType, $subjectId, $reason, $until): ActionCenterSnooze {
            $snooze = ActionCenterSnooze::query()->updateOrCreate(
                ['action_key' => $key, 'subject_type' => $subjectType, 'subject_id' => $subjectId],
                ['snoozed_until' => $until, 'reason' => $reason, 'actor_user_id' => $actor->id],
            );

            AuditLog::create([
                'actor_id' => $actor->id,
                'action' => 'action_center.snoozed',
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'details' => [
                    'action_key' => $key,
                    'snoozed_until' => $until->toIso8601String(),
                    'reason' => $reason,
                ],
            ]);

            return $snooze;
        });

        $this->forget($actor);

        return $snooze;
    }

    /** Bring a deferred subject back into the queue straight away. */
    public function unsnooze(User $actor, string $key, string $subjectType, int $subjectId): void
    {
        $this->registry->find($key);

        DB::transaction(function () use ($actor, $key, $subjectType, $subjectId): void {
            ActionCenterSnooze::query()
                ->where('action_key', $key)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->delete();

            AuditLog::create([
                'actor_id' => $actor->id,
                'action' => 'action_center.unsnoozed',
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'details' => ['action_key' => $key],
            ]);
        });

        $this->forget($actor);
    }

    public function forget(User $user): void
    {
        Cache::forget(self::cacheKey($user));
    }

    public static function cacheKey(User $user): string
    {
        return "action_center.summary.{$user->id}";
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function buildSummary(User $user): array
    {
        $rows = [];

        foreach ($this->registry->for($user) as $provider) {
            $count = $provider->count();

            if ($count === 0) {
                continue;
            }

            $rows[$provider->group()][] = $this->summaryRow($provider, $count);
        }

        // Catalogue order, criticals first within each group.
        $ordered = [];

        foreach (ActionGroup::all() as $group) {
            if (! isset($rows[$group])) {
                continue;
            }

            usort(
                $rows[$group],
                fn (array $a, array $b): int => Severity::rank((string) $b['severity']) <=> Severity::rank((string) $a['severity'])
            );

            $ordered[$group] = $rows[$group];
        }

        return $ordered;
    }

    /** @return array<string, mixed> */
    private function summaryRow(ActionProvider $provider, int $count): array
    {
        // items() is ordered oldest/most overdue first, so one row is enough to
        // learn both the worst age and the worst derived severity. Cheaper than
        // a second aggregate and it cannot disagree with the type page.
        $oldest = $provider->items(1)->first();

        return [
            'key' => $provider->key(),
            'group' => $provider->group(),
            'label' => $provider->label(),
            'description' => $provider->description(),
            'count' => $count,
            'severity' => $oldest->severity ?? $provider->severity(),
            'statutory' => $provider->statutory(),
            'sla_hours' => $provider->slaHours(),
            'oldest_at' => $oldest?->occurredAt->toIso8601String(),
            'oldest_age_hours' => $oldest?->ageHours(),
        ];
    }
}
