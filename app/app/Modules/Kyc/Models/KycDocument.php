<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Models;

use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property string $type
 * @property string $object_storage_key
 * @property string $checksum_sha256
 * @property Carbon|null $verified_at
 * @property int|null $verifier_id
 * @property string|null $flagged_reason
 * @property Carbon|null $flagged_at
 * @property int|null $flagged_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Distributor $distributor
 * @property-read User|null $verifier
 * @property-read User|null $flagger
 */
final class KycDocument extends Model
{
    protected $table = 'kyc_documents';

    protected $fillable = [
        'distributor_id',
        'type',
        'object_storage_key',
        'checksum_sha256',
        'verified_at',
        'verifier_id',
        'flagged_reason',
        'flagged_at',
        'flagged_by',
    ];

    protected $hidden = [
        'object_storage_key',
        'checksum_sha256',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'flagged_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verifier_id');
    }

    /** @return BelongsTo<User, $this> */
    public function flagger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }

    public function isFlagged(): bool
    {
        return $this->flagged_at !== null;
    }
}
