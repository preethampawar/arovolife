<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freeze the credit-time repurchase deduction onto each bonus result row.
 *
 * The four repurchase-participating engines (GSB, Rank, GBB, Fortune) credit
 * through WalletService::creditWithRepurchaseDeduction(), which is the only
 * place the deduction is computed. The engines now copy that figure here and
 * set the row's net column to gross − deduction ("credited to wallet"), so
 * every bonus page reads one stored fact instead of re-deriving it from the
 * ledger. Admin charge and TDS are payout-time figures and live only on
 * payout_line_items; the old admin/TDS columns on these tables stay at zero.
 */
return new class extends Migration
{
    private const TABLES = [
        'gsb_cutoff_results' => 'gross_gsb_paise',
        'rank_bonus_results' => 'gross_paise',
        'gbb_monthly_results' => 'gbb_gross_paise',
        'fortune_bonus_results' => 'gross_paise',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $grossColumn) {
            Schema::table($table, function (Blueprint $blueprint) use ($grossColumn): void {
                $blueprint->unsignedBigInteger('repurchase_deduction_paise')->default(0)->after($grossColumn);
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('repurchase_deduction_paise');
            });
        }
    }
};
