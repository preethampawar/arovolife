<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 9 rank tiers. Replaces RankQualification's RANK_NAMES, POOL_PCT,
     * PYP_REQUIRED, PERSONAL_BV_REQUIRED, GROUP_BV_REQUIRED consts and the
     * structural "2 qualifiers per side" literal for higher ranks.
     */
    public function up(): void
    {
        Schema::create('rank_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('rank_number')->unique();
            $table->string('rank_name');
            $table->decimal('pool_pct', 5, 2);
            $table->unsignedTinyInteger('pyp_required');
            $table->unsignedBigInteger('personal_bv_required_paise');
            $table->unsignedBigInteger('group_bv_required_paise')->nullable();
            $table->unsignedTinyInteger('structural_qualifiers_per_side')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_tiers');
    }
};
