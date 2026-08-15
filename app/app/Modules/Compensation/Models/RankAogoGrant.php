<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One use of the AO-GO ("Achieve Once – Get Once") offer (KP 2026-08-05):
 * a degraded ex-rank-holder earns a fixed number of points in the Rank-1 pool
 * for the month. Max `comp.rank.aogo_lifetime_max` uses per distributor, never
 * in consecutive months, and a rank must be re-achieved between uses.
 *
 * @property int $id
 * @property int $distributor_id
 * @property string $month_start
 * @property int $grant_number
 * @property int $points
 * @property int $previous_rank_number
 * @property int|null $point_value_paise
 * @property int|null $income_paise
 * @property string $status
 * @property Carbon|null $credited_at
 */
final class RankAogoGrant extends Model
{
    public const string STATUS_GRANTED = 'granted';

    public const string STATUS_CREDITED = 'credited';

    public const string STATUS_VOIDED = 'voided';

    protected $fillable = [
        'distributor_id',
        'month_start',
        'grant_number',
        'points',
        'previous_rank_number',
        'point_value_paise',
        'income_paise',
        'status',
        'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'grant_number' => 'int',
            'points' => 'int',
            'previous_rank_number' => 'int',
            'point_value_paise' => 'int',
            'income_paise' => 'int',
            'credited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /**
     * A use of the offer that still counts: everything except voided grants.
     * The single definition of "used" — the lifetime counter, the
     * consecutive-month rule and every display of the offer read through here,
     * so a voided grant can never count in one place and not another.
     *
     * @param  Builder<RankAogoGrant>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('status', '!=', self::STATUS_VOIDED);
    }
}
