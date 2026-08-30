<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A distributor's application to open an Arete Development Centre.
 *
 *   submitted → under_review → approved | rejected
 *                            ↘ needs_changes → (applicant edits) → submitted
 *
 * Approval creates/activates the `arete_centers` row and links it here via
 * `center_id`. The application is free and creates no purchase obligation
 * (hard rule 1); it carries no PAN / Aadhaar / bank data (hard rule 8) —
 * the applicant's identity is read from the distributor record.
 *
 * @property int $id
 * @property int $distributor_id
 * @property int|null $center_id
 * @property string $status
 * @property string $centre_name
 * @property string|null $contact_person
 * @property string|null $alternate_contact_number
 * @property string|null $applicant_alternate_mobile
 * @property string $address_line_1
 * @property string|null $address_line_2
 * @property string $landmark
 * @property string $pincode
 * @property string $city
 * @property string $state
 * @property string $property_type
 * @property int $premises_sqft
 * @property string $distance_to_nearest_adc_km
 * @property string $opening_time
 * @property string $closing_time
 * @property string $weekly_off
 * @property string|null $admin_notes
 * @property int|null $reviewed_by_user_id
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $submitted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Distributor $distributor
 * @property-read AreteCenter|null $center
 * @property-read User|null $reviewedBy
 */
final class AreteCenterApplication extends Model
{
    public const string STATUS_SUBMITTED = 'submitted';

    public const string STATUS_UNDER_REVIEW = 'under_review';

    public const string STATUS_NEEDS_CHANGES = 'needs_changes';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_REJECTED = 'rejected';

    public const array STATUSES = [
        self::STATUS_SUBMITTED => 'Submitted',
        self::STATUS_UNDER_REVIEW => 'Under review',
        self::STATUS_NEEDS_CHANGES => 'Needs changes',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
    ];

    /** Statuses in which the application still awaits a decision. */
    public const array OPEN_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_NEEDS_CHANGES,
    ];

    /** Snapshot columns copied onto the centre on approval. */
    public const array CENTRE_FIELDS = [
        'address_line_1', 'address_line_2', 'landmark', 'pincode', 'city', 'state',
        'property_type', 'premises_sqft', 'distance_to_nearest_adc_km',
        'opening_time', 'closing_time', 'weekly_off', 'contact_person',
        'alternate_contact_number',
    ];

    protected $fillable = [
        'distributor_id', 'center_id', 'status',
        'centre_name', 'contact_person', 'alternate_contact_number', 'applicant_alternate_mobile',
        'address_line_1', 'address_line_2', 'landmark', 'pincode', 'city', 'state',
        'property_type', 'premises_sqft', 'distance_to_nearest_adc_km',
        'opening_time', 'closing_time', 'weekly_off',
        'admin_notes', 'reviewed_by_user_id', 'reviewed_at', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'premises_sqft' => 'int',
            'reviewed_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id');
    }

    /** @return BelongsTo<AreteCenter, $this> */
    public function center(): BelongsTo
    {
        return $this->belongsTo(AreteCenter::class, 'center_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** @return HasMany<AreteCenterApplicationDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(AreteCenterApplicationDocument::class, 'application_id');
    }

    /** @return HasMany<AreteCenterApplicationDeclaration, $this> */
    public function declarations(): HasMany
    {
        return $this->hasMany(AreteCenterApplicationDeclaration::class, 'application_id');
    }

    /**
     * @param  Builder<AreteCenterApplication>  $query
     * @return Builder<AreteCenterApplication>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** The applicant may edit only while changes have been requested. */
    public function isEditableByApplicant(): bool
    {
        return $this->status === self::STATUS_NEEDS_CHANGES;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucwords(str_replace('_', ' ', $this->status));
    }

    public function displayLocation(): string
    {
        return implode(', ', array_filter([$this->city, $this->state], fn ($p) => filled($p)));
    }
}
