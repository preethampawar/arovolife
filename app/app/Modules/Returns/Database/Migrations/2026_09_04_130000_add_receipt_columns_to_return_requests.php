<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cooling-off refund is paid out — and the points and repurchase credit
 * given back — only once the returned goods are in hand (terms §8: "within
 * seven business days of arovolife receiving the returned product").
 * These columns record that receipt and hold the exact entitlement amounts
 * RefundOrder computed, so the release restores what was withheld and
 * nothing else. Additive; the status enum is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->dateTime('received_at', 3)->nullable()->after('status');
            $table->foreignId('received_by_user_id')->nullable()->after('received_at')->constrained('users')->nullOnDelete();
            $table->string('receipt_outcome', 16)->nullable()->after('received_by_user_id'); // received | courier_lost | not_returned
            $table->text('receipt_note')->nullable()->after('receipt_outcome');
            $table->bigInteger('entitlement_points_paise')->default(0)->after('receipt_note');
            $table->bigInteger('entitlement_credit_paise')->default(0)->after('entitlement_points_paise');
            $table->dateTime('entitlements_held_at', 3)->nullable()->after('entitlement_credit_paise');
            $table->dateTime('entitlements_restored_at', 3)->nullable()->after('entitlements_held_at');
            // The two clocks on a held refund: ops alerted at 10 days without
            // receipt, escalated to the Grievance Officer at 21.
            $table->dateTime('hold_alert_sent_at', 3)->nullable()->after('entitlements_restored_at');
            $table->dateTime('hold_escalated_at', 3)->nullable()->after('hold_alert_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('received_by_user_id');
            $table->dropColumn([
                'received_at', 'receipt_outcome', 'receipt_note',
                'entitlement_points_paise', 'entitlement_credit_paise', 'entitlements_held_at', 'entitlements_restored_at',
                'hold_alert_sent_at', 'hold_escalated_at',
            ]);
        });
    }
};
