<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The RazorpayX Contact id created for this distributor the first time a
 * payout was dispatched to them. Cached here so every later batch reuses the
 * same contact instead of asking Razorpay to create a duplicate.
 *
 * Not PII: an opaque gateway identifier (`cont_...`), no bank details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributors', function (Blueprint $table): void {
            $table->string('razorpay_contact_id', 60)->nullable()->after('bank_ifsc');
        });
    }

    public function down(): void
    {
        Schema::table('distributors', function (Blueprint $table): void {
            $table->dropColumn('razorpay_contact_id');
        });
    }
};
