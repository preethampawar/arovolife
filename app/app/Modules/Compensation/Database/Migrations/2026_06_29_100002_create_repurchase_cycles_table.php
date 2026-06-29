<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-distributor monthly repurchase cycle (KP 2026-06-27/28). The cycle is
     * anchored to the distributor's Retailer-achievement date; they must
     * complete `required_bv_paise` of self-purchase BV by `due_date` (else a
     * `grace_days` window, then suspension of GSB/Fortune/GBB — never MB/Rank).
     * One row per distributor per cycle window.
     */
    public function up(): void
    {
        Schema::create('repurchase_cycles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->date('cycle_start_date');
            $table->date('due_date');
            $table->date('grace_end_date');
            $table->unsignedBigInteger('required_bv_paise');
            $table->unsignedBigInteger('completed_bv_paise')->default(0);
            // active | grace | suspended | completed
            $table->string('status', 16)->default('active');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['distributor_id', 'cycle_start_date']);
            $table->index(['distributor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repurchase_cycles');
    }
};
