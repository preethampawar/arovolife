<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DistributorProfile extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'distributor_id',
        'gender',
        'marital_status',
        'highest_education',
        'occupation',
        'mother_tongue',
        'additional_language_1',
        'additional_language_2',
    ];

    protected function casts(): array
    {
        return [
            'gender' => 'string',
            'marital_status' => 'string',
            'highest_education' => 'string',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
