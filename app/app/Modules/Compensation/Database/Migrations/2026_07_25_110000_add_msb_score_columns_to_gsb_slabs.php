<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-slab Mentorship Bonus points (KP 2026-07-25 sheet). When a sponsee's GSB
 * cut-off credits slab N, the direct sponsor earns msb_score points, each worth
 * msb_score_value_paise (default ₹250 = 25,000 paise) — replacing the old
 * 10%→1% cumulative-GSB rate ladder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gsb_slabs', function (Blueprint $table) {
            $table->unsignedInteger('msb_score')->default(0)->after('score_value_paise');
            $table->unsignedBigInteger('msb_score_value_paise')->default(25_000)->after('msb_score');
        });
    }

    public function down(): void
    {
        Schema::table('gsb_slabs', function (Blueprint $table) {
            $table->dropColumn(['msb_score', 'msb_score_value_paise']);
        });
    }
};
