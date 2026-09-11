<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One bank reference settles one payout line, enforced by the database.
 *
 * The manual-NEFT import matches the bank's response rows on ADN and wrote
 * whatever UTR the row carried, so a response file repeating one reference —
 * a copy-paste in the bank's own export, an admin re-uploading a file with a
 * stuck column — marked two distributors paid against a single transfer. The
 * import now refuses those rows (QA F15); this index is the guarantee behind
 * it, because an application check alone cannot see a concurrent import.
 *
 * The column stays nullable and NULLs are exempt from a unique index on both
 * MySQL and SQLite, so every line still waiting for the bank is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A unique index cannot be created over data that already violates it,
        // and silently skipping it would leave the guarantee absent while the
        // migration reported success. Name the offenders instead: they are a
        // double payment that has to be reconciled by a human, not by a schema
        // change.
        $duplicates = DB::table('payout_line_items')
            ->select('utr_number')
            ->whereNotNull('utr_number')
            ->where('utr_number', '!=', '')
            ->groupBy('utr_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('utr_number');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'payout_line_items.utr_number already holds '.$duplicates->count().
                ' reference(s) used by more than one line item. Reconcile them before applying this migration: '.
                $duplicates->take(10)->implode(', ')
            );
        }

        Schema::whenTableDoesntHaveIndex('payout_line_items', 'uniq_payout_line_items_utr', function (): void {
            Schema::table('payout_line_items', function (Blueprint $table): void {
                $table->unique('utr_number', 'uniq_payout_line_items_utr');
            });
        });
    }

    public function down(): void
    {
        Schema::whenTableHasIndex('payout_line_items', 'uniq_payout_line_items_utr', function (): void {
            Schema::table('payout_line_items', function (Blueprint $table): void {
                $table->dropUnique('uniq_payout_line_items_utr');
            });
        });
    }
};
