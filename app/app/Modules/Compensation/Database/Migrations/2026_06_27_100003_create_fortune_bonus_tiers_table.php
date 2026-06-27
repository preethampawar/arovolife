<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fortune Bonus per-tier enrolment gates (personal BV + GSB slabs).
     * Replaces FortuneBonusParticipant::BV_REQUIRED_PAISE and SLABS_REQUIRED.
     */
    public function up(): void
    {
        Schema::create('fortune_bonus_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('tier')->unique();
            $table->unsignedBigInteger('bv_required_paise');
            $table->unsignedTinyInteger('slabs_required');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fortune_bonus_tiers');
    }
};
