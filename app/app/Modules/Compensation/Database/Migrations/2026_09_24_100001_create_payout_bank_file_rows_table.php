<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The parsed contents of each bank file, one row per CSV row: what the file
 * said about an ADN and what the platform did with it.
 *
 * No account number, IFSC or beneficiary name is copied here, so comparing
 * two imports never decrypts a file. `reason` is the bank's own free text and
 * can still carry an account number — the same exposure as
 * `payout_line_items.failure_reason`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_bank_file_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payout_bank_file_id');
            $table->unsignedInteger('row_no');
            $table->string('adn', 32);
            $table->unsignedBigInteger('payout_line_item_id')->nullable();
            // Export rows: the line's `retry_count` when it was sent. A line is
            // "already in a bank file" only for a row of its current attempt —
            // the file it bounced from does not count once it is sent again.
            $table->unsignedInteger('attempt')->nullable();
            $table->string('bank_status', 50)->nullable();
            $table->string('verdict', 15)->nullable();
            $table->string('utr', 64)->nullable();
            $table->bigInteger('amount_paise')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('result', 25);

            $table->index(['payout_bank_file_id', 'adn'], 'idx_payout_bank_file_rows_adn');
            $table->index('payout_line_item_id', 'idx_payout_bank_file_rows_line');

            $table->foreign('payout_bank_file_id', 'fk_payout_bank_file_rows_file')
                ->references('id')->on('payout_bank_files')->cascadeOnDelete();
            $table->foreign('payout_line_item_id', 'fk_payout_bank_file_rows_line')
                ->references('id')->on('payout_line_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_bank_file_rows');
    }
};
