<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_qualifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->unsignedTinyInteger('rank_number');
            $table->date('month_start');
            $table->unsignedBigInteger('left_genos_bv_paise')->nullable();
            $table->unsignedBigInteger('right_genos_bv_paise')->nullable();
            $table->unsignedTinyInteger('occurrence_in_month')->default(1);
            $table->boolean('is_carry_forward')->default(false);
            $table->date('carry_forward_from_month')->nullable();
            $table->enum('status', ['qualified', 'voided'])->default('qualified');
            $table->timestamps();

            $table->unique(
                ['distributor_id', 'rank_number', 'month_start', 'occurrence_in_month'],
                'uq_rank_qual_dist_rank_month_occ',
            );
            $table->index(['distributor_id', 'month_start'], 'idx_rank_qual_dist_month');
            $table->index(['month_start', 'rank_number', 'status'], 'idx_rank_qual_month_rank_status');

            $table->foreign('distributor_id', 'fk_rank_qual_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_qualifications');
    }
};
