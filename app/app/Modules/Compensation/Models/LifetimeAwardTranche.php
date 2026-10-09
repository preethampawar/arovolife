<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One merchandise tranche of a rank's Lifetime Award (client 2026-10-09):
 * tranche 1 (A) is released on the rank's 1st qualification, 2 (B) on the 2nd,
 * 3 (C) on the 3rd. A rank's tranches sum to its award budget.
 *
 * @property int $id
 * @property int $rank_number
 * @property int $tranche
 * @property int $amount_paise
 */
final class LifetimeAwardTranche extends Model
{
    protected $fillable = [
        'rank_number',
        'tranche',
        'amount_paise',
    ];

    protected function casts(): array
    {
        return [
            'rank_number' => 'int',
            'tranche' => 'int',
            'amount_paise' => 'int',
        ];
    }
}
