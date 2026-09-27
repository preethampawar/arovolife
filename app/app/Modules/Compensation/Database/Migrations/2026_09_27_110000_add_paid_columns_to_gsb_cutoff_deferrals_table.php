<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the backfill actually paid for a deferred day, beside what the deferring
 * night reserved (E5 review N1, 2026-09-27).
 *
 * The reservation is computed on the stale verdict and on the store the owed
 * days would leave; a title crossed, a sponsor crossing the MB minimum or a
 * group-BV reversal in between can still make the backfill pay more. These
 * columns let the backfill record the excess instead of it going unseen:
 * `exceeded_reservation_at` is set, and an audit_log row and the next digest
 * name it. Nullable throughout — an open row, and every row resolved before
 * this migration, has paid nothing recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gsb_cutoff_deferrals', function (Blueprint $table) {
            $table->unsignedBigInteger('paid_gsb_paise')->nullable()->after('reserved_msb_points');
            $table->unsignedInteger('paid_msb_points')->nullable()->after('paid_gsb_paise');
            $table->timestamp('exceeded_reservation_at')->nullable()->after('paid_msb_points');
        });
    }

    public function down(): void
    {
        Schema::table('gsb_cutoff_deferrals', function (Blueprint $table) {
            $table->dropColumn(['paid_gsb_paise', 'paid_msb_points', 'exceeded_reservation_at']);
        });
    }
};
