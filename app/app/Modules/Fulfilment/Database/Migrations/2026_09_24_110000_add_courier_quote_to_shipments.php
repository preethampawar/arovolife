<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The courier staff chose from Shiprocket's list, with the rate and delivery
 * days Shiprocket quoted at the moment of booking.
 *
 * What the company pays the courier, never what the buyer paid for shipping.
 * Null on a manual dispatch and on a booking Shiprocket assigned itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->unsignedInteger('courier_company_id')->nullable()->after('courier_status');
            $table->unsignedInteger('quoted_rate_paise')->nullable()->after('courier_company_id');
            $table->unsignedTinyInteger('quoted_etd_days')->nullable()->after('quoted_rate_paise');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn(['courier_company_id', 'quoted_rate_paise', 'quoted_etd_days']);
        });
    }
};
