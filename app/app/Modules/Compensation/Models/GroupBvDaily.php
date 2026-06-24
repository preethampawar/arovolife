<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property Carbon $date
 * @property int $left_bv_paise
 * @property int $right_bv_paise
 */
final class GroupBvDaily extends Model
{
    public $timestamps = false;

    protected $table = 'group_bv_daily';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'left_bv_paise' => 'integer',
            'right_bv_paise' => 'integer',
        ];
    }
}
