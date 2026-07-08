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
        // carry_forward_months = the "1+2 rule" (KP 2026-06-28): Rank 1 keeps
        // paying for 2 months after qualification; Ranks 2-9 do not (0).
        // repurchase_bv_paise = monthly repurchase obligation (KP: R1 1,000 …
        // R9 2,300 BV, stored in paise = BV × 100).
        // lifetime_award_budget_paise = per-rank Lifetime Awards budget (KP:
        // R1 ₹15,000 … R9 ₹2.25Cr); reconciles to the itemised reward worths in
        // LifetimeAwardRewardsSeeder.
        // weaker_leg_topup_bv_paise = capped personal-BV that may supplement the
        // weaker Genos leg toward the rank's group-BV match (KP 2026-06-28):
        // Ranks 1 & 2 only — R1 15,000 BV, R2 30,000 BV; Ranks 3-9 = 0.
        $rows = [
            // rank, name, pool_pct, pyp, personal_bv, group_bv, weaker_leg_topup_bv, structural_per_side, carry_forward_months, repurchase_bv_paise, lifetime_award_budget_paise
            [1, 'Silver Partner', 7.00, 1, 700_000, 30_000_000, 1_500_000, null, 2, 100_000, 1_500_000],
            [2, 'Pearl Partner', 3.40, 1, 1_500_000, 50_000_000, 3_000_000, null, 0, 110_000, 3_000_000],
            [3, 'Emerald Partner', 2.70, 2, 3_200_000, null, 0, 2, 0, 120_000, 9_000_000],
            [4, 'Gold Partner', 2.20, 2, 6_800_000, null, 0, 2, 0, 130_000, 36_500_000],
            [5, 'Diamond Partner', 1.70, 2, 14_400_000, null, 0, 2, 0, 140_000, 100_000_000],
            [6, 'Blue Diamond Partner', 1.20, 3, 30_000_000, null, 0, 2, 0, 160_000, 300_000_000],
            [7, 'Royal Diamond Partner', 0.90, 3, 30_000_000, null, 0, 2, 0, 180_000, 900_000_000],
            [8, 'Crown Diamond Partner', 0.60, 3, 30_000_000, null, 0, 2, 0, 200_000, 1_400_000_000],
            [9, 'Elite Diamond Partner', 0.30, 3, 30_000_000, null, 0, 2, 0, 230_000, 2_250_000_000],
        ];

        $records = array_map(fn (array $r): array => [
            'rank_number' => $r[0],
            'rank_name' => $r[1],
            'pool_pct' => $r[2],
            'pyp_required' => $r[3],
            'personal_bv_required_paise' => $r[4],
            'group_bv_required_paise' => $r[5],
            'weaker_leg_topup_bv_paise' => $r[6],
            'structural_qualifiers_per_side' => $r[7],
            'carry_forward_months' => $r[8],
            'repurchase_bv_paise' => $r[9],
            'lifetime_award_budget_paise' => $r[10],
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        DB::table('rank_tiers')->upsert(
            $records,
            ['rank_number'],
            ['rank_name', 'pool_pct', 'pyp_required', 'personal_bv_required_paise', 'group_bv_required_paise', 'weaker_leg_topup_bv_paise', 'structural_qualifiers_per_side', 'carry_forward_months', 'repurchase_bv_paise', 'lifetime_award_budget_paise', 'is_active', 'updated_at'],
        );
    }
}
