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
 * @property int $development_phase
 * @property int|null $monthly_cap_override_paise
 * @property string|null $approved_at
 * @property string|null $notes
 * @property bool $is_company_default
 */
final class AreteCenter extends Model
{
    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    /**
     * Development phases (KP 2026-08-07): judged on a single calendar month's
     * ADC income; upgraded manually by admin once the owner emails a letter
     * and photos of the developed center.
     */
    public const array PHASES = [
        1 => 'Phase 1 — up to ₹20,000/month · 400 sq ft, basic setup',
        2 => 'Phase 2 — up to ₹40,000/month · 600 sq ft, TV / Wi-Fi / stage',
        3 => 'Phase 3 — up to ₹60,000/month · 900 sq ft, AC + projector',
        4 => 'Phase 4 — up to ₹80,000/month · 1,200 sq ft, full facility',
    ];

    protected $fillable = [
        'name',
        'location',
        'pincode',
        'district',
        'state',
        'assigned_distributor_id',
        'status',
        'development_phase',
        'monthly_cap_override_paise',
        'approved_at',
        'notes',
        'is_company_default',
    ];

    protected function casts(): array
    {
        return [
            'development_phase' => 'int',
            'monthly_cap_override_paise' => 'int',
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
