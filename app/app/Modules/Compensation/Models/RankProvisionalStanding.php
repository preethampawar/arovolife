<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One distributor meeting one rank's conditions so far this month, as of
 * `as_of_date`. A progress figure, never a rank — see the migration.
 *
 * @property int $id
 * @property int $distributor_id
 * @property string $month_start
 * @property int $rank_number
 * @property Carbon $as_of_date
 */
final class RankProvisionalStanding extends Model
{
    protected $fillable = [
        'distributor_id',
        'month_start',
        'rank_number',
        'as_of_date',
    ];

    protected function casts(): array
    {
        return [
            'distributor_id' => 'int',
            'rank_number' => 'int',
            'as_of_date' => 'date',
        ];
    }
}
