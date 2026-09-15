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
 * @property int $gst_rate_bp
 * @property string $inventory_policy
 * @property string $status
 */
final class ProductVariant extends Model
{
    protected $table = 'product_variants';

    protected $fillable = [
        'product_id', 'variant_sku', 'name', 'attributes',
        'weight_g', 'mrp_paise', 'sale_price_paise', 'cost_paise',
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

    public function displayPrice(): string
    {
        return IndianNumber::rupees($this->sale_price_paise);
    }

    public function displayMrp(): string
    {
        return IndianNumber::rupees($this->mrp_paise);
    }

    /**
     * The distributor price tier — shown ONLY to authenticated distributors
     * (after-login pricing). It is a factual catalogue price, never an
     * earnings figure (hard rule #3).
     */
    public function hasDistributorPrice(): bool
    {
        return $this->distributor_price_paise > 0 && $this->distributor_price_paise < $this->sale_price_paise;
    }

    public function displayDistributorPrice(): string
    {
        return IndianNumber::rupees($this->distributor_price_paise);
    }

    /**
     * The price paid for one unit at the buyer's tier.
     *
     * A logged-in Direct Seller pays the distributor price wherever the
     * catalogue sets one below the sale price — the tier their product page
     * already shows them (client decision 2026-09-11, QA F55). Everyone else
     * pays the sale price. BV is untouched: it is a property of the SKU, not
     * of the price paid.
     */
    public function priceForTierPaise(bool $isDistributor): int
    {
        return ($isDistributor && $this->hasDistributorPrice())
            ? $this->distributor_price_paise
            : $this->sale_price_paise;
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
