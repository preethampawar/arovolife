<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CartItem extends Model
{
    protected $table = 'cart_items';

    protected $fillable = [
        'cart_id', 'product_variant_id', 'qty',
        'unit_price_paise', 'bv_paise', 'gst_rate_bp',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'int',
            'unit_price_paise' => 'int',
            'bv_paise' => 'int',
            'gst_rate_bp' => 'int',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function lineTotalPaise(): int
    {
        return $this->qty * $this->unit_price_paise;
    }
}
