<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Landed cost, part 3 — give the receipts that already exist a landed total.
 *
 * The columns added in 2026_09_14_100000 default to 0, which is right for a
 * new GRN and wrong for the ones already posted: their landed total is not
 * zero, it is their subtotal, because no charges were ever recorded against
 * them. Left at 0 the Trading Account reports "purchases ₹42,000, landed
 * purchases ₹0" — visibly broken, and worse, it would silently understate
 * cost of goods sold for every period before this feature shipped.
 *
 * Strictly additive: only rows still holding the 0 default are touched, so
 * re-running it cannot overwrite a real allocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No charges were captured on these receipts, so landed cost is the
        // supplier price — the honest restatement, not a guess at freight.
        DB::table('purchase_invoice_items')
            ->where('landed_unit_cost_paise', 0)
            ->where('unit_cost_paise', '>', 0)
            ->update([
                'landed_unit_cost_paise' => DB::raw('unit_cost_paise'),
                'allocated_charges_paise' => 0,
            ]);

        DB::table('purchase_invoices')
            ->where('landed_total_paise', 0)
            ->where('subtotal_paise', '>', 0)
            ->update(['landed_total_paise' => DB::raw('subtotal_paise')]);
    }

    public function down(): void
    {
        // Irreversible by design: there is no way to tell a backfilled value
        // from one a user entered, and zeroing both would lose real data.
    }
};
