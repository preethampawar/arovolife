<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of the rupee-per-score value actually used to price this cut-off's
 * gross (KP 2026-07-29 pro-rated pool). Slabs 1–2 record the fixed per-slab
 * value; slabs 3–7 record the day's variable pool value. Nullable: historical
 * rows and non-matched outcomes carry no value. Reports must COALESCE to the
 * pool/slab value only for display — money always replays gross_gsb_paise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gsb_cutoff_results', function (Blueprint $table) {
            $table->unsignedBigInteger('score_value_paise')->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('gsb_cutoff_results', function (Blueprint $table) {
            $table->dropColumn('score_value_paise');
        });
    }
};
