<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The calendar-month repurchase wallet deadline is retired.
     *
     * This table froze "was the repurchase wallet ₹0 on the last day of the
     * CALENDAR month", which every bonus engine gated on. The client's
     * 2026-09-06 rule 4(B) moves that deadline onto the last day of the
     * distributor's OWN 30-day cycle, and the answer is now frozen on the
     * `repurchase_cycles` row whose window it belongs to
     * (`wallet_balance_paise` / `wallet_zeroed`). Keeping both would have
     * punished one unspent balance twice, against two different dates.
     *
     * The rows here are a derived freeze, rebuildable from
     * `wallet_ledger_entries`, and nothing reads them any more.
     */
    public function up(): void
    {
        Schema::dropIfExists('repurchase_monthly_snapshots');
    }

    public function down(): void
    {
        Schema::create('repurchase_monthly_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->date('cycle_month');
            $table->bigInteger('balance_paise');
            $table->boolean('was_zeroed');
            $table->dateTime('snapshotted_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['distributor_id', 'cycle_month']);

            $table->foreign('distributor_id')->references('id')->on('distributors')->cascadeOnDelete();
        });
    }
};
