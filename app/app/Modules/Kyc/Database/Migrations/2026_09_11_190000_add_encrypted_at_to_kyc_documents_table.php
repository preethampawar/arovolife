<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KYC scans are now encrypted before they reach object storage
 * (KycDocumentVault). `encrypted_at` tells a ciphertext object from one
 * uploaded before this change, so the read path can serve both until
 * `kyc:encrypt-documents` has converted the older ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table): void {
            $table->dateTime('encrypted_at', 3)->nullable()->after('checksum_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table): void {
            $table->dropColumn('encrypted_at');
        });
    }
};
