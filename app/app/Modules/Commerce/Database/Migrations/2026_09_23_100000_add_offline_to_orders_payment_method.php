<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Offline orders: an order staff create for a distributor who paid outside the
 * gateway (cash at reception, bank deposit, UPI, NEFT, cheque). The payment
 * itself is recorded in `offline_payments`; this marks the order so every
 * reader can tell it apart from a gateway order.
 *
 * `cod` stays out: it was dropped on purpose by
 * 2026_06_25_160611_drop_cod_from_payment_method_enum, and this migration
 * mirrors that one's two branches (MODIFY on MySQL, ->change() elsewhere, so
 * SQLite's CHECK constraint is rebuilt too).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `orders` MODIFY COLUMN `payment_method` ENUM('online','offline') NOT NULL DEFAULT 'online'");
        } else {
            Schema::table('orders', function (Blueprint $table): void {
                $table->enum('payment_method', ['online', 'offline'])->default('online')->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('orders')->where('payment_method', 'offline')->exists()) {
            throw new RuntimeException('Offline orders exist; narrowing payment_method would destroy what they record.');
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `orders` MODIFY COLUMN `payment_method` ENUM('online') NOT NULL DEFAULT 'online'");
        } else {
            Schema::table('orders', function (Blueprint $table): void {
                $table->enum('payment_method', ['online'])->default('online')->change();
            });
        }
    }
};
