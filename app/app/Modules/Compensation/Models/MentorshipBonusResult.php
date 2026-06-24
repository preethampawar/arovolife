<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sponsor_id
 * @property int $sponsee_id
 * @property Carbon $cutoff_date
 * @property int $sponsee_gsb_paise
 * @property int $mb_rate_pct
 * @property int $mb_paise
 * @property int $sponsee_cumulative_gsb_paise
 * @property string $status
 * @property string|null $failure_reason
 */
final class MentorshipBonusResult extends Model
{
    protected $table = 'mentorship_bonus_results';

    protected $fillable = [
        'sponsor_id', 'sponsee_id', 'cutoff_date',
        'sponsee_gsb_paise', 'mb_rate_pct', 'mb_paise', 'sponsee_cumulative_gsb_paise',
        'status', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'sponsee_gsb_paise' => 'integer',
            'mb_rate_pct' => 'integer',
            'mb_paise' => 'integer',
            'sponsee_cumulative_gsb_paise' => 'integer',
        ];
    }
}
