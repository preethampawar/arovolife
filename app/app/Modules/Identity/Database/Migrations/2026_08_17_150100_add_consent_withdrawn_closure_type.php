<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `consent_withdrawn` to `users.closure_type` (C-06, R-52).
 *
 * The enum had `cooling_off_cancellation` and `admin_termination`. A consent
 * withdrawal is neither: it is the distributor's own decision, taken outside
 * the cooling-off window, and recording it as an admin termination would put
 * the company's name on a closure the company did not choose — which matters
 * the first time somebody asks why their ADN was closed.
 *
 * SQLite gets a plain string, as elsewhere: `$table->enum()` emits a CHECK
 * constraint there, so the widening has to happen on both drivers or the tests
 * pass against a schema production does not have.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY closure_type ENUM('cooling_off_cancellation', 'admin_termination', 'consent_withdrawn') NULL");

            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('closure_type', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY closure_type ENUM('cooling_off_cancellation', 'admin_termination') NULL");
        }
    }
};
