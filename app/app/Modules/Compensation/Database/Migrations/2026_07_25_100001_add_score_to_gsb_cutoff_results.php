<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot the matched slab's score onto each cut-off result (KP 2026-07-21).
 * The GSB calculation report shows Score per row; storing it at match time keeps
 * historical rows correct if an admin later edits a slab's score. Nullable —
 * no-match / below-600 rows carry no score.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gsb_cutoff_results', function (Blueprint $table) {
            $table->unsignedInteger('score')->nullable()->after('slab');
        });
    }

    public function down(): void
    {
        Schema::table('gsb_cutoff_results', function (Blueprint $table) {
            $table->dropColumn('score');
        });
    }
};
