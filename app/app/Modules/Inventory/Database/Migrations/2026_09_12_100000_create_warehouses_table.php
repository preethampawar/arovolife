<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock locations. `code` is the key everything else joins on — `inventory_levels`
 * and `shipments` already carry a `warehouse_code` string, so keying on the code
 * rather than the id means no backfill of existing rows and readable reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique('uniq_warehouses_code');
            $table->string('name', 120);
            $table->enum('type', ['hub', 'warehouse', 'franchise'])->default('warehouse');
            $table->string('line1', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 64)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->string('contact_phone_e164', 20)->nullable();
            // false = storage only; never picked for an order.
            $table->boolean('fulfils_orders')->default(true);
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
        });

        // The hub every existing inventory_levels / shipments row already points
        // at. Seeded here so the FK added in 100010 is satisfiable.
        DB::table('warehouses')->insert([
            'code' => 'DEFAULT',
            'name' => 'Central Hub',
            'type' => 'hub',
            'fulfils_orders' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
