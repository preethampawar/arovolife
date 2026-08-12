<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Run log for the ten compensation engines (2026-08-12).
 *
 * Until now the only proof an engine had run for a period was the presence of
 * its result rows, which cannot distinguish "ran and produced nothing" from
 * "never ran". Every invocation — cron, developer CLI, or the admin Engine Runs
 * page — is now recorded here by the RecordEngineRun console listener, which
 * also gives the dependency resolver a reliable "is this period computed?"
 * signal and the admin UI a last-run column.
 *
 * No DB enums: the test suite runs on SQLite, where widening an enum needs a
 * table rebuild. Statuses are plain strings validated by the EngineRun model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engine_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('engine_key', 64);
            // The date for date-period engines; the first day of the month for
            // month-period engines. One column keeps queries uniform.
            $table->date('period_start');
            $table->string('status', 16);   // running | succeeded | failed | skipped
            $table->string('trigger', 16);  // console | manual | dependency
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // Groups one admin trigger with the dependency runs it pulled in.
            $table->uuid('chain_id')->nullable();
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['engine_key', 'period_start'], 'idx_engine_runs_key_period');
            $table->index(['engine_key', 'started_at'], 'idx_engine_runs_key_started');
            $table->index('chain_id', 'idx_engine_runs_chain');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engine_runs');
    }
};
