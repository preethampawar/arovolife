<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Commerce\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sale a franchise commission was paid on (R-22).
 *
 * Evidence, not a cache. `base_paise` is the net product value at the moment
 * of payment — the order can be edited, refunded or repriced afterwards and
 * this row still says what the commission was actually calculated from.
 *
 * @property int $id
 * @property int $result_id
 * @property int $order_id
 * @property int $base_paise
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property-read FranchiseCommissionResult $result
 * @property-read Order $order
 */
final class FranchiseCommissionResultOrder extends Model
{
    protected $table = 'franchise_commission_result_orders';

    protected $fillable = [
        'result_id', 'order_id', 'base_paise', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'base_paise' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FranchiseCommissionResult, $this> */
    public function result(): BelongsTo
    {
        return $this->belongsTo(FranchiseCommissionResult::class, 'result_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
