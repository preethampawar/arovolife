<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'rejected' to users.status so we can distinguish a distributor whose
 * KYC was declined (can re-upload and try again) from one whose account is
 * permanently closed ('terminated', reserved for fraud / repeat offenders /
 * cooling-off cancellation). The KYC review queue continues to filter by
 * status='pending'; the re-submission flow will flip rejected → pending
 * once new documents arrive.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL only — the ALTER syntax is MySQL-specific. SQLite (used by
        // the test suite) gets the same enum at create time via
        // 2026_04_19_000001_create_users_table.php which now lists 'rejected'
        // in its $table->enum() call, so the column's CHECK constraint
        // already permits the value.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("ALTER TABLE users MODIFY COLUMN status ENUM('pending', 'active', 'frozen', 'terminated', 'rejected') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("UPDATE users SET status = 'terminated' WHERE status = 'rejected'");
        DB::statement("ALTER TABLE users MODIFY COLUMN status ENUM('pending', 'active', 'frozen', 'terminated') NOT NULL DEFAULT 'pending'");
    }
};
