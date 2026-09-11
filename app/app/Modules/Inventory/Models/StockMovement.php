<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only line of the stock ledger. Written by `StockLedger::post()`
 * and by nothing else; never updated, never deleted.
 *
 * @property int $id
 * @property int $product_variant_id
 * @property string $warehouse_code
 * @property int|null $stock_batch_id
 * @property string $type
 * @property int $qty
 * @property int $unit_cost_paise
 * @property string|null $reference_type
 * @property int|null $reference_id
 */
final class StockMovement extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_PURCHASE_IN = 'purchase_in';

    public const TYPE_PURCHASE_REVERSAL = 'purchase_reversal';

    public const TYPE_SALE_OUT = 'sale_out';

    public const TYPE_SALE_REVERSAL = 'sale_reversal';

    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    public const TYPE_RETURN_IN = 'return_in';

    public const TYPE_ADJUSTMENT_IN = 'adjustment_in';

    public const TYPE_ADJUSTMENT_OUT = 'adjustment_out';

    public const TYPE_WRITE_OFF = 'write_off';

    public const TYPE_OPENING = 'opening';

    /**
     * Every value the `type` column accepts, in the order the migration
     * declares them.
     *
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_PURCHASE_IN,
        self::TYPE_PURCHASE_REVERSAL,
        self::TYPE_SALE_OUT,
        self::TYPE_SALE_REVERSAL,
        self::TYPE_TRANSFER_OUT,
        self::TYPE_TRANSFER_IN,
        self::TYPE_RETURN_IN,
        self::TYPE_ADJUSTMENT_IN,
        self::TYPE_ADJUSTMENT_OUT,
        self::TYPE_WRITE_OFF,
        self::TYPE_OPENING,
    ];

    protected $table = 'stock_movements';

    protected $fillable = [
        'product_variant_id', 'warehouse_code', 'stock_batch_id', 'type', 'qty',
        'unit_cost_paise', 'reference_type', 'reference_id', 'reason',
        'actor_user_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'product_variant_id' => 'int',
            'stock_batch_id' => 'int',
            'qty' => 'int',
            'unit_cost_paise' => 'int',
            'reference_id' => 'int',
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
