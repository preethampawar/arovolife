<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the 7 GSB slabs with KP Naik's 2026-07-21 "New Engine" values.
 * Idempotent: upsert keyed on `slab`. bonus_paise = score × score_value_paise
 * (per-slab configurable, default ₹250 = 25,000 paise).
 *
 * 2026-07-21 changes vs the 2026-06-26 table:
 *  - scores: 8 / 16 / 32 / 60 / 112 / 184 / 280 (were 5/10/20/38/70/117/167)
 *  - matched BV (both sides): 15K / 36K / 1L / 3L / 9L / 27L / 81L
 *  - per-slab score value replaces the single global ₹360 score rate.
 * Personal-purchase title-BV thresholds are unchanged (KP 27-06-2026 Round-2).
 *
 * 2026-07-25 MSB sheet: per-slab Mentorship Bonus points 21/18/15/12/9/6/3
 * (credited to the direct sponsor when a sponsee's cut-off matches the slab),
 * each worth msb_score_value_paise (default ₹250 = 25,000 paise, per-slab
 * configurable). Replaces the 10%→1% cumulative-GSB rate ladder.
 */
final class GsbSlabsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s.v');

        $rows = [
            // slab, title, title_min_bv_paise, matched_bv_paise, score, score_value_paise, bonus_paise, agp, cf_lifetime, active, msb_score, msb_score_value_paise
            [1, 'Retailer', 300_000, 1_500_000, 8, 25_000, 200_000, 12, true, true, 21, 25_000],
            [2, 'Dealer', 700_000, 3_600_000, 16, 25_000, 400_000, 5, false, true, 18, 25_000],
            [3, 'Wholesaler', 1_500_000, 10_000_000, 32, 25_000, 800_000, 2, false, true, 15, 25_000],
            [4, 'Distributor', 3_200_000, 30_000_000, 60, 25_000, 1_500_000, 0, false, true, 12, 25_000],
            [5, 'Regional Distributor', 6_800_000, 90_000_000, 112, 25_000, 2_800_000, 0, false, true, 9, 25_000],
            [6, 'National Distributor', 14_400_000, 270_000_000, 184, 25_000, 4_600_000, 0, false, true, 6, 25_000],
            [7, 'Global Distributor', 30_000_000, 810_000_000, 280, 25_000, 7_000_000, 0, false, true, 3, 25_000],
        ];

        $records = array_map(fn (array $r): array => [
            'slab' => $r[0],
            'title' => $r[1],
            'title_min_bv_paise' => $r[2],
            'matched_bv_paise' => $r[3],
            'score' => $r[4],
            'score_value_paise' => $r[5],
            'bonus_paise' => $r[6],
            'agp_per_occurrence' => $r[7],
            'carry_forward_lifetime' => $r[8],
            'is_active' => $r[9],
            'msb_score' => $r[10],
            'msb_score_value_paise' => $r[11],
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        DB::table('gsb_slabs')->upsert(
            $records,
            ['slab'],
            ['title', 'title_min_bv_paise', 'matched_bv_paise', 'score', 'score_value_paise', 'bonus_paise', 'agp_per_occurrence', 'carry_forward_lifetime', 'is_active', 'msb_score', 'msb_score_value_paise', 'updated_at'],
        );

        // Pin the personal-BV top-up go-live date to the deploy date, once.
        // Before this date all personal BV was credited daily by the old engine,
        // so it must never re-enter the new conditional pending pool. insertOrIgnore
        // keeps an already-set value stable across re-seeds. (Tests that exercise
        // the pending window set their own value / rely on the permissive default.)
        DB::table('settings')->insertOrIgnore([
            'key' => 'comp.gsb.topup_golive_date',
            'value' => now()->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
