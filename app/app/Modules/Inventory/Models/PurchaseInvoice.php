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
 * @property int $freight_paise
 * @property int $insurance_paise
 * @property int $handling_paise
 * @property int $other_charges_paise
 * @property int $landed_total_paise
 * @property string $allocation_basis
 * @property int $gst_paise
 * @property int $total_paise
 * @property CarbonInterface|null $posted_at
 * @property CarbonInterface $created_at
 */
final class PurchaseInvoice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'purchase_invoices';

    /**
     * The DB defaults these to 0, but a model built by create() and used
     * before it is re-read has no value for them at all — and NULL in the
     * landed-cost arithmetic silently becomes 0 charges on a GRN that had
     * charges. Defaulting on the model closes that window.
     */
    protected $attributes = [
        'freight_paise' => 0,
        'insurance_paise' => 0,
        'handling_paise' => 0,
        'other_charges_paise' => 0,
        'landed_total_paise' => 0,
        'allocation_basis' => 'value',
    ];

    protected $fillable = [
        'grn_no', 'supplier_id', 'purchase_order_id', 'warehouse_code',
        'supplier_invoice_no', 'supplier_invoice_date', 'status',
        'subtotal_paise', 'gst_paise', 'total_paise', 'notes',
        'freight_paise', 'insurance_paise', 'handling_paise', 'other_charges_paise',
        'landed_total_paise', 'allocation_basis',
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
            'freight_paise' => 'int',
            'insurance_paise' => 'int',
            'handling_paise' => 'int',
            'other_charges_paise' => 'int',
            'landed_total_paise' => 'int',
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
