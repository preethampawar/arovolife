<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds gross/admin-charge/TDS breakdown columns to mentorship_bonus_results.
 * Previously mb_paise was the raw percentage of sponsee GSB (no deductions applied).
 * After this migration mb_paise is the NET amount credited to the sponsor wallet;
 * the deductions are stored separately for audit/finance reporting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mentorship_bonus_results', function (Blueprint $table) {
            $table->bigInteger('mb_gross_paise')->default(0)->after('mb_rate_pct');
            $table->bigInteger('mb_admin_charge_paise')->default(0)->after('mb_gross_paise');
            $table->bigInteger('mb_tds_paise')->default(0)->after('mb_admin_charge_paise');
        });
    }

    public function down(): void
    {
        Schema::table('mentorship_bonus_results', function (Blueprint $table) {
            $table->dropColumn(['mb_gross_paise', 'mb_admin_charge_paise', 'mb_tds_paise']);
        });
    }
};
