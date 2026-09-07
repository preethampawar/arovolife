<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The client's 2026-09-07 spec removes the grace window outright: a cycle
     * that misses its due date is failed from `due + 1`, with nothing in
     * between. `grace_end_date` is NOT NULL with no default, so leaving it in
     * place would break every insert once the engine stops writing it.
     *
     * The legacy `grace` value in `status` is left alone — those rows are
     * history, and `RepurchaseCycle::forfeitedWindow()` reads dates, not the
     * status column.
     */
    public function up(): void
    {
        Schema::table('repurchase_cycles', function (Blueprint $table): void {
            $table->dropColumn('grace_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('repurchase_cycles', function (Blueprint $table): void {
            $table->date('grace_end_date')->nullable()->after('due_date');
        });
    }
};
