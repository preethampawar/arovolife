<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two states between "pending approval" and "completed":
 *
 *   approved   — finance signed the batch off; no money has moved. Manual-NEFT
 *                batches wait here for the bank response file.
 *   dispatched — Razorpay has every line item and the batch is waiting for the
 *                payout webhooks that finalise each transfer.
 *
 * Without them, approval and settlement were the same event, which is only
 * true when the batch is approved after the transfers have already happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite stores enums as unconstrained strings — MODIFY COLUMN is MySQL-only.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payout_batches MODIFY COLUMN status ENUM('pending','processing','completed','failed','partially_failed','approved','dispatched') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payout_batches MODIFY COLUMN status ENUM('pending','processing','completed','failed','partially_failed') NOT NULL DEFAULT 'pending'");
        }
    }
};
