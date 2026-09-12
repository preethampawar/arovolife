<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $product_variant_id
 * @property string $warehouse_code
 * @property int $reserved
 * @property int $on_hand
 * @property int $reorder_level
 */
final class InventoryLevel extends Model
{
    protected $table = 'inventory_levels';

    protected $fillable = ['product_variant_id', 'warehouse_code', 'on_hand', 'reserved', 'reorder_level'];

    protected function casts(): array
    {
        return [
            'on_hand' => 'int',
            'reserved' => 'int',
            'reorder_level' => 'int',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function available(): int
    {
        return max(0, $this->on_hand - $this->reserved);
    }
}
