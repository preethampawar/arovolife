<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A distributor whose payout line could not be built for a batch (user
 * request 2026-10-17: one distributor's failure must not block the batch).
 *
 * The sweep skips them — their transaction rolled back, so no line, no debit,
 * and their credits stay unswept in the wallet — and records the error here in
 * full so an admin can see why and retry them from Payouts → Build failures.
 *
 * `error_detail` is the whole exception (message, file:line, trace, previous
 * exceptions) after the PII scrubber, capped at 256 KB. One row per
 * batch and distributor; a retry that fails again updates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_build_failures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payout_batch_id');
            $table->unsignedBigInteger('distributor_id');
            $table->string('status', 20)->default('open');
            $table->string('resolution', 30)->nullable();
            $table->unsignedBigInteger('resolved_line_item_id')->nullable();
            $table->unsignedBigInteger('resolved_by_batch_id')->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->string('exception_class', 255);
            $table->text('message');
            $table->mediumText('error_detail');
            $table->dateTime('first_failed_at', 3);
            $table->dateTime('last_failed_at', 3);
            $table->dateTime('resolved_at', 3)->nullable();
            $table->unsignedBigInteger('retry_requested_by')->nullable();
            $table->dateTime('retry_requested_at')->nullable();
            $table->timestamps();

            $table->unique(['payout_batch_id', 'distributor_id'], 'uniq_payout_build_failures_batch_distributor');
            $table->index(['status', 'payout_batch_id'], 'idx_payout_build_failures_status');
            $table->index(['distributor_id', 'status'], 'idx_payout_build_failures_distributor');

            // Cascades: a batch is only ever deleted by the rebuild/unbuild
            // tools, and the re-sweep re-records whoever still fails.
            $table->foreign('payout_batch_id', 'fk_payout_build_failures_batch')
                ->references('id')->on('payout_batches')->cascadeOnDelete();
            $table->foreign('distributor_id', 'fk_payout_build_failures_distributor')
                ->references('id')->on('distributors');
            $table->foreign('resolved_line_item_id', 'fk_payout_build_failures_line')
                ->references('id')->on('payout_line_items')->nullOnDelete();
            $table->foreign('resolved_by_batch_id', 'fk_payout_build_failures_resolved_batch')
                ->references('id')->on('payout_batches')->nullOnDelete();
            $table->foreign('retry_requested_by', 'fk_payout_build_failures_requester')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_build_failures');
    }
};
