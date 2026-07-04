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
        // Expand the allowed batch types to add 'weekly' and 'monthly'.
        // MySQL: widen the ENUM in place. Other drivers (SQLite in tests)
        // created batch_type as an enum-backed CHECK constraint limited to the
        // original values, so relax it to a plain string — otherwise inserting
        // a 'weekly'/'monthly' batch fails the CHECK.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payout_batches MODIFY COLUMN batch_type ENUM('gsb_weekly','manual','weekly','monthly') NOT NULL DEFAULT 'weekly'");
        } else {
            Schema::table('payout_batches', function (Blueprint $table) {
                $table->string('batch_type')->default('weekly')->change();
            });
        }

        Schema::table('payout_batches', function (Blueprint $table) {
            // Replace date-only unique with (date, type) so weekly and monthly
            // batches can coexist when the 8th falls on a Tuesday.
            try {
                $table->dropUnique('uniq_payout_batch_date');
            } catch (Throwable) {
                // Already removed (e.g. re-running on SQLite test DB).
            }

            $table->unique(['batch_date', 'batch_type'], 'uniq_payout_batch_date_type');
        });
    }

    public function down(): void
    {
        Schema::table('payout_batches', function (Blueprint $table) {
            try {
                $table->dropUnique('uniq_payout_batch_date_type');
            } catch (Throwable) {
            }

            $table->unique('batch_date', 'uniq_payout_batch_date');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payout_batches MODIFY COLUMN batch_type ENUM('gsb_weekly','manual') NOT NULL DEFAULT 'gsb_weekly'");
        }
    }
};
