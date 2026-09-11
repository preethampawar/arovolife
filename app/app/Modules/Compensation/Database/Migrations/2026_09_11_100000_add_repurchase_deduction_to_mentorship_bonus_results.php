<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Mentorship Bonus becomes the fifth repurchase-deduction source (client,
 * 2026-09-10), alongside GSB, Rank, Growth Booster and Fortune.
 *
 * It therefore needs the same two frozen columns the other four carry: what
 * moved to the repurchase wallet at credit time, and what actually landed in
 * the main wallet. Every MSB page reads these stored facts rather than
 * re-deriving them from the ledger.
 *
 * Rows written before the decision took no deduction at all, so their credited
 * amount is their gross — backfilled here so no page has to special-case them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mentorship_bonus_results', function (Blueprint $table): void {
            if (! Schema::hasColumn('mentorship_bonus_results', 'repurchase_deduction_paise')) {
                $table->unsignedBigInteger('repurchase_deduction_paise')->default(0)->after('mb_gross_paise');
            }

            if (! Schema::hasColumn('mentorship_bonus_results', 'mb_net_paise')) {
                $table->bigInteger('mb_net_paise')->default(0)->after('mb_tds_paise');
            }
        });

        DB::table('mentorship_bonus_results')
            ->where('mb_net_paise', 0)
            ->where('mb_gross_paise', '>', 0)
            ->update(['mb_net_paise' => DB::raw('mb_gross_paise - repurchase_deduction_paise')]);
    }

    public function down(): void
    {
        Schema::table('mentorship_bonus_results', function (Blueprint $table): void {
            $table->dropColumn(['repurchase_deduction_paise', 'mb_net_paise']);
        });
    }
};
