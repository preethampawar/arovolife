<?php

declare(strict_types=1);

namespace App\Modules\Returns\Models;

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $rma_no
 * @property int $order_id
 * @property int|null $order_item_id null for order-level returns (cooling-off)
 * @property int|null $qty null for order-level returns
 * @property string $reason BuybackMatrix::REASONS value
 * @property string $status
 */
final class ReturnRequest extends Model
{
    protected $table = 'return_requests';

    // Reasons — mirrors BuybackMatrix::REASONS exactly.
    public const REASON_COOLING_OFF = 'cooling_off';

    public const REASON_DAMAGE = 'damage';

    public const REASON_DISSATISFACTION = 'dissatisfaction';

    public const REASON_GENERAL_BUYBACK = 'general_buyback';

    public const REASON_TERMINATION_BUYBACK = 'termination_buyback';

    public const STATUS_OPENED = 'opened';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'rma_no', 'order_id', 'order_item_id', 'qty', 'reason',
        'opened_by_customer_id', 'notes', 'status',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function openedByCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'opened_by_customer_id');
    }

    public function inspection(): HasOne
    {
        return $this->hasOne(ReturnInspection::class);
    }

    public function buybackDecision(): HasOne
    {
        return $this->hasOne(BuybackDecision::class);
    }

    /** True when this return is for a cooling-off cancellation (one-click, non-discretionary). */
    public function isCoolingOff(): bool
    {
        return $this->reason === self::REASON_COOLING_OFF;
    }
}
