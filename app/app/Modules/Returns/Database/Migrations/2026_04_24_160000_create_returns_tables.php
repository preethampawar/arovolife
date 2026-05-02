<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('rma_no', 24)->unique('uniq_return_rma_no');
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->enum('reason', ['dissatisfaction', 'damaged', 'defective', 'wrong_item', 'other']);
            $table->foreignId('opened_by_customer_id')->constrained('customers')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->enum('status', ['opened', 'pickup_scheduled', 'received', 'inspected', 'approved', 'rejected', 'refunded'])->default('opened');
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index(['order_id', 'status'], 'idx_return_order_status');
        });

        Schema::create('return_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_request_id')->unique('uniq_return_inspection')->constrained('return_requests')->cascadeOnDelete();
            $table->dateTime('received_at', 3);
            $table->enum('condition', ['saleable', 'non_saleable', 'damaged']);
            $table->foreignId('inspector_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('buyback_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_request_id')->unique('uniq_buyback_return')->constrained('return_requests')->cascadeOnDelete();
            $table->string('decision_matrix_version', 16)->default('v1');
            $table->bigInteger('refund_base_paise');
            $table->bigInteger('gst_adjustment_paise')->default(0);
            $table->bigInteger('admin_deduction_paise')->default(0);
            $table->bigInteger('net_refund_paise');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at', 3)->nullable();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyback_decisions');
        Schema::dropIfExists('return_inspections');
        Schema::dropIfExists('return_requests');
    }
};
