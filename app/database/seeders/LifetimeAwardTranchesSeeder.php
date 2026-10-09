<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The client's 2026-10-09 Lifetime Awards & Rewards tranches, in paise
 * (₹ × 100). Tranche A is released on a rank's 1st qualification, B on the 2nd,
 * C on the 3rd; each rank's tranches sum to its budget
 * (rank_tiers.lifetime_award_budget_paise, RankTiersSeeder). Awards are
 * merchandise only, never cash.
 *
 * Rank 9 totals ₹6,87,47,400 — the client document's heading "6,87,74,400" is
 * a digit swap of the tranche sum.
 *
 * Idempotent: upsert keyed on (rank_number, tranche). Also run by migration
 * 2026_10_09_100800 so a fresh install migrates end to end without a seeder.
 */
final class LifetimeAwardTranchesSeeder extends Seeder
{
    /** rank => [tranche A, tranche B, tranche C] amounts in paise. */
    public const array TRANCHES = [
        1 => [1_540_000],
        2 => [3_600_000],
        3 => [4_860_000, 5_940_000],
        4 => [14_580_000, 17_820_000],
        5 => [43_740_000, 53_460_000],
        6 => [84_780_000, 93_240_000, 104_580_000],
        7 => [245_250_000, 269_730_000, 302_490_000],
        8 => [711_180_000, 782_280_000, 877_140_000],
        9 => [918_630_000, 956_070_000, 5_000_040_000],
    ];

    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s.v');

        $records = [];
        foreach (self::TRANCHES as $rank => $amounts) {
            foreach ($amounts as $index => $amount) {
                $records[] = [
                    'rank_number' => $rank,
                    'tranche' => $index + 1,
                    'amount_paise' => $amount,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('lifetime_award_tranches')->upsert(
            $records,
            ['rank_number', 'tranche'],
            ['amount_paise', 'updated_at'],
        );
    }
}
