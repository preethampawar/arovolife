<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Commerce\Models\Order;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property int $order_id
 * @property int $bv_paise
 * @property string $side 'L' or 'R'
 * @property Carbon $date
 * @property Carbon|null $reversed_at
 * @property Carbon $created_at
 */
final class GsbPersonalBvTopup extends Model
{
    public $timestamps = false;

    protected $table = 'gsb_personal_bv_topups';

    protected $fillable = [
        'distributor_id', 'order_id', 'bv_paise', 'side', 'date', 'reversed_at', 'created_at',
    ];

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    protected function casts(): array
    {
        return [
            'bv_paise' => 'integer',
            'date' => 'date',
            'reversed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
