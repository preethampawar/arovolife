<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Product extends Model
{
    protected $table = 'products';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'sku', 'slug', 'name', 'short_description', 'description',
        'category', 'hsn_code', 'image_url', 'status', 'created_by_user_id',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
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
