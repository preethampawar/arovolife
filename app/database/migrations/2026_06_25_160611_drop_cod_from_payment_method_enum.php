<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop 'cod' from orders.payment_method enum.
 * Online is the only supported payment method going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `orders` MODIFY COLUMN `payment_method` ENUM('online') NOT NULL DEFAULT 'online'");
        } else {
            Schema::table('orders', function (Blueprint $table): void {
                $table->enum('payment_method', ['online'])->default('online')->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `orders` MODIFY COLUMN `payment_method` ENUM('online','cod') NOT NULL DEFAULT 'online'");
        } else {
            Schema::table('orders', function (Blueprint $table): void {
                $table->enum('payment_method', ['online', 'cod'])->default('online')->change();
            });
        }
    }
};
