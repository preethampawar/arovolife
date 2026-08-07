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
 * @property string|null $pincode
 * @property string|null $district
 * @property string|null $state
 * @property int|null $assigned_distributor_id
 * @property string $status
 * @property string|null $approved_at
 * @property string|null $notes
 * @property bool $is_company_default
 */
final class AreteCenter extends Model
{
    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'name',
        'location',
        'pincode',
        'district',
        'state',
        'assigned_distributor_id',
        'status',
        'approved_at',
        'notes',
        'is_company_default',
    ];

    protected function casts(): array
    {
        return [
            'is_company_default' => 'bool',
        ];
    }

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
