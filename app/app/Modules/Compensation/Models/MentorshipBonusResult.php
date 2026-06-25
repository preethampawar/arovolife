<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sponsor_id
 * @property int $sponsee_id
 * @property Carbon $cutoff_date
 * @property int $sponsee_gsb_paise
 * @property int $mb_rate_pct
 * @property int $mb_gross_paise      Rate × sponsee GSB — before deductions.
 * @property int $mb_admin_charge_paise 3% of gross, capped at ₹30,000.
 * @property int $mb_tds_paise        5% of (gross − admin charge).
 * @property int $mb_paise            Net credited to sponsor wallet.
 * @property int $sponsee_cumulative_gsb_paise
 * @property string $status
 * @property string|null $failure_reason
 */
final class MentorshipBonusResult extends Model
{
    public const STATUS_CREDITED = 'credited';

    public const STATUS_FAILED = 'failed';

    protected $table = 'mentorship_bonus_results';

    protected $fillable = [
        'sponsor_id', 'sponsee_id', 'cutoff_date',
        'sponsee_gsb_paise', 'mb_rate_pct',
        'mb_gross_paise', 'mb_admin_charge_paise', 'mb_tds_paise', 'mb_paise',
        'sponsee_cumulative_gsb_paise', 'status', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'sponsee_gsb_paise' => 'integer',
            'mb_rate_pct' => 'integer',
            'mb_gross_paise' => 'integer',
            'mb_admin_charge_paise' => 'integer',
            'mb_tds_paise' => 'integer',
            'mb_paise' => 'integer',
            'sponsee_cumulative_gsb_paise' => 'integer',
        ];
    }

    public function sponsee(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'sponsee_id');
    }
}
