<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $center_id
 * @property int $distributor_id
 * @property string $effective_from
 * @property string|null $effective_to
 */
final class AreteCenterMember extends Model
{
    protected $fillable = [
        'center_id',
        'distributor_id',
        'effective_from',
        'effective_to',
    ];

    /** @return BelongsTo<AreteCenter, $this> */
    public function center(): BelongsTo
    {
        return $this->belongsTo(AreteCenter::class, 'center_id');
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id');
    }
}
