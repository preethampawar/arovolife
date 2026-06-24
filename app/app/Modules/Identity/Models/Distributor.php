<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Compliance\Models\CoolingOffEvent;
use App\Modules\Consent\Models\Consent;
use App\Modules\Genealogy\Models\GenealogyClosure;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Models\Sponsorship;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Orientation\Models\OrientationView;
use App\Modules\Shared\Casts\PiiEncrypted;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $adn
 * @property string|null $pan_hash
 * @property string $pan_last4
 * @property string|null $pan_encrypted
 * @property string|null $aadhaar_ref
 * @property string|null $aadhaar_last4
 * @property string|null $aadhaar_encrypted
 * @property string $bank_account_enc
 * @property string $bank_ifsc
 * @property int $sponsor_id
 * @property int|null $placement_id_at_registration
 * @property int $placement_parent_id
 * @property string|null $placement_side
 * @property string $side_chosen_by
 * @property int $depth
 * @property Carbon $effective_date
 * @property Carbon $cooling_off_end_at
 * @property string $state
 * @property int|null $spouse_distributor_id
 * @property bool $is_primary_couple
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read string|null $pan_masked
 * @property-read string|null $aadhaar_masked
 * @property-read User $user
 * @property-read Collection<int, KycDocument> $kycDocuments
 */
final class Distributor extends Model
{
    use HasFactory;

    protected $table = 'distributors';

    protected $fillable = [
        'user_id',
        'adn',
        'pan_hash',
        'pan_last4',
        'pan_encrypted',
        'aadhaar_ref',
        'aadhaar_last4',
        'aadhaar_encrypted',
        'bank_account_enc',
        'bank_ifsc',
        'sponsor_id',
        'placement_id_at_registration',
        'placement_parent_id',
        'placement_side',
        'side_chosen_by',
        'depth',
        'effective_date',
        'cooling_off_end_at',
        'state',
        'spouse_distributor_id',
        'is_primary_couple',
        'status',
        'gsb_frozen_at',
    ];

    protected $hidden = [
        'pan_hash',
        'pan_encrypted',
        'aadhaar_encrypted',
        'bank_account_enc',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'datetime',
            'cooling_off_end_at' => 'datetime',
            'gsb_frozen_at' => 'datetime',
            'is_primary_couple' => 'boolean',
            'depth' => 'integer',
            // Encrypted at rest with the dedicated PII key (ADR-0008), not
            // APP_KEY, so APP_KEY rotation never makes these unreadable. Backed
            // by VARBINARY(512). Nulled by ApproveKycSubmission after admin KYC
            // verification — at which point pan_last4 / aadhaar_last4 remain the
            // only on-disk representation of the number.
            'pan_encrypted' => PiiEncrypted::class,
            'aadhaar_encrypted' => PiiEncrypted::class,
        ];
    }

    /**
     * Display-safe PAN: mask all but the last 4 (e.g. "XXXXXX234F").
     * Reads pan_last4 (never the encrypted column) so it works post-purge.
     *
     * @return Attribute<string|null, never>
     */
    protected function panMasked(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->pan_last4 !== null && $this->pan_last4 !== ''
                ? str_repeat('X', 6).$this->pan_last4
                : null,
        );
    }

    /**
     * Display-safe Aadhaar: mask all but the last 4 (e.g. "XXXX XXXX 1234").
     * Reads aadhaar_last4 so it works post-purge.
     *
     * @return Attribute<string|null, never>
     */
    protected function aadhaarMasked(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->aadhaar_last4 !== null && $this->aadhaar_last4 !== ''
                ? 'XXXX XXXX '.$this->aadhaar_last4
                : null,
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Columns of the related user a genealogy tree card needs to render. */
    public const TREE_CARD_USER_COLUMNS = 'id,full_name,status,activated_at,closure_type';

    /**
     * Eager-load only the user columns a tree card needs. Centralised so the
     * column list — notably closure_type, which drives the Cancelled vs
     * Terminated badge — can't drift or be forgotten across the several
     * tree/sponsorship queries that render cards.
     *
     * @param  Builder<Distributor>  $query
     * @return Builder<Distributor>
     */
    public function scopeWithTreeUser(Builder $query): Builder
    {
        return $query->with(['user:'.self::TREE_CARD_USER_COLUMNS]);
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'sponsor_id');
    }

    public function placementParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'placement_parent_id');
    }

    public function spouse(): BelongsTo
    {
        return $this->belongsTo(self::class, 'spouse_distributor_id');
    }

    public function leftChild(): HasOne
    {
        return $this->hasOne(self::class, 'placement_parent_id')
            ->where('placement_side', 'L');
    }

    public function rightChild(): HasOne
    {
        return $this->hasOne(self::class, 'placement_parent_id')
            ->where('placement_side', 'R');
    }

    public function kycDocuments(): HasMany
    {
        return $this->hasMany(KycDocument::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function orientationViews(): HasMany
    {
        return $this->hasMany(OrientationView::class);
    }

    public function closureAncestors(): HasMany
    {
        return $this->hasMany(GenealogyClosure::class, 'descendant_id');
    }

    public function closureDescendants(): HasMany
    {
        return $this->hasMany(GenealogyClosure::class, 'ancestor_id');
    }

    public function sponsorship(): HasOne
    {
        return $this->hasOne(Sponsorship::class);
    }

    public function coolingOff(): HasOne
    {
        return $this->hasOne(CoolingOffEvent::class);
    }

    public function lineChangeRequests(): HasMany
    {
        return $this->hasMany(LineChangeRequest::class);
    }
}
