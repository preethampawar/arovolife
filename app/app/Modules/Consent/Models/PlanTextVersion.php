<?php

declare(strict_types=1);

namespace App\Modules\Consent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $doc_hash_sha256 raw 32-byte SHA-256 of the published body
 * @property string|null $label e.g. "v2026-10-27", read from the page footer
 * @property Carbon $notice_started_at
 * @property Carbon $created_at
 */
final class PlanTextVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'plan_text_versions';

    protected $fillable = [
        'doc_hash_sha256',
        'label',
        'notice_started_at',
    ];

    protected $hidden = ['doc_hash_sha256'];

    protected function casts(): array
    {
        return [
            'notice_started_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
