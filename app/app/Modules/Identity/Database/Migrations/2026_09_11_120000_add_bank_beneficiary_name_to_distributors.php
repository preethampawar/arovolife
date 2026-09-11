<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The name the bank holds against the account, and the bank's own name.
 *
 * Until now the only name the platform could put on a NEFT instruction was the
 * distributor's full name, which is not always the account holder's name as
 * the bank has it (QA F28) — a mismatch the bank rejects. The beneficiary name
 * is personal data and is stored as PiiCrypter ciphertext next to
 * `bank_account_enc`; the bank name is not personal data and stays plain.
 *
 * Forward-only and idempotent: both columns are added only when absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributors', function (Blueprint $table): void {
            if (! Schema::hasColumn('distributors', 'bank_beneficiary_name_enc')) {
                $table->binary('bank_beneficiary_name_enc')->nullable()->after('bank_account_enc');
            }
            if (! Schema::hasColumn('distributors', 'bank_name')) {
                $table->string('bank_name', 120)->nullable()->after('bank_ifsc');
            }
        });

        // Same shape as bank_account_enc (see the create migration): a sized
        // VARBINARY rather than the BLOB Laravel's binary() defaults to, so the
        // ciphertext is stored inline. SQLite has no column widths.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE distributors MODIFY bank_beneficiary_name_enc VARBINARY(512) NULL');
        }
    }

    public function down(): void
    {
        Schema::table('distributors', function (Blueprint $table): void {
            $table->dropColumn(['bank_beneficiary_name_enc', 'bank_name']);
        });
    }
};
