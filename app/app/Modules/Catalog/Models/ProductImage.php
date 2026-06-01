<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

final class ProductImage extends Model
{
    protected $table = 'product_images';

    public const KIND_GALLERY = 'gallery';

    public const KIND_INLINE = 'inline';

    protected $fillable = [
        'product_id', 's3_key', 'alt', 'sort', 'kind',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'int',
            'sort' => 'int',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Public URL for the stored image (catalog images live on the public
     * `s3` disk — they are not PII, unlike KYC documents).
     */
    public function url(): string
    {
        return Storage::disk('s3')->url($this->s3_key);
    }
}
