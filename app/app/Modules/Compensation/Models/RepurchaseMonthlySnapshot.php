<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One distributor's repurchase wallet balance as it stood at the end of a
 * calendar month — the frozen answer every bonus engine reads when it asks
 * "was this distributor's repurchase wallet spent down to ₹0 for that month?".
 *
 * @property int $id
 * @property int $distributor_id
 * @property Carbon $cycle_month
 * @property int $balance_paise
 * @property bool $was_zeroed
 * @property Carbon $snapshotted_at
 * @property Carbon|null $created_at
 */
final class RepurchaseMonthlySnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'distributor_id',
        'cycle_month',
        'balance_paise',
        'was_zeroed',
        'snapshotted_at',
    ];

    protected function casts(): array
    {
        return [
            'cycle_month' => 'date',
            'balance_paise' => 'integer',
            'was_zeroed' => 'boolean',
            'snapshotted_at' => 'datetime',
        ];
    }
}
