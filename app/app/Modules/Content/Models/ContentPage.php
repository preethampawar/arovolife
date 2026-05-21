<?php

declare(strict_types=1);

namespace App\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
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

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'slug',
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
