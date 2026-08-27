<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the audit log tamper-evident (T-6.1 finding M-1).
 *
 * `before_hash` and `after_hash` already existed and describe the *subject* of
 * an action — what a record looked like either side of a change. Neither says
 * anything about the log itself, so a row that is edited or simply deleted
 * leaves no trace, and an audit log that can be quietly edited is not evidence
 * of anything.
 *
 * `row_hash` covers the row's own fields plus `prev_hash`, which is the
 * previous row's `row_hash`. That makes the table a chain: change or remove any
 * row and every hash after it stops matching, which `compliance:verify-audit-log`
 * detects.
 *
 * Both are nullable because rows written before this migration have no chain,
 * and back-filling would mean computing hashes over history nobody witnessed —
 * a chain that claims to attest to rows it never saw is worse than an honest
 * gap. The verifier starts at the first row that has one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->binary('row_hash')->nullable()->after('after_hash');
            $table->binary('prev_hash')->nullable()->after('row_hash');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE audit_log MODIFY row_hash BINARY(32) NULL');
            DB::statement('ALTER TABLE audit_log MODIFY prev_hash BINARY(32) NULL');
        }
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->dropColumn(['row_hash', 'prev_hash']);
        });
    }
};
