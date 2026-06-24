<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BV accumulator columns are always non-negative — change signed to unsigned.
        Schema::table('group_bv_daily', function (Blueprint $table) {
            $table->unsignedBigInteger('left_bv_paise')->default(0)->change();
            $table->unsignedBigInteger('right_bv_paise')->default(0)->change();
        });

        Schema::table('gsb_carryforward', function (Blueprint $table) {
            $table->unsignedBigInteger('power_side_bv_paise')->default(0)->change();
            $table->unsignedBigInteger('slab1_weaker_bv_paise')->default(0)->change();
        });

        Schema::table('gsb_cutoff_results', function (Blueprint $table) {
            $table->unsignedBigInteger('left_bv_paise')->default(0)->change();
            $table->unsignedBigInteger('right_bv_paise')->default(0)->change();
            $table->unsignedBigInteger('weaker_bv_paise')->default(0)->change();
            $table->unsignedBigInteger('power_cf_before_paise')->default(0)->change();
            $table->unsignedBigInteger('power_cf_after_paise')->default(0)->change();
            $table->unsignedBigInteger('slab1_weaker_cf_before_paise')->default(0)->change();
            $table->unsignedBigInteger('slab1_weaker_cf_after_paise')->default(0)->change();
        });

        // Idempotency guard: prevent double-crediting a single (type, reference) source.
        // NULL reference_id / reference_type is intentional for manual_credit / reversal entries
        // and is treated as distinct by MySQL's unique index — correct behaviour.
        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            $table->unique(['type', 'reference_type', 'reference_id'], 'uniq_wallet_ledger_source');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            $table->dropUnique('uniq_wallet_ledger_source');
        });

        Schema::table('gsb_cutoff_results', function (Blueprint $table) {
            $table->bigInteger('left_bv_paise')->default(0)->change();
            $table->bigInteger('right_bv_paise')->default(0)->change();
            $table->bigInteger('weaker_bv_paise')->default(0)->change();
            $table->bigInteger('power_cf_before_paise')->default(0)->change();
            $table->bigInteger('power_cf_after_paise')->default(0)->change();
            $table->bigInteger('slab1_weaker_cf_before_paise')->default(0)->change();
            $table->bigInteger('slab1_weaker_cf_after_paise')->default(0)->change();
        });

        Schema::table('gsb_carryforward', function (Blueprint $table) {
            $table->bigInteger('power_side_bv_paise')->default(0)->change();
            $table->bigInteger('slab1_weaker_bv_paise')->default(0)->change();
        });

        Schema::table('group_bv_daily', function (Blueprint $table) {
            $table->bigInteger('left_bv_paise')->default(0)->change();
            $table->bigInteger('right_bv_paise')->default(0)->change();
        });
    }
};
