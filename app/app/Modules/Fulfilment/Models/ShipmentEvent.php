<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Models;

use App\Modules\Commerce\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per interaction with a courier gateway — an outbound API call or an
 * inbound tracking webhook.
 *
 * `payload` holds the scrubbed, allow-listed object only. A courier exchange
 * carries the buyer's name, phone and full delivery address, so nothing writes
 * a raw payload here (DPDP 2023 §8(3); hard rule 8 by analogy).
 *
 * `UPDATED_AT = null`: these rows are an append-only audit trail. The two
 * nullable `processed_*` columns are set once by the queued webhook handler and
 * are the single exception — they record when an event was applied, or why it
 * could not be.
 */
final class ShipmentEvent extends Model
{
    public const UPDATED_AT = null;

    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_WEBHOOK = 'webhook';

    public const DIRECTION_SYSTEM = 'system';

    protected $table = 'shipment_events';

    protected $fillable = [
        'shipment_id', 'order_id', 'gateway', 'direction', 'event_type',
        'gateway_event_id', 'gateway_shipment_id', 'signature_verified',
        'http_status', 'duration_ms', 'payload', 'error',
        'processed_at', 'processing_error', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'shipment_id' => 'int',
            'order_id' => 'int',
            'signature_verified' => 'bool',
            'http_status' => 'int',
            'duration_ms' => 'int',
            'payload' => 'array',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Shipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
