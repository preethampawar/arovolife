<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual development-phase tracking on Arete Development Centers (KP
 * 2026-08-07). The phase (1–4) is judged on a single calendar month's ADC
 * income and upgraded by admin after the owner emails a letter + photos of
 * the developed center. `monthly_cap_override_paise` lets admin apply KP's
 * "lower slab income" penalty when a center crosses a phase level without
 * proving the upgrade — the engine pays min(override, standard cap).
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arete_centers', function (Blueprint $table): void {
            $table->unsignedTinyInteger('development_phase')->default(1)->after('status');
            $table->unsignedBigInteger('monthly_cap_override_paise')->nullable()->after('development_phase');
        });
    }

    public function down(): void
    {
        Schema::table('arete_centers', function (Blueprint $table): void {
            $table->dropColumn(['development_phase', 'monthly_cap_override_paise']);
        });
    }
};
