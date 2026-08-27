<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was missing before the document could call itself a tax invoice (R-28).
 *
 * The `invoices` table already carried the CGST/SGST/IGST columns and the
 * generator already split them. Three things were absent, and each on its own
 * is fatal to the document under CGST Rule 46:
 *
 *  - the **supplier's GSTIN** was never written (Rule 46(b));
 *  - the **recipient's GSTIN** was never captured, so a registered buyer could
 *    not claim input credit (Rule 46(e)); and
 *  - the invoice number was `timestamp % 1000000`, which is neither
 *    consecutive nor collision-free. Rule 46(b) requires a consecutive serial
 *    number unique within a financial year, and a number that can repeat is
 *    worse than no number at all — two supplies would carry one identity.
 *
 * The sequence table is the fix for the third: one row per financial year,
 * incremented under a row lock, so numbers are consecutive, gap-free and
 * unique by construction rather than by luck.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_number_sequences', function (Blueprint $table): void {
            // India's financial year runs April to March, so '2026-27' rather
            // than a calendar year.
            $table->string('financial_year', 7)->primary();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table): void {
            // A registered buyer's GSTIN, captured at checkout. Optional: most
            // buyers are consumers, and Rule 46(e) only requires it where the
            // recipient is registered.
            $table->string('buyer_gstin', 15)->nullable()->after('ship_pincode');
            $table->string('buyer_legal_name', 200)->nullable()->after('buyer_gstin');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['buyer_gstin', 'buyer_legal_name']);
        });

        Schema::dropIfExists('invoice_number_sequences');
    }
};
