<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The GSB slab ladder. One row per slab (1–7). Merges three formerly
     * hardcoded structures: GsbCutoffService::SLABS (matched BV → bonus),
     * PersonalBvTitleService::LADDER (personal BV → title + slab cap) and
     * GbbMonthlyResult::AGP_BY_SLAB (AGP awarded per slab occurrence).
     */
    public function up(): void
    {
        Schema::create('gsb_slabs', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('slab')->unique();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('title_min_bv_paise');
            $table->unsignedBigInteger('matched_bv_paise');
            $table->unsignedInteger('score')->nullable();
            $table->unsignedBigInteger('bonus_paise')->nullable();
            $table->unsignedInteger('agp_per_occurrence')->default(0);
            $table->boolean('carry_forward_lifetime')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsb_slabs');
    }
};
