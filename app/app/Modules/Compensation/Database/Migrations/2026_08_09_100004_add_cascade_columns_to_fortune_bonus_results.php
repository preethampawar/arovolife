<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KP 2026-08-09 Fortune cascade: a result row's point_value_paise now holds
 * the value applied AT ITS MATRIX LEVEL (not a month-wide value). The new
 * columns record the ₹30 minimum included in the gross and, for capped
 * levels, the per-member ceiling that was in force — both nullable so
 * pre-cascade rows stay untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fortune_bonus_results', function (Blueprint $table): void {
            $table->unsignedInteger('min_commission_paise')->nullable()->after('point_value_paise');
            $table->unsignedBigInteger('cap_paise')->nullable()->after('min_commission_paise');
        });
    }

    public function down(): void
    {
        Schema::table('fortune_bonus_results', function (Blueprint $table): void {
            $table->dropColumn(['min_commission_paise', 'cap_paise']);
        });
    }
};
