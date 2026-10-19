<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The reserved company accounts are seeded without any identity number — no
 * PAN, no Aadhaar reference — until each is assigned to a leader who brings
 * their own. `pan_hash` and `pan_last4` were NOT NULL, which forced the seeder
 * to invent a synthetic `ARVO…` PAN; both now accept NULL.
 *
 * Hard rule 6 is unchanged: `uniq_distributors_pan_hash` still rejects a
 * second distributor with the same PAN, and a unique index admits any number
 * of NULLs. Registration and the admin edit path still always write a PAN.
 *
 * SQLite never had the NOT NULL on `pan_hash` (see the create migration);
 * `pan_last4` there is relaxed through the schema builder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE distributors MODIFY pan_hash BINARY(32) NULL');
            DB::statement('ALTER TABLE distributors MODIFY pan_last4 CHAR(4) NULL');

            return;
        }

        Schema::table('distributors', function (Blueprint $table): void {
            $table->char('pan_last4', 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Forward-only: rows seeded without a PAN would block restoring NOT NULL.
    }
};
