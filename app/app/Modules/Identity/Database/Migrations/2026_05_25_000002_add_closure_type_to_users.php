<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHY an account reached a terminal state, so the admin UI can label
 * a cooling-off self-cancellation ('cancelled') distinctly from an admin
 * termination ('terminated') — both currently flip users.status='terminated'
 * and were indistinguishable on the distributor-show page.
 *
 * users.status holds the terminal lifecycle state, so users is the natural
 * home for the cause marker (it sits next to the state it explains, and is
 * per-account; distributors.status is a separate record-level active/inactive
 * flag with different semantics).
 *
 * Nullable: only set on a terminal transition; NULL for every live account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // ENUM on MySQL; SQLite (test suite) gets the same CHECK constraint.
            $table->enum('closure_type', ['cooling_off_cancellation', 'admin_termination'])
                ->nullable()
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('closure_type');
        });
    }
};
