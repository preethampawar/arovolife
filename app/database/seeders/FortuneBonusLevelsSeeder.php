<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the Fortune Bonus 3×9 matrix per-level payouts. Values equal the former
 * FortuneBonusParticipant::LEVEL_BONUS_PAISE const (no behaviour change).
 * Idempotent: upsert keyed on `level`.
 */
final class FortuneBonusLevelsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s.v');

        $bonusByLevel = [
            0 => 339,
            1 => 1017,
            2 => 3050,
            3 => 4579,
            4 => 6888,
            5 => 2500,
            6 => 6000,
            7 => 5500,
            8 => 5100,
            9 => 0,
        ];

        $records = [];
        foreach ($bonusByLevel as $level => $bonusPaise) {
            $records[] = [
                'level' => $level,
                'bonus_paise' => $bonusPaise,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('fortune_bonus_levels')->upsert(
            $records,
            ['level'],
            ['bonus_paise', 'is_active', 'updated_at'],
        );
    }
}
