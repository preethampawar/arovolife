<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last day a weekly batch actually paid for, stamped on the batch itself.
 *
 * Recomputing the Wednesday→Tuesday window from a stored batch_date would
 * invent an earning week for batches that never had one — every legacy
 * `gsb_weekly` batch, and every `weekly` batch that ran before the rule was
 * deployed, swept whatever the wallet held on the batch date. Those rows keep
 * NULL by construction and the reports show "—" for them; only batches created
 * by the current PayoutService carry a date.
 *
 * Nullable and untouched for monthly batches, which pay a calendar month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_batches', function (Blueprint $table): void {
            $table->date('earnings_through')->nullable()->after('batch_date');
        });
    }

    public function down(): void
    {
        Schema::table('payout_batches', function (Blueprint $table): void {
            $table->dropColumn('earnings_through');
        });
    }
};
