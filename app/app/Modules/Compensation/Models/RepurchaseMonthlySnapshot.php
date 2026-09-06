<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One distributor's repurchase wallet balance as it stood at the end of a
 * calendar month — the frozen answer every bonus engine reads when it asks
 * "was this distributor's repurchase wallet spent down to ₹0 for that month?".
 *
 * Append-only, exactly like {@see GbbMonthlyPool} and {@see FortuneMonthlyPool}:
 * Fortune, Growth Booster and Rank all gate on these rows, so moving one
 * retroactively changes who was eligible in a month that has already been paid.
 * A month is written once and re-runs leave it alone; the one way back is
 * RepurchaseMonthlySnapshotCommand's replace path, which DELETES the month and
 * freezes it afresh rather than editing rows in place.
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

    /**
     * Enforced here, not just by discipline in the command: an `updateOrCreate`
     * anywhere else would silently re-price a closed month's eligibility.
     */
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new LogicException('repurchase_monthly_snapshots rows are frozen — a closed month\'s wallet balance is never recomputed.');
        });
    }

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
