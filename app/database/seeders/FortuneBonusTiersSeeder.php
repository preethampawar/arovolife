<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the Fortune Bonus per-tier enrolment gates. Values equal the former
 * FortuneBonusParticipant::BV_REQUIRED_PAISE + SLABS_REQUIRED consts
 * (no behaviour change). Idempotent: upsert keyed on `tier`.
 */
final class FortuneBonusTiersSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s.v');

        $rows = [
            // tier, bv_required_paise, slabs_required
            ['new_joiner', 300_000, 1],
            ['non_ranked', 60_000, 1],
            ['rank_1', 100_000, 4],
            ['rank_2', 100_000, 6],
            ['rank_3', 100_000, 8],
            ['rank_4', 100_000, 10],
            ['rank_5', 100_000, 12],
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
