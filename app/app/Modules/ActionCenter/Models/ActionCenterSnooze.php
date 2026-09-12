<?php

declare(strict_types=1);

namespace App\Modules\ActionCenter\Models;

use App\Modules\Identity\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The Action Center's only write (plan §5): a manager's statement that one
 * subject of one action type is known and deferred.
 *
 * Rows are never swept. A snooze expires by query — every provider filters on
 * `snoozed_until > now()` — so an expired row is simply inert history, and the
 * audit trail of who deferred what keeps its evidence.
 *
 * @property int $id
 * @property string $action_key
 * @property string $subject_type
 * @property int $subject_id
 * @property Carbon $snoozed_until
 * @property string $reason
 * @property int|null $actor_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ActionCenterSnooze extends Model
{
    protected $table = 'action_center_snoozes';

    protected $fillable = [
        'action_key',
        'subject_type',
        'subject_id',
        'snoozed_until',
        'reason',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'actor_user_id' => 'integer',
            'snoozed_until' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @param Builder<$this> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('snoozed_until', '>', now());
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
