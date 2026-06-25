<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_bonus_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->date('month_start');
            $table->unsignedTinyInteger('rank_number');
            $table->unsignedBigInteger('company_turnover_paise');
            $table->unsignedBigInteger('pool_paise');
            $table->unsignedInteger('qualifier_count');
            $table->unsignedBigInteger('gross_paise');
            $table->unsignedBigInteger('admin_charge_paise');
            $table->unsignedBigInteger('tds_paise');
            $table->unsignedBigInteger('net_paise');
            $table->enum('status', ['pending', 'credited', 'reversed'])->default('pending');
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['distributor_id', 'rank_number', 'month_start'],
                'uq_rank_result_dist_rank_month',
            );
            $table->index(['month_start', 'rank_number'], 'idx_rank_result_month_rank');
            $table->index(['distributor_id', 'month_start'], 'idx_rank_result_dist_month');

            $table->foreign('distributor_id', 'fk_rank_result_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_bonus_results');
    }
};
