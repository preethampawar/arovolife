<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'rejected' to users.status so we can distinguish a distributor whose
 * KYC was declined (can re-upload and try again) from one whose account is
 * permanently closed ('terminated', reserved for fraud / repeat offenders /
 * cooling-off cancellation). The KYC review queue continues to filter by
 * status='pending'; the re-submission flow will flip rejected → pending
 * once new documents arrive.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN status ENUM('pending', 'active', 'frozen', 'terminated', 'rejected') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("UPDATE users SET status = 'terminated' WHERE status = 'rejected'");
        DB::statement("ALTER TABLE users MODIFY COLUMN status ENUM('pending', 'active', 'frozen', 'terminated') NOT NULL DEFAULT 'pending'");
    }
};
