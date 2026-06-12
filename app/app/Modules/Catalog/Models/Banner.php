<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A storefront shopping-mall carousel banner. The image is EITHER an uploaded
 * S3 object (`s3_key`) OR an external URL (`external_url`) — same convention as
 * {@see ProductImage}.
 *
 * @property int $id
 * @property string|null $title
 * @property string|null $caption
 * @property string|null $link_url
 * @property string|null $s3_key
 * @property string|null $external_url
 * @property int $sort
 * @property string $status
 */
final class Banner extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'banners';

    protected $fillable = [
        'title', 'caption', 'link_url', 's3_key', 'external_url', 'sort', 'status',
    ];

    protected function casts(): array
    {
        return ['sort' => 'int'];
    }

    /** The displayable image URL — external URL wins, else the S3 object. */
    public function url(): string
    {
        if (! empty($this->external_url)) {
            return $this->external_url;
        }

        // Catalog images live on the PRIVATE s3 bucket (no public ACL/policy),
        // so they're served via a signed, time-limited URL — same as KYC/ID
        // photos. A plain ->url() would 403 in the browser.
        return $this->s3_key !== null ? Storage::disk('s3')->temporaryUrl($this->s3_key, now()->addDay()) : '';
    }

    public function hasImage(): bool
    {
        return ! empty($this->external_url) || $this->s3_key !== null;
    }

    /**
     * Active banners with an image, in display order (for the storefront carousel).
     *
     * @param  Builder<Banner>  $query
     */
    #[Scope]
    protected function displayable(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE)
            ->where(function (Builder $w): void {
                $w->whereNotNull('s3_key')->orWhereNotNull('external_url');
            })
            ->orderBy('sort')
            ->orderByDesc('id');
    }
}
