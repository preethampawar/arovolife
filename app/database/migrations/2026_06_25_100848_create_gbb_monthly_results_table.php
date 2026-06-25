<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gbb_monthly_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('distributor_id')->constrained('distributors')->cascadeOnDelete();
            $table->date('year_month');                         // always first day of month, e.g. 2026-06-01
            $table->unsignedInteger('agp_earned')->default(0); // AGP earned by this distributor (capped at 120)
            $table->unsignedBigInteger('company_turnover_paise')->default(0); // total company sales revenue for month
            $table->unsignedBigInteger('pool_paise')->default(0);             // 5% of turnover
            $table->unsignedInteger('total_pool_agp')->default(0);            // total AGP across all eligible distributors
            $table->unsignedInteger('gbb_gross_paise')->default(0);
            $table->unsignedInteger('tds_paise')->default(0);
            $table->unsignedInteger('gbb_net_paise')->default(0);
            $table->enum('status', ['pending', 'credited', 'reversed'])->default('pending');
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            $table->unique(['distributor_id', 'year_month']);
            $table->index('year_month');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gbb_monthly_results');
    }
};
