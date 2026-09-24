<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The courier's own last word on the parcel, verbatim ("RTO INITIATED",
 * "LOST", "OUT FOR DELIVERY").
 *
 * `status` is our state machine and only moves forward through the few states
 * we act on. A courier has dozens, and several of them — a return starting, a
 * parcel lost — are exactly what staff need to see before our own status could
 * ever reflect them. Written only from an API-verified tracking answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('courier_status', 40)->nullable()->after('label_url');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn('courier_status');
        });
    }
};
