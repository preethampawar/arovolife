<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $centre_type
 * @property string|null $location
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $landmark
 * @property string|null $city
 * @property string|null $pincode
 * @property string|null $district
 * @property string|null $state
 * @property string|null $property_type
 * @property int|null $premises_sqft
 * @property string|null $distance_to_nearest_adc_km
 * @property string|null $opening_time
 * @property string|null $closing_time
 * @property string|null $weekly_off
 * @property string|null $contact_person
 * @property string|null $contact_number
 * @property string|null $alternate_contact_number
 * @property int|null $assigned_distributor_id
 * @property string $status
 * @property int $development_phase
 * @property int|null $monthly_cap_override_paise
 * @property string|null $approved_at
 * @property string|null $notes
 * @property bool $is_company_default
 * @property Carbon|null $deactivated_at
 * @property string|null $deactivation_reason
 */
final class AreteCenter extends Model
{
    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_INACTIVE = 'inactive';

    /** A centre the company runs itself (no distributor owner). */
    public const string TYPE_COMPANY = 'company';

    /** A centre opened and run by a distributor (earns the ADC bonus). */
    public const string TYPE_DISTRIBUTOR = 'distributor';

    public const array TYPES = [
        self::TYPE_COMPANY => 'Company centre',
        self::TYPE_DISTRIBUTOR => 'Distributor centre',
    ];

    public const array PROPERTY_TYPES = [
        'commercial' => 'Commercial',
        'residential' => 'Residential',
    ];

    public const array WEEKLY_OFF_OPTIONS = [
        'none' => 'None (open all week)',
        'monday' => 'Monday',
        'tuesday' => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday' => 'Thursday',
        'friday' => 'Friday',
        'saturday' => 'Saturday',
        'sunday' => 'Sunday',
    ];

    /**
     * Development phases (the client, 2026-08-07): judged on a single calendar
     * month's ADC income; upgraded manually by admin once the owner emails a
     * letter and photos of the developed centre.
     *
     * ADMIN-ONLY LABELS. They carry "up to ₹N/month" figures, so rendering
     * them on any distributor-facing surface would be an income projection
     * (hard rule 3). Distributor pages show the phase number only.
     */
    public const array PHASES = [
        1 => 'Phase 1 — up to ₹20,000/month · 400 sq ft, basic setup',
        2 => 'Phase 2 — up to ₹40,000/month · 600 sq ft, TV / Wi-Fi / stage',
        3 => 'Phase 3 — up to ₹60,000/month · 900 sq ft, AC + projector',
        4 => 'Phase 4 — up to ₹80,000/month · 1,200 sq ft, full facility',
    ];

    protected $fillable = [
        'name',
        'centre_type',
        'location',
        'address_line_1',
        'address_line_2',
        'landmark',
        'city',
        'pincode',
        'district',
        'state',
        'property_type',
        'premises_sqft',
        'distance_to_nearest_adc_km',
        'opening_time',
        'closing_time',
        'weekly_off',
        'contact_person',
        'contact_number',
        'alternate_contact_number',
        'assigned_distributor_id',
        'status',
        'development_phase',
        'monthly_cap_override_paise',
        'approved_at',
        'notes',
        'is_company_default',
        'deactivated_at',
        'deactivation_reason',
    ];

    protected function casts(): array
    {
        return [
            'development_phase' => 'int',
            'premises_sqft' => 'int',
            'monthly_cap_override_paise' => 'int',
            'is_company_default' => 'bool',
            'deactivated_at' => 'datetime',
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

    /** @return HasMany<AreteCenterApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(AreteCenterApplication::class, 'center_id');
    }

    /**
     * @param  Builder<AreteCenter>  $query
     * @return Builder<AreteCenter>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Active centres in the order a picker lists them: by state, then name.
     * The company default sorts first inside its state so it is the natural
     * pre-selection.
     *
     * @param  Builder<AreteCenter>  $query
     * @return Builder<AreteCenter>
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->active()
            ->orderByRaw('CASE WHEN state IS NULL THEN 1 ELSE 0 END')
            ->orderBy('state')
            ->orderByDesc('is_company_default')
            ->orderBy('name');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** "City, State" for pickers and lists; falls back to the legacy location. */
    public function displayLocation(): string
    {
        $parts = array_filter([$this->city, $this->state], fn ($p) => filled($p));

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return (string) ($this->location ?? '');
    }

    /** Single-line street address for the admin registry. */
    public function displayAddress(): string
    {
        return implode(', ', array_filter([
            $this->address_line_1,
            $this->address_line_2,
            $this->landmark,
            $this->city,
            $this->state,
            $this->pincode,
        ], fn ($p) => filled($p)));
    }
}
