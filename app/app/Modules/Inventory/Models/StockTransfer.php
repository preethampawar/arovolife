<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $transfer_no
 * @property string $from_warehouse_code
 * @property string $to_warehouse_code
 * @property string $status
 * @property CarbonInterface|null $dispatched_at
 * @property CarbonInterface|null $received_at
 * @property int|null $items_sum_qty set only after ->withSum('items', 'qty')
 */
final class StockTransfer extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'stock_transfers';

    protected $fillable = [
        'transfer_no', 'from_warehouse_code', 'to_warehouse_code', 'status', 'notes',
        'created_by_user_id', 'dispatched_by_user_id', 'received_by_user_id',
        'dispatched_at', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'created_by_user_id' => 'int',
            'dispatched_by_user_id' => 'int',
            'received_by_user_id' => 'int',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return HasMany<StockTransferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_code', 'code');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_code', 'code');
    }

    /** @return BelongsTo<User, $this> */
    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
}
