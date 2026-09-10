<?php

declare(strict_types=1);

namespace App\Modules\Content\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property string $body
 * @property string $audience
 * @property string|null $audience_value
 * @property string $status
 * @property bool $pinned
 * @property Carbon|null $published_at
 * @property Carbon|null $expires_at
 * @property int|null $created_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $author
 */
final class Announcement extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    /** Everyone with a distributor record. */
    public const AUDIENCE_ALL = 'all';

    /** Distributors whose account is in a given state (active, frozen, …). */
    public const AUDIENCE_STATUS = 'status';

    /**
     * Distributors who have ever qualified at or above a rank number.
     *
     * Deliberately "have ever reached", not "hold this month": an
     * announcement audience that changes shape every time the rank engine
     * runs would mean two people opening the same page see different lists,
     * and a message that quietly stops being addressed to someone mid-month
     * is worse than one addressed slightly too widely.
     */
    public const AUDIENCE_RANK = 'rank';

    protected $fillable = [
        'title',
        'body',
        'audience',
        'audience_value',
        'status',
        'pinned',
        'published_at',
        'expires_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'pinned' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<AnnouncementRead, $this> */
    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    /**
     * Published, in date, and not archived — the only announcements a
     * distributor may ever see.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED)
            ->where('published_at', '<=', now())
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function audienceLabel(): string
    {
        return match ($this->audience) {
            self::AUDIENCE_STATUS => 'Accounts that are '.($this->audience_value ?? '—'),
            self::AUDIENCE_RANK => 'Rank '.($this->audience_value ?? '—').' and above',
            default => 'Every distributor',
        };
    }
}
