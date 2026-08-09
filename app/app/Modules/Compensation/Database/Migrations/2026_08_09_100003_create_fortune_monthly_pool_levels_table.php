<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-absolute-matrix-level frozen economics of a Fortune Bonus month
 * (KP 2026-08-09 cascade): the payout mode and cap that were in force, how
 * many participants sat at the level with how many combined FB points, the
 * whole-rupee point value the level priced at, and the total it paid
 * (including the ₹30 minimums).
 *
 * Written together with the parent fortune_monthly_pools row at freeze time
 * and never recomputed — a re-run reconstructs every participant's income
 * from these rows alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fortune_monthly_pool_levels', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fortune_monthly_pool_id');
            $table->unsignedTinyInteger('matrix_level');
            $table->string('payout_mode', 16);
            $table->unsignedBigInteger('cap_paise')->nullable();
            $table->unsignedInteger('participants');
            $table->unsignedBigInteger('points');
            $table->unsignedBigInteger('point_value_paise');
            $table->unsignedBigInteger('paid_paise');
            $table->timestamps();

            $table->unique(['fortune_monthly_pool_id', 'matrix_level'], 'uniq_fmpl_pool_level');
            $table->foreign('fortune_monthly_pool_id', 'fk_fmpl_pool')
                ->references('id')->on('fortune_monthly_pools')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fortune_monthly_pool_levels');
    }
};
