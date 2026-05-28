<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use App\Modules\Identity\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A compliance document uploaded by an admin and published for public
 * viewing / download (e.g. DSR registration certificate, policies,
 * statutory filings). The file itself lives on the private disk and is
 * streamed through a controller route — never served from the web root.
 *
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string $file_path
 * @property string $original_name
 * @property string|null $mime
 * @property int $size_bytes
 * @property bool $is_published
 * @property int|null $uploaded_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ComplianceDocument extends Model
{
    protected $fillable = [
        'title',
        'description',
        'file_path',
        'original_name',
        'mime',
        'size_bytes',
        'is_published',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'size_bytes' => 'integer',
        ];
    }

    /** @param Builder<ComplianceDocument> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function humanSize(): string
    {
        $bytes = $this->size_bytes;
        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 1).' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0).' KB';
        }

        return $bytes.' B';
    }
}
