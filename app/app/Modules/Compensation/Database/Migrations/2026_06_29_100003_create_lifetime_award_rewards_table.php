<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The itemised Lifetime Awards & Rewards catalogue per rank (KP 2026-06-28).
     * Each row is one reward item a rank earns (e.g. "15 Lakh accident
     * insurance"), with its worth in paise. Admin-editable; the item worths
     * reconcile to the rank's lifetime_award_budget_paise.
     */
    public function up(): void
    {
        Schema::create('lifetime_award_rewards', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('rank_number');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->string('item', 255);
            $table->unsignedBigInteger('worth_paise')->default(0);
            $table->timestamps();

            $table->unique(['rank_number', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifetime_award_rewards');
    }
};
