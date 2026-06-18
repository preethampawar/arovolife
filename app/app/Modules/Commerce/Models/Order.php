<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $order_no
 * @property string $status
 * @property string $payment_method
 * @property string $ship_name
 * @property int $subtotal_paise
 * @property int $gst_paise
 * @property int $discount_paise
 * @property int $total_paise
 * @property int $shipping_paise
 * @property int $customer_id
 * @property string|null $ship_carrier
 * @property string|null $ship_tracking_no
 */
final class Order extends Model
{
    protected $table = 'orders';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PLACED = 'placed';

    public const STATUS_PAID = 'paid';

    public const STATUS_READY_TO_SHIP = 'ready_to_ship';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REFUND_REQUESTED = 'refund_requested';

    public const STATUS_REFUND_INSPECTION = 'refund_inspection';

    public const STATUS_REFUND_APPROVED = 'refund_approved';

    public const STATUS_REFUNDED = 'refunded';

    public const PAYMENT_ONLINE = 'online';

    public const PAYMENT_COD = 'cod';

    protected $fillable = [
        'order_no', 'customer_id', 'attributed_distributor_id', 'attribution_source',
        'payment_method', 'status', 'self_consumption',
        'subtotal_paise', 'gst_paise', 'discount_paise', 'shipping_paise', 'total_paise',
        'ship_name', 'ship_phone_e164', 'ship_line1', 'ship_line2',
        'ship_city', 'ship_state', 'ship_pincode', 'ship_carrier', 'ship_tracking_no',
        'placed_at', 'paid_at', 'shipped_at', 'delivered_at', 'cancelled_at', 'refund_approved_at', 'refunded_at',
        'idempotency_key', 'tnc_of_sale_consent_id',
    ];

    protected function casts(): array
    {
        return [
            'self_consumption' => 'bool',
            'subtotal_paise' => 'int',
            'gst_paise' => 'int',
            'discount_paise' => 'int',
            'shipping_paise' => 'int',
            'total_paise' => 'int',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refund_approved_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'attributed_distributor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function coolingOff(): HasOne
    {
        return $this->hasOne(OrderCoolingOff::class);
    }

    public function bvLedgerEntries(): HasMany
    {
        return $this->hasMany(BvLedgerEntry::class);
    }

    public function displayTotal(): string
    {
        return '₹'.number_format($this->total_paise / 100, 2);
    }

    /**
     * Total Business Volume for the whole order (sum of line BV), in paise.
     * The single source of truth for an order's BV — every surface that shows
     * an order's BV total reads this. Requires `items` to be loaded.
     */
    public function bvTotalPaise(): int
    {
        return (int) $this->items->sum(fn (OrderItem $item): int => $item->lineBvPaise());
    }

    /**
     * The accumulation status of this order's personal BV, for the buyer's own
     * order history (ADR-0006, revised 2026-06-02 — BV accrues on payment, with
     * no cooling-off gating). Only meaningful for self-consumption purchases;
     * other orders carry no personal BV and return 'none'. Requires `items` and
     * `bvLedgerEntries` to be loaded.
     *
     * @return array{state: 'none'|'pending'|'accumulated'|'reversed', label: string}
     */
    public function personalBvStatus(): array
    {
        if (! $this->self_consumption || $this->bvTotalPaise() <= 0) {
            return ['state' => 'none', 'label' => '—'];
        }

        return $this->bvAccrualState();
    }

    /**
     * BV accumulation status for the attributing distributor's "My Sales" view —
     * shows whether BV from a customer-attributed order has been credited.
     * Requires `items` and `bvLedgerEntries` to be loaded.
     *
     * @return array{state: 'none'|'pending'|'accumulated'|'reversed', label: string}
     */
    public function salesBvStatus(): array
    {
        if ($this->attributed_distributor_id === null || $this->bvTotalPaise() <= 0) {
            return ['state' => 'none', 'label' => '—'];
        }

        return $this->bvAccrualState();
    }

    /**
     * @return array{state: 'none'|'pending'|'accumulated'|'reversed', label: string}
     */
    private function bvAccrualState(): array
    {
        if ($this->bvLedgerEntries->firstWhere('type', BvLedgerEntry::TYPE_REVERSAL) !== null) {
            return ['state' => 'reversed', 'label' => 'Reversed (refunded)'];
        }

        if ($this->bvLedgerEntries->firstWhere('type', BvLedgerEntry::TYPE_ACCRUAL) !== null) {
            return ['state' => 'accumulated', 'label' => 'Accumulated'];
        }

        return ['state' => 'pending', 'label' => 'Awaiting payment'];
    }
}
