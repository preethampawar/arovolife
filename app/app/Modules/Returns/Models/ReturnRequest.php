<?php

declare(strict_types=1);

namespace App\Modules\Returns\Models;

use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class ReturnRequest extends Model
{
    protected $table = 'return_requests';

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

    public function inspection(): HasOne
    {
        return $this->hasOne(ReturnInspection::class);
    }

    public function buybackDecision(): HasOne
    {
        return $this->hasOne(BuybackDecision::class);
    }
}
