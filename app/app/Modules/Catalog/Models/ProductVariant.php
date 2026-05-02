<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class ProductVariant extends Model
{
    protected $table = 'product_variants';

    protected $fillable = [
        'product_id', 'variant_sku', 'name', 'attributes',
        'weight_g', 'mrp_paise', 'sale_price_paise', 'cost_paise',
        'bv_paise', 'pv_paise', 'gst_rate_bp', 'inventory_policy', 'status',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'mrp_paise' => 'int',
            'sale_price_paise' => 'int',
            'cost_paise' => 'int',
            'bv_paise' => 'int',
            'pv_paise' => 'int',
            'weight_g' => 'int',
            'gst_rate_bp' => 'int',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(InventoryLevel::class, 'product_variant_id');
    }

    public function displayPrice(): string
    {
        return '₹'.number_format($this->sale_price_paise / 100, 2);
    }

    public function displayMrp(): string
    {
        return '₹'.number_format($this->mrp_paise / 100, 2);
    }

    public function hasDiscount(): bool
    {
        return $this->sale_price_paise < $this->mrp_paise;
    }

    public function discountPercent(): int
    {
        if ($this->mrp_paise === 0) {
            return 0;
        }

        return (int) round((1 - $this->sale_price_paise / $this->mrp_paise) * 100);
    }
}
