<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The supplier invoice and the goods receipt note are the same document (§10).
 * Once posted it is immutable: a mistake is undone by cancelling it, which
 * writes reversing movements rather than deleting anything.
 *
 * @property int $id
 * @property string $grn_no
 * @property int $supplier_id
 * @property int|null $purchase_order_id
 * @property string $warehouse_code
 * @property string $status
 * @property int $subtotal_paise
 * @property int $gst_paise
 * @property int $total_paise
 * @property CarbonInterface|null $posted_at
 */
final class PurchaseInvoice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'purchase_invoices';

    protected $fillable = [
        'grn_no', 'supplier_id', 'purchase_order_id', 'warehouse_code',
        'supplier_invoice_no', 'supplier_invoice_date', 'status',
        'subtotal_paise', 'gst_paise', 'total_paise', 'notes',
        'posted_at', 'posted_by_user_id', 'cancelled_at', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'supplier_id' => 'int',
            'purchase_order_id' => 'int',
            'posted_by_user_id' => 'int',
            'created_by_user_id' => 'int',
            'subtotal_paise' => 'int',
            'gst_paise' => 'int',
            'total_paise' => 'int',
            'supplier_invoice_date' => 'date',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_code', 'code');
    }

    /** @return HasMany<PurchaseInvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
