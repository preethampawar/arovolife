<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every bank file of a payout batch: each NEFT file downloaded for the bank
 * (`export`) and each response file the bank sent back (`import`).
 *
 * The file itself is PiiCrypter ciphertext on the `payout-bank-files` disk —
 * it carries full account numbers — and `storage_key` points at it until the
 * retention sweep deletes it (`purged_at`). This row, and the parsed rows in
 * `payout_bank_file_rows`, outlive the file: they are what the timeline and
 * the import comparisons read.
 *
 * No sequence column: "Import #2" is the file's position by id within its
 * batch and direction, worked out when the page is drawn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_bank_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payout_batch_id');
            $table->string('direction', 10);
            $table->string('storage_key', 255)->nullable();
            $table->string('original_name', 255);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->char('sha256', 64);
            $table->unsignedInteger('row_count')->default(0);
            $table->string('outcome', 10);
            $table->json('summary')->nullable();
            $table->unsignedBigInteger('identical_to_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->index(['payout_batch_id', 'direction', 'id'], 'idx_payout_bank_files_batch');
            $table->index(['purged_at', 'created_at'], 'idx_payout_bank_files_retention');

            // Cascades: a batch is only ever deleted by the rebuild/unbuild
            // tools, which remove its line items too, and a bank file of a
            // batch that no longer exists describes nothing.
            $table->foreign('payout_batch_id', 'fk_payout_bank_files_batch')
                ->references('id')->on('payout_batches')->cascadeOnDelete();
            $table->foreign('identical_to_id', 'fk_payout_bank_files_identical')
                ->references('id')->on('payout_bank_files')->nullOnDelete();
            // A staff account being removed must not delete the record of who
            // handled the file.
            $table->foreign('actor_id', 'fk_payout_bank_files_actor')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_bank_files');
    }
};
