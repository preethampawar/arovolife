<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Records which Genos side the power carry-forward sat on BEFORE the cut-off
// ran, alongside the existing power_cf_before_paise amount. Together they let
// a re-run of the same date rewind the rolling gsb_carryforwards store to its
// pre-run state instead of compounding the day's BV into CF a second time
// (the "Retry is safe" guarantee in Manual Controls). Nullable: rows written
// before this column existed can't be rewound side-accurately and fall back
// to the store's current side.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gsb_cutoff_results', function (Blueprint $table): void {
            $table->string('power_side_before', 1)->nullable()->after('power_cf_before_paise');
        });
    }

    public function down(): void
    {
        Schema::table('gsb_cutoff_results', function (Blueprint $table): void {
            $table->dropColumn('power_side_before');
        });
    }
};
