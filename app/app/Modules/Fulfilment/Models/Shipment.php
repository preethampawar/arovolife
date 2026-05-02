<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Models;

use App\Modules\Commerce\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Shipment extends Model
{
    protected $table = 'shipments';

    public const STATUS_CREATED = 'created';

    public const STATUS_PICKED = 'picked';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_RETURNED = 'returned_to_origin';

    protected $fillable = [
        'order_id', 'warehouse_code', 'carrier_code', 'awb_no',
        'status', 'dispatched_at', 'delivered_at', 'pod_hash_sha256',
    ];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
