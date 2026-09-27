<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A GSB cut-off day the full nightly run could not judge, owed to the
 * distributor until the next full run backfills it (E5 redesign).
 *
 * Written only by the full cut-off; resolved only by a settle that produced a
 * result row — the backfill, or a `--force` by-name run.
 *
 * @property int $id
 * @property int $distributor_id
 * @property Carbon $cutoff_date
 * @property string $cause
 * @property int|null $evaluate_run_id
 * @property int|null $reserved_slab
 * @property int $reserved_gsb_paise
 * @property int $reserved_msb_points
 * @property int|null $paid_gsb_paise
 * @property int|null $paid_msb_points
 * @property Carbon|null $exceeded_reservation_at
 * @property Carbon|null $resolved_at
 * @property string|null $resolution
 * @property int|null $gsb_cutoff_result_id
 * @property Carbon $created_at
 */
final class GsbCutoffDeferral extends Model
{
    public const CAUSE_EVALUATION_FAILED = 'evaluation_failed';

    /**
     * Evaluated cleanly, but an earlier owed day could not be backfilled
     * tonight: settling this day would advance the store past it.
     */
    public const CAUSE_EARLIER_DAY_OPEN = 'earlier_day_open';

    public const RESOLUTION_BACKFILLED = 'backfilled';

    public const RESOLUTION_MANUAL = 'manual';

    /**
     * The backfill found a later cut-off had already advanced the store: the
     * day closes with no result row of its own, audited and shown for a week.
     */
    public const RESOLUTION_SUPERSEDED = 'superseded';

    /** Closed by a human decision (`gsb:write-off-deferral`), never automatic. */
    public const RESOLUTION_WRITTEN_OFF = 'written_off';

    protected $fillable = [
        'distributor_id', 'cutoff_date', 'cause', 'evaluate_run_id',
        'reserved_slab', 'reserved_gsb_paise', 'reserved_msb_points',
        'paid_gsb_paise', 'paid_msb_points', 'exceeded_reservation_at',
        'resolved_at', 'resolution', 'gsb_cutoff_result_id',
    ];

    protected function casts(): array
    {
        return [
            'distributor_id' => 'integer',
            'cutoff_date' => 'date',
            'evaluate_run_id' => 'integer',
            'reserved_slab' => 'integer',
            'reserved_gsb_paise' => 'integer',
            'reserved_msb_points' => 'integer',
            'paid_gsb_paise' => 'integer',
            'paid_msb_points' => 'integer',
            'exceeded_reservation_at' => 'datetime',
            'resolved_at' => 'datetime',
            'gsb_cutoff_result_id' => 'integer',
        ];
    }

    /**
     * @param  Builder<GsbCutoffDeferral>  $query
     * @return Builder<GsbCutoffDeferral>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Backfilled on or after $since for more than the deferring night reserved.
     *
     * @param  Builder<GsbCutoffDeferral>  $query
     * @return Builder<GsbCutoffDeferral>
     */
    public function scopeExceededSince(Builder $query, Carbon $since): Builder
    {
        return $query->where('exceeded_reservation_at', '>=', $since);
    }

    /**
     * Resolved as superseded on or after $since.
     *
     * @param  Builder<GsbCutoffDeferral>  $query
     * @return Builder<GsbCutoffDeferral>
     */
    public function scopeSupersededSince(Builder $query, Carbon $since): Builder
    {
        return $query->where('resolution', self::RESOLUTION_SUPERSEDED)
            ->where('resolved_at', '>=', $since);
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /** @return BelongsTo<GsbCutoffResult, $this> */
    public function result(): BelongsTo
    {
        return $this->belongsTo(GsbCutoffResult::class, 'gsb_cutoff_result_id');
    }
}
