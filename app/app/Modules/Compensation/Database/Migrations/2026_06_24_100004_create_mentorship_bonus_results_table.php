<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mentorship_bonus_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sponsor_id');
            $table->unsignedBigInteger('sponsee_id');
            $table->date('cutoff_date');
            $table->bigInteger('sponsee_gsb_paise');
            $table->unsignedTinyInteger('mb_rate_pct');
            $table->bigInteger('mb_paise');
            $table->bigInteger('sponsee_cumulative_gsb_paise');
            $table->enum('status', ['credited', 'failed'])->default('credited');
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['sponsor_id', 'sponsee_id', 'cutoff_date'], 'uniq_mb_result');
            $table->index(['cutoff_date', 'sponsor_id'], 'idx_mb_result_date_sponsor');
            $table->foreign('sponsor_id', 'fk_mb_result_sponsor')
                ->references('id')->on('distributors')->cascadeOnDelete();
            $table->foreign('sponsee_id', 'fk_mb_result_sponsee')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentorship_bonus_results');
    }
};
