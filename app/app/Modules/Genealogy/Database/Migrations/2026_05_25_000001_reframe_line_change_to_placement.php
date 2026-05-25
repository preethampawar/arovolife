<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reframe line-change from a sponsor change to a binary-placement change
 * (spec 2026-05-25). The columns named "sponsor" actually only ever
 * recorded the binary placement target; rename them to match reality and
 * add the admin-review fields the approval workflow needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Additive columns first (separate closure so SQLite's renameColumn
        // table-rebuild in the next block sees a stable shape).
        Schema::table('line_change_requests', function (Blueprint $table): void {
            $table->char('chosen_side', 1)->nullable()->after('to_sponsor_id');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('approved_at');
            $table->dateTime('reviewed_at', 3)->nullable()->after('reviewed_by');
            $table->string('decision_note', 1024)->nullable()->after('reviewed_at');

            $table->foreign('reviewed_by', 'fk_lcr_reviewer')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('line_change_requests', function (Blueprint $table): void {
            $table->renameColumn('from_sponsor_id', 'from_placement_parent_id');
            $table->renameColumn('to_sponsor_id', 'to_placement_parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('line_change_requests', function (Blueprint $table): void {
            $table->renameColumn('from_placement_parent_id', 'from_sponsor_id');
            $table->renameColumn('to_placement_parent_id', 'to_sponsor_id');
        });

        Schema::table('line_change_requests', function (Blueprint $table): void {
            $table->dropForeign('fk_lcr_reviewer');
            $table->dropColumn(['chosen_side', 'reviewed_by', 'reviewed_at', 'decision_note']);
        });
    }
};
