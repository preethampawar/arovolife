<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Shared\Casts\PiiEncrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property string $full_name
 * @property string $relationship
 * @property Carbon $date_of_birth
 * @property string|null $pan_number
 * @property string|null $aadhaar_last4
 * @property string|null $aadhaar_encrypted
 * @property string|null $mobile
 * @property string|null $email
 * @property string|null $address
 * @property Carbon $consent_given_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class DistributorNominee extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'distributor_id',
        'full_name',
        'relationship',
        'date_of_birth',
        'pan_number',
        'aadhaar_last4',
        'aadhaar_encrypted',
        'mobile',
        'email',
        'address',
        'consent_given_at',
    ];

    /** @var list<string> */
    protected $hidden = ['aadhaar_encrypted'];

    protected function casts(): array
    {
        return [
            'relationship' => 'string',
            'date_of_birth' => 'date',
            'consent_given_at' => 'datetime',
            // Encrypted at rest with the dedicated PII key (PiiCrypter).
            // Raw Aadhaar is NEVER stored as plaintext — hard rule #8.
            'aadhaar_encrypted' => PiiEncrypted::class,
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
