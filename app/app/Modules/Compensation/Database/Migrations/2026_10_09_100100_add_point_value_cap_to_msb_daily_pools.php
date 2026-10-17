<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client 2026-10-09: the MSB point value is capped (default ₹120). Both the
     * uncapped floored value and the cap in force are frozen on the day's pool
     * row so a later setting change never moves a frozen day. Rows frozen before
     * this release keep NULL in both columns (they were priced without a cap).
     */
    public function up(): void
    {
        Schema::table('msb_daily_pools', function (Blueprint $table): void {
            $table->unsignedBigInteger('raw_point_value_paise')->nullable()->after('point_value_paise');
            $table->unsignedBigInteger('point_value_cap_paise')->nullable()->after('raw_point_value_paise');
        });
    }

    public function down(): void
    {
        Schema::table('msb_daily_pools', function (Blueprint $table): void {
            $table->dropColumn(['raw_point_value_paise', 'point_value_cap_paise']);
        });
    }
};
