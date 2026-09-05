<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Month-end photograph of every distributor's repurchase wallet.
     *
     * The bonus engines gate on "was the repurchase wallet spent down to ₹0",
     * which is a statement about a month that has already closed. Reading the
     * live balance at engine time answers a different question every time it is
     * re-run, so the answer is frozen once, on the 1st, and every engine reads
     * the same row afterwards. It is also the audit record of why a distributor
     * was excluded from a month they can no longer reconstruct.
     */
    public function up(): void
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

    public function down(): void
    {
        Schema::dropIfExists('repurchase_monthly_snapshots');
    }
};
