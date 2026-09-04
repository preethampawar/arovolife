<?php

declare(strict_types=1);

namespace App\Modules\Returns\Models;

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use Carbon\Carbon;
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
 * @property Carbon|null $received_at
 * @property int|null $received_by_user_id
 * @property string|null $receipt_outcome
 * @property string|null $receipt_note
 * @property int $entitlement_points_paise
 * @property int $entitlement_credit_paise
 * @property Carbon|null $entitlements_held_at
 * @property Carbon|null $entitlements_restored_at
 * @property Carbon|null $hold_alert_sent_at
 * @property Carbon|null $hold_escalated_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order $order
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

    public const STATUS_REFUNDED = 'refunded';

    public const RECEIPT_RECEIVED = 'received';

    public const RECEIPT_COURIER_LOST = 'courier_lost';

    public const RECEIPT_NOT_RETURNED = 'not_returned';

    protected $fillable = [
        'rma_no', 'order_id', 'order_item_id', 'qty', 'reason',
        'opened_by_customer_id', 'notes', 'status',
        'received_at', 'received_by_user_id', 'receipt_outcome', 'receipt_note',
        'entitlement_points_paise', 'entitlement_credit_paise', 'entitlements_held_at', 'entitlements_restored_at',
        'hold_alert_sent_at', 'hold_escalated_at',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'int',
            'received_at' => 'datetime',
            'entitlement_points_paise' => 'int',
            'entitlement_credit_paise' => 'int',
            'entitlements_held_at' => 'datetime',
            'entitlements_restored_at' => 'datetime',
            'hold_alert_sent_at' => 'datetime',
            'hold_escalated_at' => 'datetime',
        ];
    }

    /** The refund (cash, points, credit) is waiting for the goods to come back. */
    public function isAwaitingReceipt(): bool
    {
        return $this->entitlements_held_at !== null && $this->received_at === null && $this->receipt_outcome === null;
    }

    /** @return BelongsTo<Order, $this> */
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
