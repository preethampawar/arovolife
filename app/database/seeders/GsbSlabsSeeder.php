<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the 7 GSB slabs with KP Naik's 2026-06-26 confirmed values.
 * Idempotent: upsert keyed on `slab`. bonus_paise = score × ₹360 (36,000 paise).
 * Slab 7 (score 167 → ₹60,120) confirmed in KP's final 26-06-2026 plan doc.
 */
final class GsbSlabsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s.v');

        $rows = [
            // slab, title, title_min_bv_paise, matched_bv_paise, score, bonus_paise, agp, cf_lifetime, active
            [1, 'Retailer', 300_000, 1_500_000, 5, 180_000, 12, true, true],
            [2, 'Dealer', 500_000, 3_600_000, 10, 360_000, 5, false, true],
            [3, 'Wholesaler', 1_500_000, 9_000_000, 20, 720_000, 2, false, true],
            [4, 'Distributor', 5_000_000, 27_000_000, 38, 1_368_000, 0, false, true],
            [5, 'Regional Distributor', 10_000_000, 81_000_000, 70, 2_520_000, 0, false, true],
            [6, 'National Distributor', 20_000_000, 243_000_000, 117, 4_212_000, 0, false, true],
            // Slab 7: Global Distributor (30,00,000 BV title). KP's final
            // 26-06-2026 doc supplied score 167 → ₹60,120 (167 × ₹360 = 60,120),
            // making it a fully payable matching slab. agp 0 (1st–3rd slabs only).
            [7, 'Global Distributor', 30_000_000, 729_000_000, 167, 6_012_000, 0, false, true],
        ];

        $records = array_map(fn (array $r): array => [
            'slab' => $r[0],
            'title' => $r[1],
            'title_min_bv_paise' => $r[2],
            'matched_bv_paise' => $r[3],
            'score' => $r[4],
            'bonus_paise' => $r[5],
            'agp_per_occurrence' => $r[6],
            'carry_forward_lifetime' => $r[7],
            'is_active' => $r[8],
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        DB::table('gsb_slabs')->upsert(
            $records,
            ['slab'],
            ['title', 'title_min_bv_paise', 'matched_bv_paise', 'score', 'bonus_paise', 'agp_per_occurrence', 'carry_forward_lifetime', 'is_active', 'updated_at'],
        );
    }
}
