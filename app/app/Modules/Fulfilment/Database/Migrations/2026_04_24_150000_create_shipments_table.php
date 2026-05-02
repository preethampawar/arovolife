<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('warehouse_code', 32)->default('DEFAULT');
            $table->string('carrier_code', 32)->default('MANUAL');
            $table->string('awb_no', 64)->nullable();
            $table->enum('status', ['created', 'picked', 'dispatched', 'delivered', 'returned_to_origin'])->default('created');
            $table->dateTime('dispatched_at', 3)->nullable();
            $table->dateTime('delivered_at', 3)->nullable();
            $table->string('pod_hash_sha256', 64)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index(['order_id', 'status'], 'idx_shipments_order_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
