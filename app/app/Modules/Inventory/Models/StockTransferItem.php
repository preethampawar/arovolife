<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_transfer_id
 * @property int $product_variant_id
 * @property int $stock_batch_id
 * @property int $qty
 */
final class StockTransferItem extends Model
{
    protected $table = 'stock_transfer_items';

    protected $fillable = ['stock_transfer_id', 'product_variant_id', 'stock_batch_id', 'qty'];

    protected function casts(): array
    {
        return [
            'stock_transfer_id' => 'int',
            'product_variant_id' => 'int',
            'stock_batch_id' => 'int',
            'qty' => 'int',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StockTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }
}
