<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fortune Bonus 3×9 matrix payout per level (0–9).
     * Replaces FortuneBonusParticipant::LEVEL_BONUS_PAISE.
     */
    public function up(): void
    {
        Schema::create('fortune_bonus_levels', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('level')->unique();
            $table->unsignedBigInteger('bonus_paise');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fortune_bonus_levels');
    }
};
