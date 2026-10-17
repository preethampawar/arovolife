<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The Lifetime Awards & Rewards catalogue (client 2026-10-09): one placeholder
 * merchandise item per award tranche, worth the tranche amount, so each rank's
 * items reconcile to its budget (rank_tiers.lifetime_award_budget_paise, the
 * sum of the rank's tranches in LifetimeAwardTranchesSeeder). The client will
 * supply the item list; until then the admin edits these rows on the
 * Reward catalog page. Awards are merchandise only, never cash.
 *
 * Idempotent: upsert keyed on (rank_number, sort_order). Rows of the retired
 * itemised catalogue beyond a rank's tranche count are removed so they can
 * never survive a re-run and break the reconciliation.
 */
final class LifetimeAwardRewardsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s.v');

        $records = [];
        foreach (LifetimeAwardTranchesSeeder::TRANCHES as $rank => $amounts) {
            foreach ($amounts as $sort => $worth) {
                $records[] = [
                    'rank_number' => $rank,
                    'sort_order' => $sort,
                    'item' => sprintf('Merchandise, tranche %s — items to be specified by the company', chr(65 + $sort)),
                    'worth_paise' => $worth,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('lifetime_award_rewards')
                ->where('rank_number', $rank)
                ->where('sort_order', '>=', count($amounts))
                ->delete();
        }

        DB::table('lifetime_award_rewards')->upsert(
            $records,
            ['rank_number', 'sort_order'],
            ['item', 'worth_paise', 'updated_at'],
        );
    }
}
