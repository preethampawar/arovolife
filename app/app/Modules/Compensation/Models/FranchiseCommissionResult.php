<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Commerce\Models\Franchise;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One franchise's commission for one month.
 *
 * The rate is snapshotted so a later plan edit cannot restate what was paid,
 * and `order_count` / `base_paise` are stored so "which sales was this
 * commission on?" stays answerable — hard rule 2 requires every credit to
 * trace to product sales, and a figure nobody can decompose is not a trace.
 *
 * @property int $id
 * @property int $franchise_id
 * @property int $distributor_id
 * @property \Illuminate\Support\Carbon $month_start
 * @property int $order_count
 * @property int $base_paise
 * @property int $rate_bp
 * @property int $gross_paise
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $credited_at
 * @property-read Franchise $franchise
 * @property-read Distributor $distributor
 */
final class FranchiseCommissionResult extends Model
{
    protected $table = 'franchise_commission_results';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CREDITED = 'credited';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'franchise_id', 'distributor_id', 'month_start',
        'order_count', 'base_paise', 'rate_bp', 'gross_paise',
        'status', 'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'month_start' => 'date',
            'order_count' => 'integer',
            'base_paise' => 'integer',
            'rate_bp' => 'integer',
            'gross_paise' => 'integer',
            'credited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Franchise, $this> */
    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
