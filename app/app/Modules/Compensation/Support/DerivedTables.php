<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

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
 *   • {@see \App\Console\Actions\PurchaseDataResetAction} — wipes these PLUS
 *     the commerce source tables ("start selling again").
 *   • {@see \App\Modules\Compensation\Services\Recompute\CompensationStateWiper}
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
     * @return list<string>
     */
    public static function inTruncationOrder(): array
    {
        return self::TABLES;
    }

    public static function contains(string $table): bool
    {
        return in_array($table, self::TABLES, true);
    }
}
