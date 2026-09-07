<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The client's 2026-09-06 repurchase spec makes a cycle a verdict on TWO
     * conditions, not one: the window's self-purchase BV must clear the
     * distributor's obligation AND their repurchase wallet must stand at ₹0 on
     * the window's last day (rule 4).
     *
     * The wallet half only has an answer on a date that has already passed, so
     * it is frozen onto the cycle row the moment the window closes — the same
     * discipline `repurchase_monthly_snapshots` gave the old calendar-month
     * gate, moved onto the row whose window it actually belongs to. Every
     * engine now reads one verdict for one deadline instead of two.
     *
     * `fulfilled_on` is what makes eligibility answerable for a PAST date: a
     * cycle fulfilled after its due date was suspended in between, and a re-run
     * of that month has to reach the same conclusion the run that paid it did.
     */
    public function up(): void
    {
        Schema::table('repurchase_cycles', function (Blueprint $table): void {
            $table->unsignedBigInteger('wallet_balance_paise')->nullable()->after('completed_bv_paise');
            $table->boolean('wallet_zeroed')->nullable()->after('wallet_balance_paise');
            $table->date('fulfilled_on')->nullable()->after('status');
            // null | bv_short | wallet_nonzero | both
            $table->string('failure_reason', 16)->nullable()->after('fulfilled_on');
            $table->timestamp('resolved_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('repurchase_cycles', function (Blueprint $table): void {
            $table->dropColumn([
                'wallet_balance_paise',
                'wallet_zeroed',
                'fulfilled_on',
                'failure_reason',
                'resolved_at',
            ]);
        });
    }
};
