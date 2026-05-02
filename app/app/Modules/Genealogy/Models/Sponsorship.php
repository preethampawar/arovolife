<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Sponsorship extends Model
{
    public $timestamps = false;

    protected $table = 'sponsorship';

    protected $fillable = [
        'sponsor_id',
        'distributor_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'sponsor_id');
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id');
    }
}
