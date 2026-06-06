<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0007 — optional binary spillover.
 *
 * Adds three `side_chosen_by` enum values used when the (admin-toggled)
 * spillover engine places a joiner BELOW the link's placement target:
 *   - `spillover_left`     — directed into the target's left subtree
 *   - `spillover_right`    — directed into the target's right subtree
 *   - `spillover_balanced` — no side given; shallowest open slot under target
 *
 * The existing referral_* values are retained (toggle-off path, and the
 * toggle-on case where the joiner still landed at the immediate target slot).
 */
return new class extends Migration
{
    private const REFERRAL_VALUES = "'referral_explicit', 'referral_default', 'referral_fallback_right'";

    private const SPILLOVER_VALUES = "'spillover_left', 'spillover_right', 'spillover_balanced'";

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE distributors MODIFY side_chosen_by ENUM('
                .self::REFERRAL_VALUES.', '.self::SPILLOVER_VALUES.') NOT NULL');
        } else {
            // SQLite (test driver): enums are TEXT + CHECK. Drop and recreate
            // with the widened value set. No production rows on SQLite.
            Schema::table('distributors', static function (Blueprint $table): void {
                $table->dropColumn('side_chosen_by');
            });
            Schema::table('distributors', static function (Blueprint $table): void {
                $table->enum('side_chosen_by', [
                    'referral_explicit', 'referral_default', 'referral_fallback_right',
                    'spillover_left', 'spillover_right', 'spillover_balanced',
                ])->after('placement_side');
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Best-effort reverse map so the narrowed enum doesn't truncate any
            // spillover rows, then narrow back to the referral-only set.
            DB::statement("UPDATE distributors SET side_chosen_by = 'referral_explicit' WHERE side_chosen_by IN ('spillover_left', 'spillover_right')");
            DB::statement("UPDATE distributors SET side_chosen_by = 'referral_default'  WHERE side_chosen_by = 'spillover_balanced'");
            DB::statement('ALTER TABLE distributors MODIFY side_chosen_by ENUM('.self::REFERRAL_VALUES.') NOT NULL');
        } else {
            Schema::table('distributors', static function (Blueprint $table): void {
                $table->dropColumn('side_chosen_by');
            });
            Schema::table('distributors', static function (Blueprint $table): void {
                $table->enum('side_chosen_by', [
                    'referral_explicit', 'referral_default', 'referral_fallback_right',
                ])->after('placement_side');
            });
        }
    }
};
