<?php

declare(strict_types=1);

namespace App\Modules\Consent\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property string $document_type
 * @property string $document_version
 * @property string|null $doc_hash_sha256
 * @property Carbon $accepted_at
 * @property Carbon|null $withdrawn_at
 * @property string|null $withdrawal_reason
 * @property string|null $ip
 * @property string|null $user_agent
 */
final class Consent extends Model
{
    public $timestamps = false;

    protected $table = 'consents';

    protected $fillable = [
        'distributor_id',
        'document_type',
        'document_version',
        'doc_hash_sha256',
        'accepted_at',
        'withdrawn_at',
        'withdrawal_reason',
        'ip',
        'user_agent',
    ];

    protected $hidden = ['doc_hash_sha256'];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /**
     * Consent that is still in force.
     *
     * Withdrawn rows are kept, never deleted: withdrawal does not invalidate
     * processing carried out before it, so the record of what was agreed and
     * when is the platform's own evidence that the earlier processing was
     * lawful. Any code asking "may we still process?" must use this scope
     * rather than counting rows.
     */
    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('withdrawn_at');
    }
}
