<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $status
 * @property string|null $image_s3_key
 * @property string|null $banner_s3_key
 * @property string|null $banner_external_url
 */
final class ProductCategory extends Model
{
    protected $table = 'product_categories';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'slug', 'name', 'parent_id', 'description', 'image_s3_key',
        'banner_s3_key', 'banner_external_url', 'sort', 'status',
    ];

    protected function casts(): array
    {
        return [
            'parent_id' => 'int',
            'sort' => 'int',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Public URL for the category tile image (on the `s3` disk), or null.
     */
    public function imageUrl(): ?string
    {
        return $this->image_s3_key !== null
            ? Storage::disk('s3')->url($this->image_s3_key)
            : null;
    }

    /**
     * Public URL for the wide category banner (external URL wins, else the S3
     * object), or null when none is set.
     */
    public function bannerUrl(): ?string
    {
        if (! empty($this->banner_external_url)) {
            return $this->banner_external_url;
        }

        return $this->banner_s3_key !== null
            ? Storage::disk('s3')->url($this->banner_s3_key)
            : null;
    }
}
