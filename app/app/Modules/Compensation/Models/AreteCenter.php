<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $location
 * @property int $assigned_distributor_id
 * @property string $status
 * @property string|null $approved_at
 * @property string|null $notes
 */
final class AreteCenter extends Model
{
    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'name',
        'location',
        'assigned_distributor_id',
        'status',
        'approved_at',
        'notes',
    ];

    /** @return BelongsTo<Distributor, $this> */
    public function assignedDistributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'assigned_distributor_id');
    }

    /** @return HasMany<AreteCenterMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(AreteCenterMember::class, 'center_id');
    }

    /** @return HasMany<AdcBonusResult, $this> */
    public function bonusResults(): HasMany
    {
        return $this->hasMany(AdcBonusResult::class, 'center_id');
    }
}
