<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Shared\Casts\PiiEncrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A family member the distributor has registered for the arovolife Family
 * Card. The card itself only ever shows "1 + 5 family members"; the name is
 * kept for the card's stated purpose (Privacy Policy §4 8b) and never shown to
 * anyone but the distributor and admin-compliance.
 *
 * @property int $id
 * @property int $distributor_id
 * @property string|null $full_name
 * @property string $relation
 * @property bool $is_minor
 * @property int|null $turns_18_year
 * @property Carbon $consent_given_at
 * @property Carbon|null $guardian_consent_at
 * @property string $consent_version
 * @property string $consent_text_hash
 * @property int $consented_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class DistributorFamilyMember extends Model
{
    /** The card is "1 + 5": the distributor plus at most five family members. */
    public const MAX_PER_DISTRIBUTOR = 5;

    /** @var array<string, string> relation key => label */
    public const RELATIONS = [
        'spouse' => 'Spouse',
        'child' => 'Son / Daughter',
        'parent' => 'Father / Mother',
        'parent_in_law' => 'Father-in-law / Mother-in-law',
        'sibling' => 'Brother / Sister',
        'other' => 'Other family member',
    ];

    /** Bump with any change to either consent text below. */
    public const CONSENT_VERSION = '2026-10-11';

    public const CONSENT_TEXT = 'I confirm that this family member has agreed to arovolife keeping their name and relation to me, only to register them as a family member on my arovolife Family Card. I can remove it at any time on My Profile, and they can ask arovolife to erase it by writing to dpo@arovolife.com. I have read the Privacy Policy, including how to complain to the Data Protection Board of India.';

    public const GUARDIAN_CONSENT_TEXT = 'I am this child\'s parent or lawful guardian, and I consent to arovolife keeping my child\'s name and relation to me for the arovolife Family Card only. arovolife will not track, profile or send advertising to my child. I can remove this at any time on My Profile.';

    /** @var list<string> */
    protected $fillable = [
        'distributor_id',
        'full_name',
        'relation',
        'is_minor',
        'turns_18_year',
        'consent_given_at',
        'guardian_consent_at',
        'consent_version',
        'consent_text_hash',
        'consented_by_user_id',
    ];

    /** @var list<string> */
    protected $hidden = ['full_name'];

    protected function casts(): array
    {
        return [
            // Encrypted at rest with the dedicated PII key (hard rule #8) — a
            // third party's name, held on the distributor's word.
            'full_name' => PiiEncrypted::class,
            'relation' => 'string',
            'is_minor' => 'boolean',
            'turns_18_year' => 'integer',
            'consent_given_at' => 'datetime',
            'guardian_consent_at' => 'datetime',
            'consented_by_user_id' => 'integer',
        ];
    }

    /** sha256 of the consent wording a row was added under. */
    public static function consentTextHash(bool $isMinor): string
    {
        return hash('sha256', $isMinor
            ? self::CONSENT_TEXT."\n".self::GUARDIAN_CONSENT_TEXT
            : self::CONSENT_TEXT);
    }

    public function relationLabel(): string
    {
        return self::RELATIONS[$this->relation] ?? ucfirst($this->relation);
    }

    /**
     * Delete every family member of a distributor — on termination of the ADN
     * or a cooling-off cancellation (Privacy Policy §5). A no-op when none.
     */
    public static function eraseFor(int $distributorId): int
    {
        return self::query()->where('distributor_id', $distributorId)->delete();
    }

    /**
     * @return BelongsTo<Distributor, $this>
     */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
