<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property string $type
 * @property int $amount_paise
 * @property int|null $reference_id
 * @property string|null $reference_type
 * @property Carbon|null $bonus_month
 * @property Carbon|null $earned_on
 * @property string|null $memo
 * @property int|null $swept_by_payout_batch_id
 * @property int|null $engine_run_id
 * @property Carbon $created_at
 */
final class WalletLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'wallet_ledger_entries';

    protected $fillable = [
        'distributor_id', 'type', 'amount_paise',
        'reference_id', 'reference_type', 'bonus_month', 'earned_on', 'memo',
        'swept_by_payout_batch_id', 'engine_run_id',
    ];

    /**
     * `bonus_month` and `earned_on` ARE date-cast, unlike the `month_start`
     * columns on the pool models: those are compared straight against a 'Y-m-d'
     * string in a plain where(), which the cast's 'Y-m-d 00:00:00' serialisation
     * would silently break. These columns are never used that way — they are
     * only ever read through whereDate(), exactly as RepurchaseCycle's date
     * columns are.
     */
    protected function casts(): array
    {
        return [
            'amount_paise' => 'integer',
            'bonus_month' => 'date',
            'earned_on' => 'date',
            'reference_id' => 'integer',
            'swept_by_payout_batch_id' => 'integer',
            'engine_run_id' => 'integer',
        ];
    }

    /**
     * Drop every entry belonging to a bonus an admin has reversed.
     *
     * A reversal writes a `reversal` debit against the SAME (reference_type,
     * reference_id) tuple as the credit it unwinds, and leaves the original
     * `+gross` credit and its `repurchase_transfer` debit in place so the
     * statement still shows what was earned and what was withheld. Nothing on
     * those two rows says the bonus is gone, so a payout batch — which selects
     * unswept credits by ledger type alone — would sweep them and wire the
     * money to the bank for a bonus that no longer exists, leaving the main
     * wallet permanently negative. They cannot be marked swept instead:
     * `swept_by_payout_batch_id` is a foreign key to a real batch, and no batch
     * paid them.
     *
     * Every query that decides what a payout batch may pay, and what a month's
     * income ceiling has already consumed, therefore goes through here. The
     * correlated lookup is a unique-index seek on
     * `uniq_wallet_ledger_source (type, reference_type, reference_id)`.
     *
     * Entries with no reference tuple (manual credits, payout debits) can never
     * have been reversed, and the NULL comparison keeps them in.
     *
     * @param  Builder<WalletLedgerEntry>  $query
     */
    #[Scope]
    protected function notReversed(Builder $query): void
    {
        $query->whereNotExists(function (QueryBuilder $reversals): void {
            $reversals->selectRaw('1')
                ->from('wallet_ledger_entries as reversal_entries')
                ->where('reversal_entries.type', 'reversal')
                ->whereColumn('reversal_entries.reference_type', 'wallet_ledger_entries.reference_type')
                ->whereColumn('reversal_entries.reference_id', 'wallet_ledger_entries.reference_id');
        });
    }

    /**
     * Rows earned on or before `$date` — the day filter the weekly payout's
     * earning week is expressed through.
     *
     * WHICH day that is belongs to {@see PayoutBatch::weeklyEarningWindow()};
     * this scope only knows how to compare against it, so the three weekly
     * queries and the repurchase-transfer sweep cannot drift apart.
     *
     * A null `earned_on` passes. Those are the rows written before the column
     * existed and the monthly streams, which have no earning day at all: both
     * keep exactly the behaviour they had before the week rule arrived, rather
     * than being stranded unpaid by a filter that can never match them.
     *
     * @param  Builder<WalletLedgerEntry>  $query
     */
    #[Scope]
    protected function earnedOnOrBefore(Builder $query, Carbon $date): void
    {
        $query->where(function (Builder $window) use ($date): void {
            $window->whereDate('earned_on', '<=', $date)->orWhereNull('earned_on');
        });
    }

    /**
     * The engine run that wrote this entry, or null when it was written outside
     * one (order-time repurchase entries, manual admin credits).
     *
     * @return BelongsTo<EngineRun, $this>
     */
    public function engineRun(): BelongsTo
    {
        return $this->belongsTo(EngineRun::class, 'engine_run_id');
    }
}
