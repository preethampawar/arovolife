<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real wall-clock duration for an engine run.
 *
 * started_at/finished_at deliberately carry the *replayed* instant — the
 * compensation replay travels the clock so rows land on the date the scheduler
 * would have written them — which makes their difference 0 for every replayed
 * run. Without a clock-independent measure there is no way to see which engine
 * a slow replay is actually spending its time in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_runs', function (Blueprint $table): void {
            $table->unsignedInteger('duration_ms')->nullable()->after('finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('engine_runs', function (Blueprint $table): void {
            $table->dropColumn('duration_ms');
        });
    }
};
