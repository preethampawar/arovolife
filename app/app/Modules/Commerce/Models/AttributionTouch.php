<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

final class AttributionTouch extends Model
{
    protected $table = 'attribution_touches';

    public $timestamps = false;

    protected $fillable = [
        'anonymous_key', 'ref_adn', 'distributor_id',
        'landing_url', 'ip_hash', 'user_agent', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
