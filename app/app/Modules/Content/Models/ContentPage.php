<?php

declare(strict_types=1);

namespace App\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string|null $type
 * @property string|null $category
 * @property int $sort_order
 * @property string $title
 * @property string|null $body
 * @property string|null $meta_description
 * @property string $status
 * @property Carbon|null $published_at
 * @property int|null $updated_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ContentPage extends Model
{
    protected $table = 'content_pages';

    /**
     * The FAQ library's page type. Named here rather than repeated as a
     * string: the type is what the listing, the editor's allow-list and the
     * feature flag all key off, and three copies of 'faq' is three chances to
     * mistype one.
     */
    public const TYPE_FAQ = 'faq';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'slug',
        'type',
        'category',
        'sort_order',
        'title',
        'body',
        'meta_description',
        'status',
        'published_at',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
