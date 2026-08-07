<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the Fortune Bonus per-tier enrolment gates (KP 2026-08-07).
 * Idempotent: upsert keyed on `tier`.
 */
final class FortuneBonusTiersSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s.v');

        // slabs_required is the count of GSB slab-ACHIEVEMENTS a distributor
        // must earn that month to enter the Fortune Bonus — repeats count, GSB
        // has only 7 distinct slabs (KP 2026-08-07). His ranked series
        // 8 / 11 / 14 / 17 / 20 (Ranks 1–5) supersedes the 2026-06-28
        // 7 / 10 / 13 / 16 / 19, and each ranked tier now carries its own
        // monthly BV gate (1,000 → 1,400 BV).
        //
        // new_joiner (month 1) additionally requires the 3,000 BV Retailer
        // purchase and the GSB 1st income specifically (slab 1); non_ranked
        // (month 2+) additionally requires one of the 7 personal-purchase
        // titles — both enforced in FortuneBonusService::enrollEligible().
        $rows = [
            // tier, bv_required_paise, slabs_required
            ['new_joiner', 300_000, 1],
            ['non_ranked', 60_000, 1],
            ['rank_1', 100_000, 8],
            ['rank_2', 110_000, 11],
            ['rank_3', 120_000, 14],
            ['rank_4', 130_000, 17],
            ['rank_5', 140_000, 20],
        ];

        $records = [];
        foreach ($rows as $i => $r) {
            $records[] = [
                'tier' => $r[0],
                'bv_required_paise' => $r[1],
                'slabs_required' => $r[2],
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('fortune_bonus_tiers')->upsert(
            $records,
            ['tier'],
            ['bv_required_paise', 'slabs_required', 'sort_order', 'updated_at'],
        );
    }
}
