<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Commerce\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentIntent extends Model
{
    protected $table = 'payment_intents';

    public const STATUS_CREATED = 'created';

    public const STATUS_AUTHORISED = 'authorised';

    public const STATUS_CAPTURED = 'captured';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_id', 'gateway', 'gateway_intent_id',
        'amount_paise', 'status', 'idempotency_key', 'raw_payload',
        'captured_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'amount_paise' => 'int',
            'captured_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
