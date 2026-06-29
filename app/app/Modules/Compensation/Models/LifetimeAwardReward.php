<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One itemised reward a rank earns under Lifetime Awards & Rewards.
 *
 * @property int $id
 * @property int $rank_number
 * @property int $sort_order
 * @property string $item
 * @property int $worth_paise
 */
final class LifetimeAwardReward extends Model
{
    protected $fillable = [
        'rank_number',
        'sort_order',
        'item',
        'worth_paise',
    ];

    protected function casts(): array
    {
        return [
            'rank_number' => 'int',
            'sort_order' => 'int',
            'worth_paise' => 'int',
        ];
    }
}
