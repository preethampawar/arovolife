<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adc_bonus_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('center_id');
            $table->unsignedBigInteger('distributor_id');
            $table->date('month_start');
            $table->unsignedInteger('member_count')->default(0);
            $table->bigInteger('total_member_bv_paise')->default(0);
            $table->integer('gross_paise')->default(0);
            $table->integer('tds_paise')->default(0);
            $table->integer('net_paise')->default(0);
            $table->enum('status', ['pending', 'credited', 'reversed'])->default('pending');
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            $table->unique(['center_id', 'month_start'], 'uniq_adc_center_month');
            $table->index('distributor_id');
            $table->foreign('center_id', 'fk_adc_center')->references('id')->on('arete_centers')->cascadeOnDelete();
            $table->foreign('distributor_id', 'fk_adc_dist')->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adc_bonus_results');
    }
};
