<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fortune_bonus_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->date('month_start');
            $table->unsignedInteger('position');
            $table->unsignedTinyInteger('matrix_level');
            $table->integer('gross_paise')->default(0);
            $table->integer('tds_paise')->default(0);
            $table->integer('net_paise')->default(0);
            $table->enum('status', ['pending', 'credited', 'skipped'])->default('pending');
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            $table->unique(['distributor_id', 'month_start'], 'uniq_fb_result');
            $table->foreign('distributor_id', 'fk_fbr_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fortune_bonus_results');
    }
};
