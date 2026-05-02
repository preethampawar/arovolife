<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Setting extends Model
{
    protected $table = 'settings';

    protected $fillable = [
        'key',
        'value',
        'version',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
