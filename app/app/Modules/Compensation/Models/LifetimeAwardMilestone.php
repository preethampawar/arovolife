<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property int $rank_number
 * @property int $tranche
 * @property int $amount_paise
 * @property Carbon $triggered_month
 * @property Carbon|null $achieved_month
 * @property int|null $rank_qualification_id
 * @property int|null $personal_bv_paise
 * @property int|null $left_genos_bv_paise
 * @property int|null $right_genos_bv_paise
 * @property int $qualification_count
 * @property string $award_description
 * @property string $status
 * @property Carbon|null $credited_at
 * @property Carbon|null $released_rule_changed_at
 * @property Carbon|null $delivered_at
 * @property string|null $notes
 */
final class LifetimeAwardMilestone extends Model
{
    /** Earned, not yet credited to the wallet. */
    public const string STATUS_PENDING = 'pending';

    /** The awards_credit wallet row is written; the monthly payout on the 8th pays it (client 2026-11-10). */
    public const string STATUS_CREDITED = 'credited';

    /** Merchandise handed over under the retired goods plan (before 2026-11-10). Never paid in cash. */
    public const string STATUS_DELIVERED = 'delivered';

    public const string STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'distributor_id',
        'rank_number',
        'tranche',
        'amount_paise',
        'triggered_month',
        'achieved_month',
        'rank_qualification_id',
        'personal_bv_paise',
        'left_genos_bv_paise',
        'right_genos_bv_paise',
        'qualification_count',
        'award_description',
        'status',
        'credited_at',
        'released_rule_changed_at',
        'delivered_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'rank_number' => 'int',
            'tranche' => 'int',
            'amount_paise' => 'int',
            'triggered_month' => 'date',
            'achieved_month' => 'date',
            'rank_qualification_id' => 'int',
            'personal_bv_paise' => 'int',
            'left_genos_bv_paise' => 'int',
            'right_genos_bv_paise' => 'int',
            'qualification_count' => 'int',
            'credited_at' => 'datetime',
            'delivered_at' => 'datetime',
            'released_rule_changed_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /**
     * The award's cash credit in the wallet (client 2026-11-10); null while
     * pending, and for merchandise rows of the retired plan.
     *
     * @return HasOne<WalletLedgerEntry, $this>
     */
    public function walletCredit(): HasOne
    {
        return $this->hasOne(WalletLedgerEntry::class, 'reference_id')
            ->where('type', 'awards_credit')
            ->where('reference_type', 'lifetime_award_milestone');
    }
}
