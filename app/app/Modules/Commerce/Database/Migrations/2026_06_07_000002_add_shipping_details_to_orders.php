<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture courier + tracking number when an order is marked shipped, so the
 * admin can record it and the buyer can see how their order is travelling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('ship_carrier', 120)->nullable()->after('ship_pincode');
            $table->string('ship_tracking_no', 120)->nullable()->after('ship_carrier');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['ship_carrier', 'ship_tracking_no']);
        });
    }
};
