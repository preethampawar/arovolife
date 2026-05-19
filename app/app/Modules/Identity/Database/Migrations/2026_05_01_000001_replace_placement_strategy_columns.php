<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0003 — referral-link single-level placement.
 *
 * The `placement_strategy_snapshot` column is dropped (no historical
 * reinterpretation needed; the rule is now invariant). The `side_chosen_by`
 * enum is rewritten to reflect the new referral-link slot resolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // 1. Widen the enum to a superset of old + new values, so the
            //    UPDATEs in step 2 don't fail with "Data truncated".
            DB::statement("ALTER TABLE distributors MODIFY side_chosen_by ENUM(
                'admin_default', 'sponsor_override', 'prospect_custom',
                'referral_explicit', 'referral_default', 'referral_fallback_right'
            ) NOT NULL");

            // 2. Map the old enum values to the new ones.
            DB::statement("UPDATE distributors SET side_chosen_by = 'referral_default'   WHERE side_chosen_by = 'admin_default'");
            DB::statement("UPDATE distributors SET side_chosen_by = 'referral_explicit'  WHERE side_chosen_by IN ('sponsor_override', 'prospect_custom')");

            // 3. Narrow the enum to just the three new values.
            DB::statement("ALTER TABLE distributors MODIFY side_chosen_by ENUM('referral_explicit', 'referral_default', 'referral_fallback_right') NOT NULL");
        } else {
            // SQLite path — enums are stored as TEXT with a CHECK constraint.
            // The simplest portable rewrite is to drop and recreate the
            // column with the new value set. There are no production rows
            // on SQLite (it's the test-only driver), so the lossy mapping
            // done by the MySQL branch doesn't matter here — we just need
            // the destination schema to accept the new values.
            Schema::table('distributors', static function (Blueprint $table): void {
                $table->dropColumn('side_chosen_by');
            });
            Schema::table('distributors', static function (Blueprint $table): void {
                $table->enum('side_chosen_by', [
                    'referral_explicit', 'referral_default', 'referral_fallback_right',
                ])->after('placement_side');
            });
        }

        // 4. Drop the strategy snapshot column entirely. No replacement —
        //    placement is now invariant per ADR-0003.
        if (Schema::hasColumn('distributors', 'placement_strategy_snapshot')) {
            Schema::table('distributors', function ($table): void {
                $table->dropColumn('placement_strategy_snapshot');
            });
        }
    }

    public function down(): void
    {
        Schema::table('distributors', function ($table): void {
            $table->enum('placement_strategy_snapshot', ['default_left', 'default_right', 'custom'])
                ->default('default_left')
                ->after('placement_side');
        });

        if (DB::getDriverName() === 'mysql') {
            // Reverse-map the enum values, best-effort.
            DB::statement("UPDATE distributors SET side_chosen_by = 'admin_default'    WHERE side_chosen_by IN ('referral_default', 'referral_fallback_right')");
            DB::statement("UPDATE distributors SET side_chosen_by = 'sponsor_override' WHERE side_chosen_by = 'referral_explicit'");

            DB::statement("ALTER TABLE distributors MODIFY side_chosen_by ENUM('admin_default', 'sponsor_override', 'prospect_custom') NOT NULL");
        } else {
            Schema::table('distributors', static function (Blueprint $table): void {
                $table->dropColumn('side_chosen_by');
            });
            Schema::table('distributors', static function (Blueprint $table): void {
                $table->enum('side_chosen_by', ['admin_default', 'sponsor_override', 'prospect_custom'])
                    ->after('placement_side');
            });
        }
    }
};
