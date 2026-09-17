<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Models;

use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Models\AreteCenter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $order_id
 * @property string $warehouse_code
 * @property string $carrier_code
 * @property string|null $awb_no
 * @property string $gateway
 * @property string|null $gateway_shipment_id
 * @property string|null $label_url
 * @property int|null $arete_center_id
 * @property string $status
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $consigned_at
 * @property Carbon|null $at_centre_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $collected_at
 * @property int|null $collected_by_user_id
 * @property string|null $pod_hash_sha256
 */
final class Shipment extends Model
{
    protected $table = 'shipments';

    public const STATUS_CREATED = 'created';

    public const STATUS_PICKED = 'picked';

    public const STATUS_DISPATCHED = 'dispatched';

    /** Collection orders only: the parcel has reached the centre and the centre has acknowledged it. */
    public const STATUS_AT_CENTRE = 'at_centre';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_RETURNED = 'returned_to_origin';

    /** A carrier name and AWB an operator typed in by hand. The default, and the fallback when no courier gateway is configured. */
    public const GATEWAY_MANUAL = 'manual';

    public const GATEWAY_SHIPROCKET = 'shiprocket';

    protected $fillable = [
        'order_id', 'warehouse_code', 'carrier_code', 'awb_no',
        'gateway', 'gateway_shipment_id', 'label_url',
        'arete_center_id', 'consigned_at', 'at_centre_at',
        'collected_at', 'collected_by_user_id',
        'status', 'dispatched_at', 'delivered_at', 'pod_hash_sha256',
    ];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'consigned_at' => 'datetime',
            'at_centre_at' => 'datetime',
            'collected_at' => 'datetime',
            'arete_center_id' => 'int',
            'collected_by_user_id' => 'int',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The centre this parcel was consigned to, on a collection order. Null on a
     * home delivery.
     *
     * @return BelongsTo<AreteCenter, $this>
     */
    public function areteCenter(): BelongsTo
    {
        return $this->belongsTo(AreteCenter::class, 'arete_center_id');
    }

    /**
     * Has the buyer actually taken possession?
     *
     * This is the question the ADC bonus must ask before paying a centre for
     * fulfilment work (R-24): a centre is consideration-earning only for parcels
     * it received, held and handed over, not for every order that named it at
     * checkout.
     */
    public function isCollected(): bool
    {
        return $this->collected_at !== null;
    }
}
