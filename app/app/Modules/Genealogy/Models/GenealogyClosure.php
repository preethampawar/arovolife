<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GenealogyClosure extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'genealogy_closure';

    protected $fillable = [
        'ancestor_id',
        'descendant_id',
        'depth',
    ];

    protected function casts(): array
    {
        return [
            'ancestor_id' => 'integer',
            'descendant_id' => 'integer',
            'depth' => 'integer',
        ];
    }

    public function ancestor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'ancestor_id');
    }

    public function descendant(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'descendant_id');
    }
}
