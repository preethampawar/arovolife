<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_aogo_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->date('month_start');
            // 1–3: which of the distributor's lifetime AO-GO uses this is.
            $table->unsignedTinyInteger('grant_number');
            $table->unsignedSmallInteger('points');
            // Highest rank the distributor ever held before degrading (audit).
            $table->unsignedTinyInteger('previous_rank_number');
            // Snapshotted at credit time by the monthly Rank Bonus run.
            $table->unsignedBigInteger('point_value_paise')->nullable();
            $table->unsignedBigInteger('income_paise')->nullable();
            $table->enum('status', ['granted', 'credited', 'voided'])->default('granted');
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            $table->unique(['distributor_id', 'month_start'], 'uq_aogo_dist_month');
            $table->index(['distributor_id', 'grant_number'], 'idx_aogo_dist_grant');
            $table->index('month_start', 'idx_aogo_month');

            $table->foreign('distributor_id', 'fk_aogo_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_aogo_grants');
    }
};
