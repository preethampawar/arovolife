<?php

declare(strict_types=1);

namespace App\Modules\Consent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property string $version
 * @property string|null $pdf_hash
 * @property Carbon $effective_from
 * @property int|null $supersedes_id
 */
final class Agreement extends Model
{
    public $timestamps = false;

    protected $table = 'agreements';

    protected $fillable = [
        'type',
        'version',
        'pdf_hash',
        'effective_from',
        'supersedes_id',
    ];

    protected $hidden = ['pdf_hash'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
        ];
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }
}
