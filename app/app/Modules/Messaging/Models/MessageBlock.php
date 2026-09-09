<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $blocker_user_id
 * @property int $blocked_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $blocked
 */
final class MessageBlock extends Model
{
    protected $fillable = [
        'blocker_user_id',
        'blocked_user_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_user_id');
    }

    /** Has $recipientId blocked $senderId? */
    public static function blocks(int $recipientId, int $senderId): bool
    {
        return self::query()
            ->where('blocker_user_id', $recipientId)
            ->where('blocked_user_id', $senderId)
            ->exists();
    }
}
