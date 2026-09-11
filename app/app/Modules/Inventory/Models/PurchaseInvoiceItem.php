<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One batch of one variant on a supplier invoice.
 *
 * Purchase lines are tax-exclusive: `taxable_value = qty × unit_cost`, and the
 * GST sits on top. The catalogue works the other way round (prices include
 * GST), which is why the two must never share a calculator.
 *
 * @property int $id
 * @property int $purchase_invoice_id
 * @property int $product_variant_id
 * @property string $batch_no
 * @property int $qty
 * @property int $unit_cost_paise
 * @property int $gst_rate_bp
 * @property int $taxable_value_paise
 * @property int $gst_paise
 * @property int $line_total_paise
 */
final class PurchaseInvoiceItem extends Model
{
    protected $table = 'purchase_invoice_items';

    protected $fillable = [
        'purchase_invoice_id', 'product_variant_id', 'batch_no', 'mfg_date', 'expiry_date',
        'qty', 'unit_cost_paise', 'gst_rate_bp', 'taxable_value_paise', 'gst_paise', 'line_total_paise',
    ];

    protected function casts(): array
    {
        return [
            'purchase_invoice_id' => 'int',
            'product_variant_id' => 'int',
            'qty' => 'int',
            'unit_cost_paise' => 'int',
            'gst_rate_bp' => 'int',
            'taxable_value_paise' => 'int',
            'gst_paise' => 'int',
            'line_total_paise' => 'int',
            'mfg_date' => 'date',
            'expiry_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PurchaseInvoice, $this> */
    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
