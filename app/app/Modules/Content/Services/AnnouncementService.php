<?php

declare(strict_types=1);

namespace App\Modules\Content\Services;

use App\Modules\Content\Models\Announcement;
use App\Modules\Content\Models\AnnouncementRead;
use App\Modules\Content\Notifications\AnnouncementPublishedNotification;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Who sees which announcement, and what they have read.
 *
 * Audience membership is evaluated at read time rather than frozen into a
 * recipient list at publish time. A distributor who activates the day after a
 * "for active accounts" announcement went out should see it — the message is
 * about their situation, not about who happened to be in a snapshot.
 *
 * The one place this rule bends is rank: {@see Announcement::AUDIENCE_RANK}
 * is "has ever qualified at or above", not "holds this month", so a monthly
 * engine run cannot silently un-address someone mid-month.
 */
final class AnnouncementService
{
    public function __construct(private readonly AnnouncementSettingsService $settings) {}

    /**
     * Live announcements addressed to this user, pinned first, newest first.
     *
     * @return Collection<int, Announcement>
     */
    public function forUser(User $user): Collection
    {
        return Announcement::query()
            ->live()
            ->where(fn (Builder $q) => $this->addressedTo($q, $user))
            ->orderByDesc('pinned')
            ->orderByDesc('published_at')
            ->get();
    }

    /** How many addressed announcements this user has not opened. */
    public function unreadCountFor(User $user): int
    {
        return Announcement::query()
            ->live()
            ->where(fn (Builder $q) => $this->addressedTo($q, $user))
            ->whereNotExists(function ($sub) use ($user): void {
                $sub->select(DB::raw(1))
                    ->from('announcement_reads')
                    ->whereColumn('announcement_reads.announcement_id', 'announcements.id')
                    ->where('announcement_reads.user_id', $user->id);
            })
            ->count();
    }

    /** @return list<int> ids of the announcements this user has opened */
    public function readIdsFor(User $user): array
    {
        return array_values(array_map(
            static fn ($id): int => (int) $id,
            AnnouncementRead::query()->where('user_id', $user->id)->pluck('announcement_id')->all(),
        ));
    }

    /**
     * Record that a user has seen an announcement. Idempotent — opening the
     * page twice must not be two reads.
     */
    public function markRead(User $user, Announcement $announcement): void
    {
        AnnouncementRead::query()->firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $user->id],
            ['read_at' => now()],
        );
    }

    /**
     * May another announcement be pinned, or is the pin limit already met?
     * Refusing is deliberate: silently unpinning someone else's announcement
     * to make room would remove a message nobody chose to remove.
     */
    public function canPinAnother(?Announcement $excluding = null): bool
    {
        $query = Announcement::query()->where('pinned', true)->where('status', Announcement::STATUS_PUBLISHED);

        if ($excluding !== null && $excluding->exists) {
            $query->whereKeyNot($excluding->getKey());
        }

        return $query->count() < $this->settings->pinLimit();
    }

    /**
     * Email a published announcement to everyone it is addressed to.
     *
     * Chunked and queued: an audience is every distributor on the platform in
     * the common case, and a publish that holds the admin's request open while
     * it fans out is a publish that times out half-sent.
     *
     * Returns the number of recipients queued, which is what the admin is told
     * — "sent to 1,240 distributors" is a fact they can check against the
     * audience they chose, and a silent zero is the failure worth catching.
     */
    public function emailAudience(Announcement $announcement): int
    {
        $queued = 0;

        $this->audienceUsers($announcement)->chunkById(500, function ($users) use ($announcement, &$queued): void {
            Notification::send($users, new AnnouncementPublishedNotification($announcement));
            $queued += $users->count();
        });

        return $queued;
    }

    /**
     * The users an announcement is addressed to — the inverse of
     * {@see addressedTo()}, for the send side.
     *
     * @return EloquentBuilder<User>
     */
    private function audienceUsers(Announcement $announcement): EloquentBuilder
    {
        $query = User::query()
            ->whereHas('distributor')
            ->whereNotNull('email');

        return match ($announcement->audience) {
            Announcement::AUDIENCE_STATUS => $query->where('status', $announcement->audience_value),
            Announcement::AUDIENCE_RANK => $query->whereHas('distributor', function ($distributor) use ($announcement): void {
                $distributor->whereExists(function ($sub) use ($announcement): void {
                    $sub->select(DB::raw(1))
                        ->from('rank_qualifications')
                        ->whereColumn('rank_qualifications.distributor_id', 'distributors.id')
                        ->where('rank_qualifications.status', 'qualified')
                        ->where('rank_qualifications.rank_number', '>=', (int) $announcement->audience_value);
                });
            }),
            default => $query,
        };
    }

    /**
     * Narrow a query to the announcements addressed to this user.
     *
     * @param  Builder<Announcement>  $query
     */
    private function addressedTo(Builder $query, User $user): void
    {
        $distributorId = $user->distributor?->id;
        // The account status a distributor sees on their own dashboard —
        // users.status, the one User::STATUS_LABELS names. distributors.status
        // is a narrower active/inactive flag and would make "for blocked
        // accounts" address nobody.
        $accountStatus = $user->status;

        // Every rank threshold this user clears, as the strings the column
        // holds. Resolved in PHP rather than as a correlated column
        // comparison: audience_value is a varchar shared with the status
        // audience, and comparing it to an integer column is a coercion that
        // MySQL performs silently and SQLite gets wrong. Ranks run 1-9, so
        // the IN list is never more than nine values.
        $rankThresholds = $distributorId === null
            ? []
            : array_map('strval', range(1, max(0, $this->highestQualifiedRank($distributorId))));

        $query->where(function (Builder $q) use ($accountStatus, $rankThresholds): void {
            $q->where('audience', Announcement::AUDIENCE_ALL);

            if ($accountStatus !== null) {
                $q->orWhere(function (Builder $byStatus) use ($accountStatus): void {
                    $byStatus->where('audience', Announcement::AUDIENCE_STATUS)
                        ->where('audience_value', $accountStatus);
                });
            }

            if ($rankThresholds !== []) {
                $q->orWhere(function (Builder $byRank) use ($rankThresholds): void {
                    $byRank->where('audience', Announcement::AUDIENCE_RANK)
                        ->whereIn('audience_value', $rankThresholds);
                });
            }
        });
    }

    /**
     * The highest rank this distributor has ever qualified at, or 0.
     *
     * Read straight from the qualification ledger rather than through the
     * rank engine: the engine is flag-gated, and an audience that silently
     * empties while a flag is off is a message nobody receives and nobody
     * notices.
     */
    private function highestQualifiedRank(int $distributorId): int
    {
        return (int) DB::table('rank_qualifications')
            ->where('distributor_id', $distributorId)
            ->where('status', 'qualified')
            ->max('rank_number');
    }
}
