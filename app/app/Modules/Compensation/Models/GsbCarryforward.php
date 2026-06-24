<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $distributor_id
 * @property int $power_side_bv_paise
 * @property string|null $power_side
 * @property int $slab1_weaker_bv_paise
 */
final class GsbCarryforward extends Model
{
    protected $table = 'gsb_carryforward';

    protected $fillable = [
        'distributor_id', 'power_side_bv_paise', 'power_side', 'slab1_weaker_bv_paise',
    ];

    protected function casts(): array
    {
        return [
            'power_side_bv_paise' => 'integer',
            'slab1_weaker_bv_paise' => 'integer',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
