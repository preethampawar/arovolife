<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $adjustment_no
 * @property string $warehouse_code
 * @property int $product_variant_id
 * @property int|null $stock_batch_id
 * @property int $qty_delta
 * @property string $reason
 * @property string $notes
 */
final class StockAdjustment extends Model
{
    public const UPDATED_AT = null;

    public const REASON_COUNT_CORRECTION = 'count_correction';

    public const REASON_DAMAGED = 'damaged';

    public const REASON_EXPIRED = 'expired';

    public const REASON_THEFT_LOSS = 'theft_loss';

    public const REASON_SAMPLE = 'sample';

    public const REASON_OTHER = 'other';

    /** @var list<string> */
    public const REASONS = [
        self::REASON_COUNT_CORRECTION,
        self::REASON_DAMAGED,
        self::REASON_EXPIRED,
        self::REASON_THEFT_LOSS,
        self::REASON_SAMPLE,
        self::REASON_OTHER,
    ];

    /**
     * Reasons that mean the goods are gone rather than miscounted; these post
     * a `write_off` movement instead of `adjustment_out`.
     *
     * @var list<string>
     */
    public const WRITE_OFF_REASONS = [
        self::REASON_DAMAGED,
        self::REASON_EXPIRED,
        self::REASON_THEFT_LOSS,
    ];

    protected $table = 'stock_adjustments';

    protected $fillable = [
        'adjustment_no', 'warehouse_code', 'product_variant_id', 'stock_batch_id',
        'qty_delta', 'reason', 'notes', 'actor_user_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'product_variant_id' => 'int',
            'stock_batch_id' => 'int',
            'qty_delta' => 'int',
            'actor_user_id' => 'int',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
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

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_code', 'code');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
