<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** @return BelongsTo<User, RegistrationDraft> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
