<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OrderItem extends Model
{
    protected $table = 'order_items';

    public $timestamps = false;

    protected $fillable = [
        'order_id', 'product_variant_id',
        'product_name_snapshot', 'variant_sku_snapshot', 'hsn_code_snapshot',
        'qty', 'unit_price_paise', 'bv_paise', 'pv_paise', 'gst_rate_bp',
        'taxable_value_paise', 'gst_paise', 'line_total_paise',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'int',
            'unit_price_paise' => 'int',
            'bv_paise' => 'int',
            'pv_paise' => 'int',
            'gst_rate_bp' => 'int',
            'taxable_value_paise' => 'int',
            'gst_paise' => 'int',
            'line_total_paise' => 'int',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
