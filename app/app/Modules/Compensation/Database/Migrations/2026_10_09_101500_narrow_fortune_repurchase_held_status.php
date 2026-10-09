<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire the 'repurchase_held' status from fortune_bonus_results — the Fortune
 * twin of 2026_10_09_101400. It came from the 2026-09-06 hold-and-release
 * model that the client's 2026-09-07 forfeit spec replaced; FortuneBonusResult
 * has no constant for it and no engine writes it.
 *
 * Where it came from: 2026_09_06_100001_add_repurchase_held_to_rank_and_fortune_results
 * widened the enum, ran on some long-lived databases, and was then deleted
 * from the repository (43f66121) as "unrun". A database migrated after that
 * commit never had the value, so on it this narrowing restates the current
 * enum unchanged; a database that ran the deleted migration loses the value.
 *
 * Fail-safe: count first and refuse before any DDL while a single row still
 * carries the status — narrowing a MySQL ENUM under such a row would rewrite
 * or reject it.
 *
 * MySQL only. The list is the one 2026_09_05_100002 (the latest widening of
 * this column in the repository) wrote. SQLite needs nothing: its CHECK
 * constraint was rebuilt by 2026_09_05_100002 without repurchase_held. The
 * refusal runs on every driver.
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.narrow_fortune_repurchase_held_status';

    private const REMOVED = 'repurchase_held';

    public function up(): void
    {
        $carrying = DB::table('fortune_bonus_results')->where('status', self::REMOVED)->count();

        if ($carrying > 0) {
            throw new RuntimeException("Refusing to narrow the fortune bonus status enum: {$carrying} fortune_bonus_results rows still carry repurchase_held. Replay or wipe history first.");
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE fortune_bonus_results MODIFY COLUMN status ENUM('pending','credited','skipped','repurchase_wallet_blocked') NOT NULL DEFAULT 'pending'");
        }

        // Through the model, not a raw insert: the creating hook links the
        // row into the audit hash chain, which a raw insert would skip.
        AuditLog::create([
            'actor_id' => null,
            'action' => self::ACTION,
            'subject_type' => 'compensation_status_enum',
            'subject_id' => null,
            'details' => [
                'migration' => '2026_10_09_101500_narrow_fortune_repurchase_held_status',
                'reason' => 'The client\'s 2026-09-07 forfeit spec replaced hold-and-release; no engine writes the fortune repurchase_held status.',
                'driver' => DB::getDriverName(),
                'fortune_bonus_results' => ['column' => 'status', 'removed' => [self::REMOVED], 'rows_carrying' => $carrying],
            ],
        ]);
    }

    public function down(): void
    {
        // Schema only: the enum widens back to the list the deleted
        // 2026_09_06_100001 wrote. No row moves.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE fortune_bonus_results MODIFY COLUMN status ENUM('pending','credited','skipped','repurchase_wallet_blocked','repurchase_held') NOT NULL DEFAULT 'pending'");
    }
};
