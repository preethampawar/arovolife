<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The GSB daily pool sums company-wide BV by effective_at day
 * (GsbDailyPoolService::companyBvPaiseForDate) — without this index that is a
 * full table scan every cut-off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bv_ledger_entries', function (Blueprint $table) {
            $table->index('effective_at', 'idx_bv_ledger_effective_at');
        });
    }

    public function down(): void
    {
        Schema::table('bv_ledger_entries', function (Blueprint $table) {
            $table->dropIndex('idx_bv_ledger_effective_at');
        });
    }
};
