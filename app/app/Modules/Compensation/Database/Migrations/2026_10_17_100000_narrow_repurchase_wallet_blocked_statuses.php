<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire the 'repurchase_wallet_blocked' status from gbb_monthly_results,
 * rank_bonus_results and fortune_bonus_results. It recorded the month-end
 * repurchase-wallet gate (client 2026-09-05), which the user retired on
 * 2026-10-17: the repurchase wallet is judged only at each cycle's due date,
 * and no monthly engine writes the status any more.
 *
 * Fail-safe: count every table first and refuse before any DDL while a single
 * row still carries the status — narrowing a MySQL ENUM under such a row would
 * rewrite or reject it, and the month that row belongs to was priced without
 * that distributor. Such a month has to be replayed on the new rule (dev and
 * staging recompute) or wiped (production before launch), never patched.
 *
 * MySQL only. SQLite needs no DDL: the three status columns are plain strings
 * there. The refusal runs on every driver.
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.narrow_repurchase_wallet_blocked_statuses';

    private const REMOVED = 'repurchase_wallet_blocked';

    /** Table → the enum list without the removed status. */
    private const NARROWED = [
        'gbb_monthly_results' => "'pending','credited','reversed'",
        'rank_bonus_results' => "'pending','credited','reversed','requalification_held'",
        'fortune_bonus_results' => "'pending','credited','skipped'",
    ];

    public function up(): void
    {
        $carrying = [];
        foreach (array_keys(self::NARROWED) as $table) {
            $carrying[$table] = DB::table($table)->where('status', self::REMOVED)->count();
        }

        if (array_sum($carrying) > 0) {
            $detail = implode(', ', array_map(
                static fn (string $table, int $n): string => "{$table}: {$n}",
                array_keys($carrying),
                $carrying,
            ));

            throw new RuntimeException("Refusing to narrow the bonus status enums: rows still carry repurchase_wallet_blocked ({$detail}). Replay or wipe history first.");
        }

        if (DB::getDriverName() === 'mysql') {
            foreach (self::NARROWED as $table => $values) {
                DB::statement("ALTER TABLE {$table} MODIFY COLUMN status ENUM({$values}) NOT NULL DEFAULT 'pending'");
            }
        }

        // Through the model, not a raw insert: the creating hook links the
        // row into the audit hash chain, which a raw insert would skip.
        AuditLog::create([
            'actor_id' => null,
            'action' => self::ACTION,
            'subject_type' => 'compensation_status_enum',
            'subject_id' => null,
            'details' => [
                'migration' => '2026_10_17_100000_narrow_repurchase_wallet_blocked_statuses',
                'reason' => 'User decision 2026-10-17: no month-end repurchase-wallet gate on Growth Booster, Fortune, rank requalification or AO-GO.',
                'driver' => DB::getDriverName(),
                'removed' => self::REMOVED,
                'rows_carrying' => $carrying,
            ],
        ]);
    }

    public function down(): void
    {
        // Schema only: each enum widens back to include the status. No row
        // moves. Audited like the narrowing, so a rollback is as visible as
        // the run.
        if (DB::getDriverName() === 'mysql') {
            foreach (self::NARROWED as $table => $values) {
                DB::statement("ALTER TABLE {$table} MODIFY COLUMN status ENUM({$values},'".self::REMOVED."') NOT NULL DEFAULT 'pending'");
            }
        }

        AuditLog::create([
            'actor_id' => null,
            'action' => self::ACTION.'.rolled_back',
            'subject_type' => 'compensation_status_enum',
            'subject_id' => null,
            'details' => [
                'migration' => '2026_10_17_100000_narrow_repurchase_wallet_blocked_statuses',
                'driver' => DB::getDriverName(),
                'restored' => self::REMOVED,
            ],
        ]);
    }
};
