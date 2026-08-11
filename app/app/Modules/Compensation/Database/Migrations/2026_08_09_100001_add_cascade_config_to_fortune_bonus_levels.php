<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KP 2026-08-09 Fortune cascade: each ABSOLUTE matrix level now carries a
 * payout mode — 'capped' (per-level point value with a per-member rupee
 * ceiling), 'residual' (one shared point value over the residual levels'
 * combined points, no cap) or 'flat_min' (the ₹30 minimum only) — and, for
 * capped levels, the per-member cap in paise. The cap INCLUDES the ₹30
 * minimum ("maximum of ₹30,000, including their personal account ₹30").
 *
 * A plain string column, not an enum: SQLite CHECK constraints make enum
 * widening painful (see the GBB engine note); values are validated at the
 * admin form and unknown modes fall back to 'capped' in the engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fortune_bonus_levels', function (Blueprint $table): void {
            $table->string('payout_mode', 16)->default('capped')->after('points_per_member');
            $table->unsignedBigInteger('cap_paise')->nullable()->after('payout_mode');
        });
    }

    public function down(): void
    {
        Schema::table('fortune_bonus_levels', function (Blueprint $table): void {
            $table->dropColumn(['payout_mode', 'cap_paise']);
        });
    }
};
