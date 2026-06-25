<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsb_cutoff_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->date('cutoff_date');
            $table->bigInteger('left_bv_paise')->default(0);
            $table->bigInteger('right_bv_paise')->default(0);
            $table->bigInteger('weaker_bv_paise')->default(0);
            $table->tinyInteger('slab')->unsigned()->nullable();
            $table->bigInteger('gross_gsb_paise')->default(0);
            $table->bigInteger('admin_charge_paise')->default(0);
            $table->bigInteger('tds_paise')->default(0);
            $table->bigInteger('net_gsb_paise')->default(0);
            $table->bigInteger('power_cf_before_paise')->default(0);
            $table->bigInteger('power_cf_after_paise')->default(0);
            $table->enum('power_side_after', ['L', 'R'])->nullable();
            $table->bigInteger('slab1_weaker_cf_before_paise')->default(0);
            $table->bigInteger('slab1_weaker_cf_after_paise')->default(0);
            $table->enum('status', [
                'no_match', 'calculated', 'credited', 'failed', 'frozen', 'below_600bv',
            ])->default('no_match');
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['distributor_id', 'cutoff_date'], 'uniq_gsb_cutoff');
            $table->index(['cutoff_date', 'status'], 'idx_gsb_cutoff_date_status');
            $table->foreign('distributor_id', 'fk_gsb_cutoff_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsb_cutoff_results');
    }
};
