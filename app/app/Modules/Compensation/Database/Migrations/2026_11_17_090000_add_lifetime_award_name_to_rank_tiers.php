<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each rank's cash Lifetime Award is announced as an item — a phone, a flight
 * ticket, a tour — worth the rank's award amount (the client, 2026-11-17).
 * The admin names it here; a milestone copies the name into its
 * award_description when it is earned. Blank = the default
 * "<rank> Lifetime Award" text. Schema only: no row moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rank_tiers', function (Blueprint $table): void {
            $table->string('lifetime_award_name', 100)->nullable()->after('lifetime_award_budget_paise');
        });
    }

    public function down(): void
    {
        Schema::table('rank_tiers', function (Blueprint $table): void {
            $table->dropColumn('lifetime_award_name');
        });
    }
};
