<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `internal_note` timeline kind.
 *
 * An investigator needs somewhere to record findings that name a third party —
 * usually another distributor — without publishing them to the complainant.
 * Without this kind the only options were to leak the third party's name or to
 * keep the investigation out of the statutory register entirely.
 *
 * Separate from the 100000 migration because that one has already run on dev
 * and staging; editing a migration that has run changes nothing there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ticket_events MODIFY COLUMN kind ENUM('status_change','comment','assignment','sla_breach','acknowledgement','escalation','attachment','notification','sla_extension','status_update','internal_note') NOT NULL");

            return;
        }

        // SQLite emits a CHECK constraint for enum columns, so the widening has
        // to happen there too or the test suite diverges from production. The
        // 100000 migration already dropped this column to a plain string on
        // SQLite; re-stating it is harmless and keeps a fresh SQLite build
        // correct whichever order the two run in.
        Schema::table('ticket_events', function (Blueprint $table): void {
            $table->string('kind', 32)->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ticket_events MODIFY COLUMN kind ENUM('status_change','comment','assignment','sla_breach','acknowledgement','escalation','attachment','notification','sla_extension','status_update') NOT NULL");
        }
    }
};
