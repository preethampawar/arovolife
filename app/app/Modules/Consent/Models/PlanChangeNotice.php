<?php

declare(strict_types=1);

namespace App\Modules\Consent\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property string $doc_hash_sha256 raw 32-byte SHA-256 of the plan text noticed
 * @property string|null $labels_shown comma-separated version labels listed
 * @property Carbon|null $shown_at
 * @property Carbon|null $emailed_at
 * @property Carbon|null $email_failed_at
 * @property int|null $accepted_consent_id
 */
final class PlanChangeNotice extends Model
{
    protected $table = 'plan_change_notices';

    protected $fillable = [
        'distributor_id',
        'doc_hash_sha256',
        'labels_shown',
        'shown_at',
        'emailed_at',
        'email_failed_at',
        'accepted_consent_id',
    ];

    protected $hidden = ['doc_hash_sha256'];

    protected function casts(): array
    {
        return [
            'shown_at' => 'datetime',
            'emailed_at' => 'datetime',
            'email_failed_at' => 'datetime',
            'accepted_consent_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
