<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rank_bonus_results', function (Blueprint $table) {
            // Points snapshot for ranks distributed by points (KP 2026-08-05:
            // Rank 1 only). Legacy equal-split rows keep nulls, same pattern as
            // the MSB legacy-ladder null snapshots.
            $table->unsignedSmallInteger('rap_points')->nullable()->after('qualifier_count');
            $table->unsignedSmallInteger('aogo_points')->nullable()->after('rap_points');
            $table->unsignedInteger('total_points')->nullable()->after('aogo_points');
            $table->unsignedBigInteger('point_value_paise')->nullable()->after('total_points');
        });

        // 'requalification_held': the distributor re-qualified but failed the
        // month's requalification conditions (rank's repurchase BV + wallet
        // cleared) — recorded, never credited (KP §8, confirmed 2026-08-05).
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE rank_bonus_results MODIFY COLUMN status ENUM('pending','credited','reversed','requalification_held') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE rank_bonus_results MODIFY COLUMN status ENUM('pending','credited','reversed') NOT NULL DEFAULT 'pending'");
        }

        Schema::table('rank_bonus_results', function (Blueprint $table) {
            $table->dropColumn(['rap_points', 'aogo_points', 'total_points', 'point_value_paise']);
        });
    }
};
