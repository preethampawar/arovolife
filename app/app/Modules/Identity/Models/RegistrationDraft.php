<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $draft_token_hash
 * @property int $current_step
 * @property int $sponsor_id
 * @property int $placement_id
 * @property string|null $side_opt
 * @property string $payload_enc
 * @property Carbon|null $resume_link_sent_at
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class RegistrationDraft extends Model
{
    protected $fillable = [
        'user_id',
        'draft_token_hash',
        'current_step',
        'sponsor_id',
        'placement_id',
        'side_opt',
        'payload_enc',
        'resume_link_sent_at',
        'expires_at',
    ];

    protected $casts = [
        'current_step' => 'integer',
        'sponsor_id' => 'integer',
        'placement_id' => 'integer',
        'expires_at' => 'datetime',
        'resume_link_sent_at' => 'datetime',
    ];

    /** @param  Builder<RegistrationDraft>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('expires_at', '>', now());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
