<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the 9 rank tiers. Values equal the former RankQualification consts
 * exactly (no behaviour change); they become admin-editable from here on.
 * Idempotent: upsert keyed on `rank_number`.
 */
final class RankTiersSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s.v');

        // KP's 27-06-2026 Round-2 answers: pool %s now total 20% (R2 3.4, R3 2.7,
        // R4 2.2 changed; rest unchanged). Personal-BV requirements track the
        // revised personal-title ladder — R1 Dealer 7,000 (explicit), R3
        // Distributor 32,000, R4 Regional 68,000, R5 National 1,44,000 BV.
        $rows = [
            // rank, name, pool_pct, pyp, personal_bv, group_bv, structural_per_side
            [1, 'Silver Partner', 7.00, 1, 700_000, 30_000_000, null],
            [2, 'Pearl Partner', 3.40, 1, 1_500_000, 50_000_000, null],
            [3, 'Emerald Partner', 2.70, 2, 3_200_000, null, 2],
            [4, 'Gold Partner', 2.20, 2, 6_800_000, null, 2],
            [5, 'Diamond Partner', 1.70, 2, 14_400_000, null, 2],
            [6, 'Blue Diamond Partner', 1.20, 3, 30_000_000, null, 2],
            [7, 'Royal Diamond Partner', 0.90, 3, 30_000_000, null, 2],
            [8, 'Crown Diamond Partner', 0.60, 3, 30_000_000, null, 2],
            [9, 'Elite Diamond Partner', 0.30, 3, 30_000_000, null, 2],
        ];

        $records = array_map(fn (array $r): array => [
            'rank_number' => $r[0],
            'rank_name' => $r[1],
            'pool_pct' => $r[2],
            'pyp_required' => $r[3],
            'personal_bv_required_paise' => $r[4],
            'group_bv_required_paise' => $r[5],
            'structural_qualifiers_per_side' => $r[6],
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        DB::table('rank_tiers')->upsert(
            $records,
            ['rank_number'],
            ['rank_name', 'pool_pct', 'pyp_required', 'personal_bv_required_paise', 'group_bv_required_paise', 'structural_qualifiers_per_side', 'is_active', 'updated_at'],
        );
    }
}
