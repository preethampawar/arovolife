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
     * Friendly label for each `type`, keyed by the raw machine value. Shared
     * by the distributor-facing wallet ledger page and its CSV export so
     * neither one leaks the internal enum (e.g. `gsb_credit`) to a distributor.
     *
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            'gsb_credit' => 'Genos Sales Bonus',
            'mb_credit' => 'Mentorship Bonus',
            'gbb_credit' => 'Growth Booster Bonus',
            'rank_credit' => 'Rank Bonus',
            'fortune_credit' => 'Fortune Bonus',
            'adc_credit' => 'ADC Bonus',
            'payout_debit' => 'Payout to bank',
            'admin_charge_debit' => 'Admin charge',
            'tds_debit' => 'TDS (Tax Deducted at Source)',
            'repurchase_transfer' => 'Repurchase obligation (bonus deduction)',
            'income_cap_forfeit' => 'Monthly income cap',
            'manual_credit' => 'Manual adjustment',
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
     * `whereDate()` — not a plain `where('earned_on', '<=', ...)` — on purpose,
     * even though it costs the `idx_wallet_type_swept_earned` index a range
     * scan. `earned_on` is a MySQL DATE column, but Eloquent's `date` cast
     * writes it through `fromDateTime()`, so on the SQLite test database the
     * stored text is `Y-m-d 00:00:00`. A string comparison against `Y-m-d`
     * then excludes the boundary day (measured: 0 rows vs 1), which is a
     * silent under-sweep of a distributor's own money on exactly the day it
     * was earned. A DATE-typed comparison behaves the same on both engines;
     * a lexical one does not. If this ever needs to be sargable, normalise
     * what is written first — do not change only the comparison.
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
     * Rows a monthly payout batch for `$month` is due to settle — the monthly
     * counterpart of {@see earnedOnOrBefore()}.
     *
     * The monthly batch used to have no earning window at all: it swept every
     * unswept row, so the batch for August paid income earned in September as
     * well, while the engine-completion gate only ever certified August (QA
     * F48). The window closes that gap.
     *
     * Three readings of "when was this earned", in order of authority:
     *
     *   bonus_month — the month the income was EARNED for, which is what the
     *                 monthly engines stamp when they close a month on the 1st
     *                 of the next one. `<= $month` because income held back by
     *                 a hold or the minimum payout rolls forward.
     *   earned_on   — the earning DAY, for streams that carry one.
     *   created_at  — the last resort for a credit with neither (a Lifetime
     *                 Award released by hand). App time is IST, so the month
     *                 boundary needs no conversion.
     *
     * Rows written before either column existed carry neither and fall to
     * created_at, which for a historical batch is the same answer the old
     * unwindowed sweep gave.
     *
     * @param  Builder<WalletLedgerEntry>  $query
     */
    #[Scope]
    protected function earnedForMonthOrBefore(Builder $query, Carbon $month): void
    {
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $query->where(function (Builder $window) use ($monthStart, $monthEnd): void {
            $window->whereDate('bonus_month', '<=', $monthStart)
                ->orWhere(function (Builder $byDay) use ($monthEnd): void {
                    $byDay->whereNull('bonus_month')->whereDate('earned_on', '<=', $monthEnd);
                })
                ->orWhere(function (Builder $byWriteTime) use ($monthEnd): void {
                    $byWriteTime->whereNull('bonus_month')
                        ->whereNull('earned_on')
                        ->where('created_at', '<=', $monthEnd);
                });
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
