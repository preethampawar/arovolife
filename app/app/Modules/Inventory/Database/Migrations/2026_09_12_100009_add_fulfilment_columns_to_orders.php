<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which warehouse an order was picked from, and when. `packed_at` is the flag
 * the cancel path reads to decide between releasing a reservation and
 * restocking real goods, so it is set only by the pack step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('warehouse_code', 32)->nullable();
            $table->dateTime('packed_at', 3)->nullable();
            $table->foreignId('packed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('packed_by_user_id');
            $table->dropColumn(['warehouse_code', 'packed_at']);
        });
    }
};
