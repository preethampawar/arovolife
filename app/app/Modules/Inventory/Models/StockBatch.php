<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $product_variant_id
 * @property string $warehouse_code
 * @property string $batch_no
 * @property CarbonInterface|null $mfg_date
 * @property CarbonInterface|null $expiry_date
 * @property int $unit_cost_paise
 * @property int $qty_on_hand
 * @property CarbonInterface|null $expiry_alerted_at
 */
final class StockBatch extends Model
{
    protected $table = 'stock_batches';

    protected $fillable = [
        'product_variant_id', 'warehouse_code', 'batch_no', 'mfg_date', 'expiry_date',
        'unit_cost_paise', 'qty_on_hand', 'received_at', 'source_type', 'source_id',
        'expiry_alerted_at',
    ];

    protected function casts(): array
    {
        return [
            'product_variant_id' => 'int',
            'qty_on_hand' => 'int',
            'unit_cost_paise' => 'int',
            'source_id' => 'int',
            'mfg_date' => 'date',
            'expiry_date' => 'date',
            'received_at' => 'datetime',
            'expiry_alerted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_code', 'code');
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'stock_batch_id');
    }

    /**
     * Batches that may still be sold: something on hand, and not past expiry.
     * Expired stock is deliberately left visible rather than zeroed — it is
     * written off through an adjustment so the loss is recorded.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAllocatable(Builder $query): void
    {
        $query->where('qty_on_hand', '>', 0)
            ->where(function (Builder $q): void {
                $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString());
            });
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isBefore(now()->startOfDay());
    }
}
