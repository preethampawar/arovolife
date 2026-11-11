<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Lifetime Award part of a monthly payout line (the client, 2026-11-10).
 * It is already inside gross_paise; this column lets the statement show the
 * award on its own line. The award carries TDS at the bonus rate inside
 * tds_paise, and no admin charge or repurchase deduction. Schema only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_line_items', function (Blueprint $table): void {
            $table->bigInteger('awards_gross_paise')->default(0)->after('tds_paise');
        });
    }

    public function down(): void
    {
        Schema::table('payout_line_items', function (Blueprint $table): void {
            $table->dropColumn('awards_gross_paise');
        });
    }
};
