<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite stores enums as unconstrained strings — MODIFY COLUMN is MySQL-only.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payout_batches MODIFY COLUMN status ENUM('pending','processing','completed','failed','partially_failed') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payout_batches MODIFY COLUMN status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending'");
        }
    }
};
