<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Client notes 2026-09-03: every Fortune matrix level is a capped level with
 * its own per-member ceiling — levels 7–9 stop being "residual / flat" and
 * become ₹2,500 / ₹1,500 / ₹30 (the ₹30 cap equals the guaranteed minimum).
 *
 * Data-only and idempotent. A row is rewritten when it is not `capped` OR
 * has no cap (an environment that never ran FortuneBonusLevelsSeeder after
 * the 2026-08-09 columns landed carries `capped` + NULL, which the allocator
 * would treat as uncapped). A capped row that already carries a cap — e.g.
 * one a developer edited on Plan Settings — is left untouched. Months frozen
 * before this change keep their own `fortune_monthly_pool_levels` snapshot.
 */
return new class extends Migration
{
    /** @var array<int, int> level => cap_paise (includes the ₹30 minimum) */
    private const array CAPS = [
        0 => 3_000_000,
        1 => 3_000_000,
        2 => 3_000_000,
        3 => 3_000_000,
        4 => 2_000_000,
        5 => 1_000_000,
        6 => 500_000,
        7 => 250_000,
        8 => 150_000,
        9 => 3_000,
    ];

    public function up(): void
    {
        foreach (self::CAPS as $level => $capPaise) {
            DB::table('fortune_bonus_levels')
                ->where('level', $level)
                ->where(function ($query): void {
                    $query->where('payout_mode', '!=', 'capped')
                        ->orWhereNull('cap_paise');
                })
                ->update([
                    'payout_mode' => 'capped',
                    'cap_paise' => $capPaise,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: the previous residual/flat configuration is a
        // superseded plan rule, and reverting it silently would change what
        // the next Fortune run pays. Re-seed FortuneBonusLevelsSeeder from the
        // prior commit if a rollback is ever genuinely required.
    }
};
