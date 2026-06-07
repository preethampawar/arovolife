<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $food_type
 */
final class Product extends Model
{
    protected $table = 'products';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const FOOD_VEG = 'veg';

    public const FOOD_NON_VEG = 'non_veg';

    protected $fillable = [
        'sku', 'slug', 'name', 'short_description', 'description', 'description_html',
        'category', 'category_id', 'manufacturer', 'country_of_origin', 'food_type',
        'hsn_code', 'image_url', 'status', 'created_by_user_id',
    ];

    /** True when this product carries a veg/non-veg mark (i.e. it's a food item). */
    public function hasFoodMark(): bool
    {
        return in_array($this->food_type, [self::FOOD_VEG, self::FOOD_NON_VEG], true);
    }

    public function isVeg(): bool
    {
        return $this->food_type === self::FOOD_VEG;
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * The category-master relation. Named `productCategory` (not `category`)
     * because a legacy string column `category` already occupies the
     * `category` attribute name and would shadow a relation of that name.
     */
    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort');
    }

    /**
     * Product-level descriptive attributes (ingredients, nutrition, storage,
     * …) ordered for display on the product detail page. Named
     * `productAttributes` (not `attributes`) to avoid colliding with
     * Eloquent's internal `$attributes` bag.
     */
    public function productAttributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class)->orderBy('sort')->orderBy('id');
    }

    public function galleryImages(): HasMany
    {
        return $this->images()->where('kind', ProductImage::KIND_GALLERY);
    }

    public function primaryVariant(): ?ProductVariant
    {
        return $this->variants()->where('status', 'active')->orderBy('id')->first();
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
