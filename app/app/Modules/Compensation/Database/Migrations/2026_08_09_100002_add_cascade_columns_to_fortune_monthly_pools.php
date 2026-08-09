<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KP 2026-08-09 Fortune cascade: the month no longer has ONE point value —
 * each capped level prices against the remaining pool and the residual levels
 * share a value, all snapshotted in fortune_monthly_pool_levels. The pool
 * row's point_value_paise therefore becomes nullable: legacy single-value
 * months keep theirs, cascade months write NULL and carry the values on their
 * level rows instead.
 *
 * New columns freeze the guarantee economics: the ₹30 minimum in force
 * (min_commission_paise), the total reserved for it (guaranteed_total_paise),
 * and the shortfall branch — when the pool cannot cover the guarantees every
 * qualifier gets the same whole-rupee share (shortfall_per_head_paise) and
 * nothing else. All nullable so pre-cascade rows stay untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fortune_monthly_pools', function (Blueprint $table): void {
            $table->unsignedBigInteger('point_value_paise')->nullable()->change();
            $table->unsignedInteger('min_commission_paise')->nullable()->after('point_value_paise');
            $table->unsignedBigInteger('guaranteed_total_paise')->nullable()->after('min_commission_paise');
            $table->boolean('is_shortfall')->default(false)->after('guaranteed_total_paise');
            $table->unsignedBigInteger('shortfall_per_head_paise')->nullable()->after('is_shortfall');
        });
    }

    public function down(): void
    {
        Schema::table('fortune_monthly_pools', function (Blueprint $table): void {
            $table->dropColumn(['min_commission_paise', 'guaranteed_total_paise', 'is_shortfall', 'shortfall_per_head_paise']);
            $table->unsignedBigInteger('point_value_paise')->nullable(false)->change();
        });
    }
};
