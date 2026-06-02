<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purge PV from the catalogue. PV (`pv_paise`) was never shown to users and
 * never consumed by any logic — the platform is BV-only. See the BV-only
 * decision in CLAUDE.md / the Genos+BV terminology note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('pv_paise');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->bigInteger('pv_paise')->default(0);
        });
    }
};
