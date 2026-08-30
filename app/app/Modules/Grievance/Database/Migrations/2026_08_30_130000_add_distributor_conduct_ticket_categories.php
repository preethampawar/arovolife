<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Four named code-of-ethics complaint categories (poaching, competitive
 * business, e-commerce selling, stocking and under-cutting) — items 8–11 of
 * the client's 30-08-2026 "Distributor Request" list, which are complaints
 * against another distributor rather than requests.
 *
 * MySQL keeps a real ENUM; SQLite already holds the column as a plain string
 * (see 2026_08_16_100000), so nothing is needed there.
 */
return new class extends Migration
{
    private const string WIDENED = "ENUM('order','payment','refund','account','product','compliance','kyc','compensation','genealogy','ethics','poaching','competitive_business','ecommerce_selling','stocking_undercutting','privacy','platform','other') NOT NULL";

    private const string PREVIOUS = "ENUM('order','payment','refund','account','product','compliance','kyc','compensation','genealogy','ethics','privacy','platform','other') NOT NULL";

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE tickets MODIFY COLUMN category '.self::WIDENED);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Rows holding a new value would block this; fold them into the
            // general ethics family first so the narrowing succeeds.
            DB::table('tickets')
                ->whereIn('category', ['poaching', 'competitive_business', 'ecommerce_selling', 'stocking_undercutting'])
                ->update(['category' => 'ethics']);
            DB::statement('ALTER TABLE tickets MODIFY COLUMN category '.self::PREVIOUS);
        }
    }
};
