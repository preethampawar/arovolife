<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A distributor's formal request about their own record.
 *
 *   submitted → under_review → approved | rejected
 *
 * Name and date-of-birth requests are applied to the record on approval;
 * membership transfer and ID cancellation are acknowledged on approval and
 * then carried out by compliance with the existing account tools (they touch
 * one-PAN-one-ADN, KYC and the genealogy, so they are never automatic).
 *
 * @property int $id
 * @property string $request_no
 * @property int $distributor_id
 * @property string $type
 * @property string $status
 * @property array<string, mixed> $details
 * @property string $reason
 * @property array<string, mixed>|null $snapshot_before
 * @property string|null $admin_notes
 * @property int|null $reviewed_by_user_id
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $applied_at
 * @property Carbon|null $submitted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Distributor $distributor
 * @property-read User|null $reviewedBy
 * @property-read Collection<int, DistributorRequestDocument> $documents
 */
final class DistributorRequest extends Model
{
    public const string TYPE_NAME_CORRECTION = 'name_correction';

    public const string TYPE_NAME_CHANGE = 'name_change';

    public const string TYPE_DOB_CORRECTION = 'dob_correction';

    public const string TYPE_MEMBERSHIP_TRANSFER = 'membership_transfer';

    public const string TYPE_ID_CANCELLATION = 'id_cancellation';

    /**
     * type => label, what it does, whether approval applies the change to
     * the record itself (`applies`), which permission decides it, and the
     * documents it needs.
     *
     * @var array<string, array{label: string, summary: string, applies: bool, decide: string, documents: array<string, array{label: string, required: bool}>}>
     */
    public const array TYPES = [
        self::TYPE_NAME_CORRECTION => [
            'label' => 'Name correction',
            'summary' => 'Fix a spelling mistake or a missing initial so your name matches your PAN card. Same person, same name.',
            'applies' => true,
            'decide' => 'kyc.review',
            'documents' => [
                'id_proof' => ['label' => 'PAN card (or another government ID) showing the correct spelling', 'required' => true],
            ],
        ],
        self::TYPE_NAME_CHANGE => [
            'label' => 'Name change (same person)',
            'summary' => 'Your legal name has changed — for example after marriage or by gazette notification.',
            'applies' => true,
            'decide' => 'kyc.review',
            'documents' => [
                'legal_proof' => ['label' => 'Gazette notification, marriage certificate or affidavit for the name change', 'required' => true],
                'id_proof' => ['label' => 'Updated PAN card or government ID in the new name', 'required' => true],
            ],
        ],
        self::TYPE_DOB_CORRECTION => [
            'label' => 'Date of birth correction',
            'summary' => 'Correct a wrongly entered date of birth so it matches your identity documents.',
            'applies' => true,
            'decide' => 'kyc.review',
            'documents' => [
                'id_proof' => ['label' => 'PAN card, Aadhaar (masked) or birth certificate showing the correct date of birth', 'required' => true],
            ],
        ],
        self::TYPE_MEMBERSHIP_TRANSFER => [
            'label' => 'Membership transfer to an immediate blood relation',
            'summary' => 'Ask for your distributorship to pass to a spouse, parent, child or sibling. Compliance reviews it under the Direct Seller Agreement; the relation completes their own KYC before anything changes.',
            'applies' => false,
            'decide' => 'compliance.discipline',
            'documents' => [
                'relationship_proof' => ['label' => 'Proof of relationship (e.g. marriage certificate, birth certificate, family ID)', 'required' => true],
                'consent_letter' => ['label' => 'Signed consent letter from the relation accepting the transfer', 'required' => true],
                'supporting' => ['label' => 'Any other supporting document (e.g. death certificate, medical certificate)', 'required' => false],
            ],
        ],
        self::TYPE_ID_CANCELLATION => [
            'label' => 'ID cancellation',
            'summary' => 'Ask to close your distributorship. Within the first 30 days, use the one-click cooling-off cancellation instead — it refunds in full.',
            'applies' => false,
            'decide' => 'compliance.discipline',
            'documents' => [],
        ],
    ];

    public const string STATUS_SUBMITTED = 'submitted';

    public const string STATUS_UNDER_REVIEW = 'under_review';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_REJECTED = 'rejected';

    public const array STATUSES = [
        self::STATUS_SUBMITTED => 'Submitted',
        self::STATUS_UNDER_REVIEW => 'Under review',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
    ];

    public const array OPEN_STATUSES = [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW];

    public const array RELATIONSHIPS = [
        'spouse' => 'Spouse',
        'parent' => 'Parent',
        'child' => 'Son / daughter',
        'sibling' => 'Brother / sister',
    ];

    protected $fillable = [
        'request_no', 'distributor_id', 'type', 'status', 'details', 'reason', 'snapshot_before',
        'admin_notes', 'reviewed_by_user_id', 'reviewed_at', 'applied_at', 'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'snapshot_before' => 'array',
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** @return HasMany<DistributorRequestDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(DistributorRequestDocument::class, 'request_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type]['label'] ?? ucwords(str_replace('_', ' ', $this->type));
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    /** Approval changes the record itself (name / DOB) rather than just acknowledging. */
    public function appliesOnApproval(): bool
    {
        return (bool) (self::TYPES[$this->type]['applies'] ?? false);
    }

    /** The permission an admin needs to approve or reject this request. */
    public function decidePermission(): string
    {
        return self::TYPES[$this->type]['decide'] ?? 'compliance.discipline';
    }

    /** Human-readable "requested" summary for lists and emails. */
    public function requestedSummary(): string
    {
        $d = $this->details;

        return match ($this->type) {
            self::TYPE_NAME_CORRECTION, self::TYPE_NAME_CHANGE => 'New name: '.($d['requested_full_name'] ?? '—'),
            self::TYPE_DOB_CORRECTION => 'New date of birth: '.(isset($d['requested_date_of_birth']) ? Carbon::parse($d['requested_date_of_birth'])->format('d M Y') : '—'),
            self::TYPE_MEMBERSHIP_TRANSFER => 'Transfer to '.($d['transferee_name'] ?? '—').' ('.(self::RELATIONSHIPS[$d['relationship'] ?? ''] ?? '—').')',
            self::TYPE_ID_CANCELLATION => 'Close the distributorship',
            default => '—',
        };
    }
}
