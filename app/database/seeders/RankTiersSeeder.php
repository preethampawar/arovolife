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

        // The client's 27-06-2026 Round-2 answers: personal-BV requirements
        // track the revised personal-title ladder — R1 Dealer 7,000
        // (explicit), R3 Distributor 32,000, R4 Regional 68,000, R5 National
        // 1,44,000 BV. Group-BV matches revised 2026-08-13 (client): R1 2.5L
        // per side (was 3L), R2 6L per side (was 8L per 2026-08-05, itself
        // raised from the June plan's 5L).
        // rap_points (client 2026-10-05 Rank Income Point System): every rank
        // carries Rank Achievement Points; the 20% envelope is one pool divided
        // in two passes at a capped point value (see RankBonusService).
        // The "1+2 rule" carry-forward is RETIRED (KP 2026-08-05) in favour of
        // the AO-GO offer: the column and its engine path are gone, only the
        // historical rank_qualifications rows remain.
        // pyp_required = the Q-Period: achieving rank r this many times grants
        // permission to attain rank r+1 (KP 2026-08-05).
        // repurchase_bv_paise = monthly repurchase obligation (KP: R1 1,000 …
        // R9 2,300 BV, stored in paise = BV × 100). Also the requalification
        // condition for 2nd-and-later credits of the same rank (KP §8).
        // lifetime_award_budget_paise = the sum of the rank's award tranches (client 2026-10-09)
        // — R1 ₹15,400 … R9 ₹6,87,47,400, from LifetimeAwardTranchesSeeder;
        // reconciles to the reward worths in LifetimeAwardRewardsSeeder.
        // weaker_leg_topup_bv_paise = capped personal-BV that may supplement the
        // weaker Genos leg toward the rank's group-BV match (KP 2026-06-28):
        // Ranks 1 & 2 only — R1 15,000 BV, R2 30,000 BV; Ranks 3-9 = 0.
        $rows = [
            // rank, name, pyp, rap_points, personal_bv, group_bv, weaker_leg_topup_bv, structural_per_side, repurchase_bv_paise, lifetime_award_budget_paise
            [1, 'Silver Partner', 1, 72, 700_000, 25_000_000, 1_500_000, null, 100_000, 1_540_000],
            [2, 'Pearl Partner', 1, 189, 1_500_000, 60_000_000, 3_000_000, null, 110_000, 3_600_000],
            [3, 'Emerald Partner', 2, 468, 3_200_000, null, 0, 2, 120_000, 10_800_000],
            [4, 'Gold Partner', 2, 1125, 6_800_000, null, 0, 2, 130_000, 32_400_000],
            [5, 'Diamond Partner', 2, 2583, 14_400_000, null, 0, 2, 140_000, 97_200_000],
            [6, 'Blue Diamond Partner', 3, 5688, 30_000_000, null, 0, 2, 160_000, 282_600_000],
            [7, 'Royal Diamond Partner', 3, 11934, 30_000_000, null, 0, 2, 180_000, 817_470_000],
            [8, 'Crown Diamond Partner', 3, 23877, 30_000_000, null, 0, 2, 200_000, 2_370_600_000],
            [9, 'Elite Diamond Partner', 3, 39501, 30_000_000, null, 0, 2, 230_000, 6_874_740_000],
        ];

        $records = array_map(fn (array $r): array => [
            'rank_number' => $r[0],
            'rank_name' => $r[1],
            'pyp_required' => $r[2],
            'rap_points' => $r[3],
            'personal_bv_required_paise' => $r[4],
            'group_bv_required_paise' => $r[5],
            'weaker_leg_topup_bv_paise' => $r[6],
            'structural_qualifiers_per_side' => $r[7],
            'repurchase_bv_paise' => $r[8],
            'lifetime_award_budget_paise' => $r[9],
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        DB::table('rank_tiers')->upsert(
            $records,
            ['rank_number'],
            ['rank_name', 'pyp_required', 'rap_points', 'personal_bv_required_paise', 'group_bv_required_paise', 'weaker_leg_topup_bv_paise', 'structural_qualifiers_per_side', 'repurchase_bv_paise', 'lifetime_award_budget_paise', 'is_active', 'updated_at'],
        );
    }
}
