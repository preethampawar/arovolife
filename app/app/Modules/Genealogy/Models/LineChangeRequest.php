<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property int $from_sponsor_id
 * @property int $to_sponsor_id
 * @property Carbon $requested_at
 * @property Carbon|null $approved_at
 * @property string $status
 * @property string|null $reason
 * @property-read Distributor $distributor
 * @property-read Distributor $fromSponsor
 * @property-read Distributor $toSponsor
 */
final class LineChangeRequest extends Model
{
    public $timestamps = false;

    protected $table = 'line_change_requests';

    protected $fillable = [
        'distributor_id',
        'from_sponsor_id',
        'to_sponsor_id',
        'requested_at',
        'approved_at',
        'status',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id');
    }

    public function fromSponsor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'from_sponsor_id');
    }

    public function toSponsor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'to_sponsor_id');
    }
}
