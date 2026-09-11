<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who asked for this batch to exist — the maker half of maker-checker (QA F94).
 *
 * `approved_by` recorded the checker from the first day; the maker was never
 * recorded at all, which made "the person who built the batch approved their
 * own batch" unrepresentable rather than blocked. It is nullable because the
 * scheduler builds most batches with nobody logged in: a NULL maker is a
 * machine-made batch and any approver may sign it off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payout_batches', 'created_by')) {
            return;
        }

        Schema::table('payout_batches', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_by')->nullable()->after('processed_at');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payout_batches', function (Blueprint $table): void {
            $table->dropForeign(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
