<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One exchange with the payout gateway — a call we made, or a webhook it
 * delivered.
 *
 * Append-only evidence: rows are written once and only `processed_at` /
 * `processing_error` are ever updated, so there is no `updated_at`.
 *
 * `payload` is always scrubbed (see RazorpayPayoutPayloadScrubber) — a bank
 * account number must never reach this table.
 *
 * @property int $id
 * @property int|null $payout_line_item_id
 * @property int|null $payout_batch_id
 * @property string $gateway
 * @property string $direction
 * @property string $event_type
 * @property string|null $gateway_event_id
 * @property string|null $gateway_payout_id
 * @property bool $signature_verified
 * @property int|null $http_status
 * @property int|null $duration_ms
 * @property array<array-key, mixed>|null $payload
 * @property string|null $error
 * @property Carbon|null $processed_at
 * @property string|null $processing_error
 * @property Carbon $created_at
 */
final class PayoutGatewayEvent extends Model
{
    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_WEBHOOK = 'webhook';

    public const GATEWAY_RAZORPAYX = 'razorpayx';

    public const UPDATED_AT = null;

    protected $table = 'payout_gateway_events';

    protected $fillable = [
        'payout_line_item_id', 'payout_batch_id', 'gateway', 'direction',
        'event_type', 'gateway_event_id', 'gateway_payout_id',
        'signature_verified', 'http_status', 'duration_ms',
        'payload', 'error', 'processed_at', 'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'payout_line_item_id' => 'integer',
            'payout_batch_id' => 'integer',
            'signature_verified' => 'boolean',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'payload' => 'array',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(PayoutLineItem::class, 'payout_line_item_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }
}
