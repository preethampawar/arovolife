<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KP's final Q&A (2026-06-27) confirmed the 3% admin charge applies to ALL seven
 * bonuses, including the Arete Development Center Bonus (previously exempt).
 * Add the column so the ADC result records its admin charge like the others.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adc_bonus_results', function (Blueprint $table): void {
            $table->integer('admin_charge_paise')->default(0)->after('gross_paise');
        });
    }

    public function down(): void
    {
        Schema::table('adc_bonus_results', function (Blueprint $table): void {
            $table->dropColumn('admin_charge_paise');
        });
    }
};
