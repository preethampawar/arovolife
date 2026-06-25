<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'web_only' status to payout_line_items.
 * Used for distributors with personal BV < 3,000 BV (below Retailer title):
 * their wallet balance is held — credits display in the back-office but are
 * NOT included in the NEFT batch until the 3,000 BV threshold is reached.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payout_line_items MODIFY status ENUM('pending','transferred','failed','below_minimum','web_only') DEFAULT 'pending'");
        } else {
            // SQLite: ALTER TABLE can't change CHECK constraints — recreate the table.
            $this->recreateSqlite("CHECK(\"status\" in ('pending','transferred','failed','below_minimum','web_only'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE payout_line_items MODIFY status ENUM('pending','transferred','failed','below_minimum') DEFAULT 'pending'");
        } else {
            $this->recreateSqlite("CHECK(\"status\" in ('pending','transferred','failed','below_minimum'))");
        }
    }

    private function recreateSqlite(string $statusCheck): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement("
            CREATE TABLE payout_line_items_new (
                id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                payout_batch_id INTEGER NOT NULL,
                distributor_id INTEGER NOT NULL,
                wallet_balance_paise INTEGER NOT NULL,
                repurchase_deduction_paise INTEGER NOT NULL DEFAULT 0,
                net_transferred_paise INTEGER NOT NULL,
                bank_account_last4 VARCHAR(4),
                utr_number VARCHAR(64),
                status VARCHAR(255) NOT NULL DEFAULT 'pending' {$statusCheck},
                failure_reason TEXT,
                created_at DATETIME,
                updated_at DATETIME
            )
        ");
        DB::statement('INSERT INTO payout_line_items_new SELECT * FROM payout_line_items');
        DB::statement('DROP TABLE payout_line_items');
        DB::statement('ALTER TABLE payout_line_items_new RENAME TO payout_line_items');
        DB::statement('CREATE INDEX idx_payout_line_batch ON payout_line_items (payout_batch_id)');
        DB::statement('CREATE INDEX idx_payout_line_dist ON payout_line_items (distributor_id)');
        DB::statement('CREATE UNIQUE INDEX uniq_payout_line ON payout_line_items (payout_batch_id, distributor_id)');
        DB::statement('PRAGMA foreign_keys = ON');
    }
};
