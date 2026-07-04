<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // payout_line_items: the weekly/monthly batches record the gross, admin
        // charge and TDS per line. These columns were never added by a migration
        // (they exist on the dev DB only through out-of-band drift), so a fresh
        // deploy would crash the payout batches. Add them idempotently — the
        // hasColumn guards make this a no-op where drift already added them.
        Schema::table('payout_line_items', function (Blueprint $table) {
            if (! Schema::hasColumn('payout_line_items', 'gross_paise')) {
                $table->bigInteger('gross_paise')->default(0)->after('distributor_id');
            }
            if (! Schema::hasColumn('payout_line_items', 'admin_charge_paise')) {
                $table->bigInteger('admin_charge_paise')->default(0)->after('gross_paise');
            }
            if (! Schema::hasColumn('payout_line_items', 'tds_paise')) {
                $table->bigInteger('tds_paise')->default(0)->after('admin_charge_paise');
            }
        });

        // mentorship_bonus_results: mb_gross_paise already exists as a separate column
        // (added by 2026_06_25_500001_add_mb_deduction_columns). Drop the legacy
        // mb_paise (net) column — deductions are now handled at payout time, not
        // at credit time, so storing a stale per-event net here is misleading.
        // Guarded because dev-DB drift may have already removed it.
        if (Schema::hasColumn('mentorship_bonus_results', 'mb_paise')) {
            Schema::table('mentorship_bonus_results', function (Blueprint $table) {
                $table->dropColumn('mb_paise');
            });
        }
    }

    public function down(): void
    {
        Schema::table('mentorship_bonus_results', function (Blueprint $table) {
            $table->bigInteger('mb_paise')->default(0)->after('mb_tds_paise');
        });

        Schema::table('payout_line_items', function (Blueprint $table) {
            $table->dropColumn(['gross_paise', 'admin_charge_paise', 'tds_paise']);
        });
    }
};
