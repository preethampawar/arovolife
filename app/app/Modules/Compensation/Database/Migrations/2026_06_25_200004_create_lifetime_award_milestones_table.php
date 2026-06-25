<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifetime_award_milestones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->unsignedTinyInteger('rank_number');
            $table->date('triggered_month');
            $table->string('award_description');
            $table->enum('status', ['pending', 'delivered', 'cancelled'])->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['distributor_id', 'rank_number'],
                'uq_lifetime_award_dist_rank',
            );
            $table->index('distributor_id', 'idx_lifetime_award_dist');
            $table->index('status', 'idx_lifetime_award_status');

            $table->foreign('distributor_id', 'fk_lifetime_award_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifetime_award_milestones');
    }
};
