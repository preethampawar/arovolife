<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $purchase_order_id
 * @property int $product_variant_id
 * @property int $qty_ordered
 * @property int $qty_received
 * @property int $unit_cost_paise
 */
final class PurchaseOrderItem extends Model
{
    protected $table = 'purchase_order_items';

    protected $fillable = [
        'purchase_order_id', 'product_variant_id', 'qty_ordered', 'qty_received', 'unit_cost_paise',
    ];

    protected function casts(): array
    {
        return [
            'purchase_order_id' => 'int',
            'product_variant_id' => 'int',
            'qty_ordered' => 'int',
            'qty_received' => 'int',
            'unit_cost_paise' => 'int',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function outstandingQty(): int
    {
        return max(0, $this->qty_ordered - $this->qty_received);
    }
}
