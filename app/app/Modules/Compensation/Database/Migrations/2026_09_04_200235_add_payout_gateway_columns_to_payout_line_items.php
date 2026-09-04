<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gateway-dispatch columns for the Razorpay Payouts (RazorpayX) mode.
 *
 * A line item now carries the whole trail of one bank transfer: the contact
 * and fund account it was sent to, the payout id the webhook comes back on,
 * the rail it used, and how many times ops re-tried it. Manual-NEFT batches
 * simply leave every one of them null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_line_items', function (Blueprint $table): void {
            $table->string('razorpay_payout_id', 60)->nullable()->after('utr_number');
            $table->string('razorpay_contact_id', 60)->nullable()->after('razorpay_payout_id');
            $table->string('razorpay_fund_account_id', 60)->nullable()->after('razorpay_contact_id');
            $table->enum('transfer_mode', ['neft', 'imps', 'rtgs', 'upi'])->nullable()->after('razorpay_fund_account_id');
            $table->unsignedTinyInteger('retry_count')->default(0)->after('transfer_mode');
            $table->timestamp('last_retried_at')->nullable()->after('retry_count');
            $table->timestamp('dispatched_at')->nullable()->after('last_retried_at');

            $table->index('razorpay_payout_id', 'idx_payout_line_rzp_payout');
        });
    }

    public function down(): void
    {
        Schema::table('payout_line_items', function (Blueprint $table): void {
            $table->dropIndex('idx_payout_line_rzp_payout');
            $table->dropColumn([
                'razorpay_payout_id',
                'razorpay_contact_id',
                'razorpay_fund_account_id',
                'transfer_mode',
                'retry_count',
                'last_retried_at',
                'dispatched_at',
            ]);
        });
    }
};
