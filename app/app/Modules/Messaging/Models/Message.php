<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $from_user_id
 * @property int $to_user_id
 * @property string $body
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $fromUser
 * @property-read User $toUser
 */
final class Message extends Model
{
    protected $fillable = [
        'from_user_id',
        'to_user_id',
        'body',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /**
     * Messages received by $userId that haven't been read yet — drives
     * the topnav bell badge count and the per-conversation unread badge.
     *
     * @param  Builder<Message>  $query
     */
    public function scopeUnreadFor(Builder $query, int $userId): void
    {
        $query->where('to_user_id', $userId)->whereNull('read_at');
    }

    /**
     * Full chronological thread between two user_ids (in either
     * direction). Order by created_at ASC so the chat view renders
     * oldest first / newest last.
     *
     * @param  Builder<Message>  $query
     */
    public function scopeThreadBetween(Builder $query, int $userA, int $userB): void
    {
        $query
            ->where(function (Builder $q) use ($userA, $userB): void {
                $q->where('from_user_id', $userA)->where('to_user_id', $userB);
            })
            ->orWhere(function (Builder $q) use ($userA, $userB): void {
                $q->where('from_user_id', $userB)->where('to_user_id', $userA);
            })
            ->orderBy('created_at');
    }
}
