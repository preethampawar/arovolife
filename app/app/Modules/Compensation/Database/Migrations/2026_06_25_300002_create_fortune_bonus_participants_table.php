<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fortune_bonus_participants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->date('month_start');
            $table->unsignedInteger('position');
            $table->unsignedTinyInteger('matrix_level');
            $table->string('eligibility_tier', 20);
            $table->date('first_gsb_date')->nullable();
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamps();

            $table->unique(['distributor_id', 'month_start'], 'uniq_fb_participant');
            $table->index(['month_start', 'position']);
            $table->foreign('distributor_id', 'fk_fbp_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fortune_bonus_participants');
    }
};
