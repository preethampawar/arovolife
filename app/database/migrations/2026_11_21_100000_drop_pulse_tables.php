<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel Pulse was removed on 2026-11-21: recording had been switched off on
 * staging and production and nothing read the dashboard. Its create migration
 * extended a Pulse class, so it went with the package; this drops the tables
 * it left behind. Pulse held only 7-day telemetry, never business records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pulse_entries');
        Schema::dropIfExists('pulse_aggregates');
        Schema::dropIfExists('pulse_values');
    }

    /** Nothing to restore: the telemetry is gone and Pulse is no longer installed. */
    public function down(): void {}
};
