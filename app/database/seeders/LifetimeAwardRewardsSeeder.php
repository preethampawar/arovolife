<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * KP's 2026-06-28 Lifetime Awards & Rewards catalogue. Worths in paise
 * (₹ × 100); each rank's items reconcile to its budget (seeded onto
 * rank_tiers.lifetime_award_budget_paise by RankTiersSeeder).
 * Idempotent: upsert keyed on (rank_number, sort_order).
 */
final class LifetimeAwardRewardsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s.v');

        // rank => [ [item, worth_paise], ... ]
        $catalogue = [
            1 => [
                ['15 Lakh accident insurance (single person)', 1_500_000],
            ],
            2 => [
                ['30 Lakh term insurance (single person)', 3_000_000],
            ],
            3 => [
                ['15 Lakh health insurance (2+2)', 2_500_000],
                ['1 foreign trip (3N/4D)', 5_000_000],
                ['Gold', 1_500_000],
            ],
            4 => [
                ['4 foreign trip tickets (3N/4D)', 20_000_000],
                ['Samsung Tab', 3_000_000],
                ['Gold', 13_500_000],
            ],
            5 => [
                ['4 foreign trip tickets (3N/4D)', 20_000_000],
                ['Car down payment', 50_000_000],
                ['iPhone', 10_000_000],
                ['Gold', 20_000_000],
            ],
            6 => [
                ['4 foreign trip tickets (6N/7D)', 40_000_000],
                ['Car down payment', 150_000_000],
                ['Laptop down payment', 15_000_000],
                ['iPhone', 15_000_000],
                ['Preloaded debit card', 10_000_000],
                ['Gold', 70_000_000],
            ],
            7 => [
                ['4 foreign trip tickets (6N/7D)', 40_000_000],
                ['House down payment', 640_000_000],
                ['Royal Enfield Bullet down payment', 15_000_000],
                ['2 iPhones', 30_000_000],
                ['Preloaded debit card', 20_000_000],
                ['Gold', 80_000_000],
                ['Silver', 75_000_000],
            ],
            8 => [
                ['4 foreign trip tickets (10N/11D)', 100_000_000],
                ['House down payment', 750_000_000],
                ['Luxury car down payment', 340_000_000],
                ['Preloaded debit card', 30_000_000],
                ['Office rent', 5_000_000],
                ['Gold', 90_000_000],
                ['Silver', 85_000_000],
            ],
            9 => [
                ['4 foreign tickets (10N/11D)', 100_000_000],
                ['Independent villa down payment', 1_350_000_000],
                ['Luxury car down payment', 500_000_000],
                ['Driver salary', 5_000_000],
                ['Office rent', 10_000_000],
                ['PA salary', 5_000_000],
                ['Preloaded debit card', 50_000_000],
                ['Gold', 120_000_000],
                ['Silver', 110_000_000],
            ],
        ];

        $records = [];
        foreach ($catalogue as $rank => $items) {
            foreach ($items as $sort => [$item, $worth]) {
                $records[] = [
                    'rank_number' => $rank,
                    'sort_order' => $sort,
                    'item' => $item,
                    'worth_paise' => $worth,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('lifetime_award_rewards')->upsert(
            $records,
            ['rank_number', 'sort_order'],
            ['item', 'worth_paise', 'updated_at'],
        );
    }
}
