<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Shared\Support\IndianNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $variant_sku
 * @property int $cost_paise
 * @property int $gst_rate_bp
 * @property string $inventory_policy
 * @property-read InventoryLevel|null $inventory
 */
/**
 * @property int $id
 * @property int $product_id
 * @property string $variant_sku
 * @property string $name
 * @property int $mrp_paise
 * @property int $sale_price_paise
 * @property int $cost_paise
 * @property int $landing_price_paise
 * @property int $distributor_price_paise
 * @property int $bv_paise
 * @property int $weight_g
 * @property int|null $length_mm
 * @property int|null $breadth_mm
 * @property int|null $height_mm
 * @property int $gst_rate_bp
 * @property string $inventory_policy
 * @property string $status
 */
final class ProductVariant extends Model
{
    protected $table = 'product_variants';

    protected $fillable = [
        'product_id', 'variant_sku', 'name', 'attributes',
        'weight_g', 'length_mm', 'breadth_mm', 'height_mm', 'mrp_paise', 'sale_price_paise', 'cost_paise',
        'landing_price_paise', 'distributor_price_paise',
        'bv_paise', 'gst_rate_bp', 'inventory_policy', 'status',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'mrp_paise' => 'int',
            'sale_price_paise' => 'int',
            'cost_paise' => 'int',
            'landing_price_paise' => 'int',
            'distributor_price_paise' => 'int',
            'bv_paise' => 'int',
            'weight_g' => 'int',
            'length_mm' => 'int',
            'breadth_mm' => 'int',
            'height_mm' => 'int',
            'gst_rate_bp' => 'int',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasOne<InventoryLevel, $this> */
    public function inventory(): HasOne
    {
        return $this->hasOne(InventoryLevel::class, 'product_variant_id');
    }

    /**
     * The distributor price tier — shown ONLY to authenticated distributors
     * (after-login pricing). It is a factual catalogue price, never an
     * earnings figure (hard rule #3). Never above MRP.
     */
    public function hasDistributorPrice(): bool
    {
        return $this->distributor_price_paise > 0 && $this->distributor_price_paise < $this->mrp_paise;
    }

    /**
     * The price paid for one unit at the buyer's tier.
     *
     * A logged-in Direct Seller sees and pays the distributor price; everyone
     * else sees and pays MRP. A SKU with no distributor price falls back to
     * MRP for distributors too (client decision 2026-09-26). The sale price
     * is not used on the storefront. BV is untouched: it is a property of the
     * SKU, not of the price paid.
     */
    public function priceForTierPaise(bool $isDistributor): int
    {
        return ($isDistributor && $this->hasDistributorPrice())
            ? $this->distributor_price_paise
            : $this->mrp_paise;
    }

    public function displayPriceForTier(bool $isDistributor): string
    {
        return IndianNumber::rupees($this->priceForTierPaise($isDistributor));
    }
}
