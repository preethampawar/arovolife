<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds `erased` to `users.closure_type`: the account of a partner removed by
 * the developer-only subtree erasure. It is not a termination — the person's
 * data is gone, and only the anonymised account row remains.
 *
 * SQLite is skipped: 2026_08_17_150100 already turned the column into a plain
 * string there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY closure_type ENUM('cooling_off_cancellation', 'admin_termination', 'consent_withdrawn', 'erased') NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (DB::table('users')->where('closure_type', 'erased')->exists()) {
            throw new RuntimeException('Some users have closure_type "erased"; the enum value cannot be removed while they exist.');
        }

        DB::statement("ALTER TABLE users MODIFY closure_type ENUM('cooling_off_cancellation', 'admin_termination', 'consent_withdrawn') NULL");
    }
};
