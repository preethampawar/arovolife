<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('ticket_no', 24)->unique('uniq_tickets_no');
            $table->string('subject', 255);
            $table->text('body');
            $table->enum('category', ['order', 'payment', 'refund', 'account', 'product', 'compliance', 'other']);
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('status', ['open', 'acknowledged', 'in_progress', 'resolved', 'closed'])->default('open');

            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('distributor_id')->nullable()->constrained('distributors')->nullOnDelete();
            $table->string('reporter_email', 255)->nullable();
            $table->string('reporter_phone', 20)->nullable();

            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('sla_first_response_at', 3);  // 24h from open
            $table->dateTime('sla_resolution_at', 3);       // 7d from open
            $table->dateTime('first_response_at', 3)->nullable();
            $table->dateTime('resolved_at', 3)->nullable();
            $table->dateTime('closed_at', 3)->nullable();

            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index(['status', 'severity'], 'idx_tickets_status_sev');
            $table->index('sla_resolution_at', 'idx_tickets_sla');
        });

        Schema::create('ticket_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->enum('kind', ['status_change', 'comment', 'assignment', 'sla_breach']);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_value', 64)->nullable();
            $table->string('to_value', 64)->nullable();
            $table->text('note')->nullable();
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index(['ticket_id', 'created_at'], 'idx_ticket_events_ticket_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_events');
        Schema::dropIfExists('tickets');
    }
};
