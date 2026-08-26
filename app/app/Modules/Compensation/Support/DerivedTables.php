<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Console\Actions\PurchaseDataResetAction;
use App\Modules\Compensation\Services\Recompute\CompensationStateWiper;

/**
 * The single source of truth for which tables hold state COMPUTED from BV, and
 * the order they must be truncated in.
 *
 * "Derived" means: every row can be reproduced by re-running the engine that
 * wrote it, given the same source data (orders, bv_ledger_entries, the tree and
 * the plan settings). Nothing in this list is input; nothing in this list is
 * safe to keep when the intent is to recompute from scratch.
 *
 * Two callers consume it and there must never be a third copy:
 *   • {@see PurchaseDataResetAction} — wipes these PLUS
 *     the commerce source tables ("start selling again").
 *   • {@see CompensationStateWiper}
 *     — wipes only these, keeping the purchases ("recompute the same history").
 *
 * The list drifting out of date is not hypothetical: rank_aogo_grants,
 * gsb_personal_bv_topups and engine_runs were all added to the schema after
 * the reset was written and silently survived it, leaving orphaned grants that
 * made AogoOfferService treat a month as already granted.
 */
final class DerivedTables
{
    /**
     * FK-safe truncation order — children before parents.
     *
     * Only three real FK constraints exist among these:
     *   payout_line_items        → payout_batches
     *   wallet_ledger_entries    → payout_batches
     *   fortune_monthly_pool_levels → fortune_monthly_pools
     * Everything else points only at distributors / arete_centers / orders,
     * which are never truncated by either caller.
     *
     * @var list<string>
     */
    private const TABLES = [
        // Group BV projection + its propagation markers. bv_propagation_log
        // MUST go: PropagateGroupBvJob no-ops on its unique row, so a replay
        // with the log intact silently accumulates nothing.
        'group_bv_credits',
        'group_bv_reversals',
        'group_bv_debts',
        'bv_propagation_log',
        'group_bv_daily',

        // Children before the parent: both payout_line_items and
        // wallet_ledger_entries (swept_by_payout_batch_id) reference
        // payout_batches.
        'payout_line_items',
        'wallet_ledger_entries',
        'payout_batches',

        // Daily engines. The frozen pool rows must go too: freezePoolForDate()
        // returns an existing row unchanged, so a survivor would price every
        // re-run of that date against pre-wipe company BV forever.
        'gsb_cutoff_results',
        'gsb_carryforward',
        'gsb_personal_bv_topups',
        'gsb_daily_pools',
        'msb_daily_pools',
        'mentorship_bonus_results',

        // Monthly engines — same frozen-pool rule.
        'gbb_monthly_results',
        'gbb_monthly_pools',
        'rank_bonus_results',
        'rank_aogo_grants',
        'rank_qualifications',
        'lifetime_award_milestones',
        'fortune_bonus_results',
        'fortune_bonus_participants',
        'fortune_monthly_pool_levels',
        'fortune_monthly_pools',
        'adc_bonus_results',

        // Eligibility state rebuilt from the BV ledger.
        'repurchase_cycles',

        // The run log itself: EngineStatusService::isPeriodComputed() reads a
        // succeeded row as "already done", so surviving rows make a replay skip
        // the very periods it was asked to recompute.
        'engine_runs',
    ];

    /**
     * The column that dates each derived row, per table — what makes a
     * *windowed* recompute possible at all: with it a replay can delete only
     * the rows on or after a date instead of truncating the history.
     *
     * `period` tables are keyed by the first day of the month the engine ran
     * for, so a window that starts mid-month must delete from that month's
     * first day (see WindowedStateWiper).
     *
     * Three tables are deliberately absent because they carry no date:
     *   • gsb_carryforward — one rolling row per distributor, rewound from the
     *     `*_before` columns already stored on gsb_cutoff_results;
     *   • group_bv_debts — rolling reversal debt, rewound arithmetically;
     *   • payout_line_items — deleted by parent payout_batches id.
     *
     * @var array<string, array{column: string, granularity: 'day'|'month'}>
     */
    private const DATE_COLUMNS = [
        'group_bv_credits' => ['column' => 'date', 'granularity' => 'day'],
        'group_bv_reversals' => ['column' => 'date', 'granularity' => 'day'],
        'bv_propagation_log' => ['column' => 'date', 'granularity' => 'day'],
        'group_bv_daily' => ['column' => 'date', 'granularity' => 'day'],
        'wallet_ledger_entries' => ['column' => 'created_at', 'granularity' => 'day'],
        'payout_batches' => ['column' => 'batch_date', 'granularity' => 'day'],
        'gsb_cutoff_results' => ['column' => 'cutoff_date', 'granularity' => 'day'],
        'gsb_personal_bv_topups' => ['column' => 'date', 'granularity' => 'day'],
        'gsb_daily_pools' => ['column' => 'cutoff_date', 'granularity' => 'day'],
        'msb_daily_pools' => ['column' => 'cutoff_date', 'granularity' => 'day'],
        'mentorship_bonus_results' => ['column' => 'cutoff_date', 'granularity' => 'day'],
        'repurchase_cycles' => ['column' => 'cycle_start_date', 'granularity' => 'day'],
        'engine_runs' => ['column' => 'period_start', 'granularity' => 'day'],
        'gbb_monthly_results' => ['column' => 'year_month', 'granularity' => 'month'],
        'gbb_monthly_pools' => ['column' => 'month_start', 'granularity' => 'month'],
        'rank_bonus_results' => ['column' => 'month_start', 'granularity' => 'month'],
        'rank_aogo_grants' => ['column' => 'month_start', 'granularity' => 'month'],
        'rank_qualifications' => ['column' => 'month_start', 'granularity' => 'month'],
        'lifetime_award_milestones' => ['column' => 'triggered_month', 'granularity' => 'month'],
        'fortune_bonus_results' => ['column' => 'month_start', 'granularity' => 'month'],
        'fortune_bonus_participants' => ['column' => 'month_start', 'granularity' => 'month'],
        'fortune_monthly_pools' => ['column' => 'month_start', 'granularity' => 'month'],
        'adc_bonus_results' => ['column' => 'month_start', 'granularity' => 'month'],
    ];

    /**
     * @return list<string>
     */
    public static function inTruncationOrder(): array
    {
        return self::TABLES;
    }

    /**
     * How to date-filter one derived table, or null when it carries no date.
     *
     * @return array{column: string, granularity: 'day'|'month'}|null
     */
    public static function dateFilter(string $table): ?array
    {
        return self::DATE_COLUMNS[$table] ?? null;
    }

    public static function contains(string $table): bool
    {
        return in_array($table, self::TABLES, true);
    }
}
