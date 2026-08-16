<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly franchise commission results.
 *
 * One row per franchise per month, mirroring `adc_bonus_results`: the run is
 * idempotent against it, and the rate is snapshotted so a later plan edit
 * cannot silently restate what was paid.
 *
 * `order_count` and `base_paise` are stored rather than recomputed because
 * hard rule 2 requires every credit to trace to product sales, and "which
 * sales" has to still be answerable after the orders have moved on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franchise_commission_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('franchise_id')->constrained('franchises')->cascadeOnDelete();
            $table->foreignId('distributor_id')->constrained('distributors')->restrictOnDelete();
            $table->date('month_start');

            $table->unsignedInteger('order_count')->default(0);
            // Net product value of the month's settled orders: subtotal LESS
            // the GST included in it LESS discount. Catalogue prices are
            // GST-inclusive, so the tax has to come out before the rate is
            // applied; shipping is excluded as a pass-through cost.
            $table->bigInteger('base_paise')->default(0);
            $table->unsignedSmallInteger('rate_bp');
            $table->bigInteger('gross_paise')->default(0);

            $table->enum('status', ['pending', 'credited', 'skipped'])->default('pending');
            $table->dateTime('credited_at', 3)->nullable();

            $table->timestamps();

            $table->unique(['franchise_id', 'month_start'], 'uniq_franchise_month');
            $table->index(['month_start', 'status'], 'idx_franchise_results_month_status');
        });

        // The wallet ledger needs a type for this stream. SQLite emits a CHECK
        // constraint for enum columns, so the widening has to happen on both
        // drivers or the tests pass against a schema production does not have.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','franchise_credit','payout_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal') NOT NULL");
        } else {
            Schema::table('wallet_ledger_entries', function (Blueprint $table): void {
                $table->string('type', 32)->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','fortune_credit','adc_credit','awards_credit','payout_debit','repurchase_deduction','rank_cap_forfeit','income_cap_forfeit','manual_credit','reversal') NOT NULL");
        }

        Schema::dropIfExists('franchise_commission_results');
    }
};
