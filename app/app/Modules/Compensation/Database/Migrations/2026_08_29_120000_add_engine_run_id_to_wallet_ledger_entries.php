<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links every wallet ledger entry back to the engine run that wrote it.
 *
 * Recovery from an engine that dies mid-way is already safe (per-engine
 * transactions or idempotent per-row checks, plus `uniq_wallet_ledger_source`),
 * but nobody could *see* what a given run wrote. WalletService stamps this
 * column from the ambient EngineRunContext, and Run events shows "entries · net
 * ₹" per run — so a failed run's committed rows are a listed set, not
 * archaeology.
 *
 * Nullable on purpose: order-time entries (`repurchase_deduction`,
 * `repurchase_wallet_used`, `manual_credit`, `reversal`) are written outside any
 * engine run and stay NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('engine_run_id')->nullable()->after('memo');

            $table->index('engine_run_id', 'idx_wallet_engine_run');
            $table->foreign('engine_run_id', 'fk_wallet_engine_run')
                ->references('id')->on('engine_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            // SQLite (tests) cannot drop a foreign key, and the index rides on
            // the column: dropping the column alone is the whole rollback there.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign('fk_wallet_engine_run');
                $table->dropIndex('idx_wallet_engine_run');
            }

            $table->dropColumn('engine_run_id');
        });
    }
};
