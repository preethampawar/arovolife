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
        'product_id', 's3_key', 'external_url', 'alt', 'sort', 'kind',
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
     * Public URL for the image. An externally-hosted image returns its URL
     * verbatim; an uploaded image is served from the public `s3` disk
     * (catalog images are not PII, unlike KYC documents). Returns '' for a
     * row with neither — a defensive guard that never reaches a real row.
     */
    public function url(): string
    {
        if (! empty($this->external_url)) {
            return $this->external_url;
        }

        // Private s3 bucket → signed, time-limited URL (a plain ->url() 403s).
        return $this->s3_key !== null ? Storage::disk('s3')->temporaryUrl($this->s3_key, now()->addDay()) : '';
    }
}
