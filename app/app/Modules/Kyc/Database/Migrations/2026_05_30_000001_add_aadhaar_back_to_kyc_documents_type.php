<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'aadhaar_back' as a valid KYC document type.
 *
 * The wizard now collects BOTH sides of the Aadhaar card so the admin reviewer
 * can verify the address printed on the back (UIDAI prints the address on the
 * reverse side of the physical Aadhaar). Existing rows are untouched —
 * pre-launch data only has 'aadhaar' (front) rows; new registrations will
 * persist both 'aadhaar' (front) and 'aadhaar_back'.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL-only: SQLite has no native ENUM and already accepts any string value.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE kyc_documents MODIFY COLUMN type ENUM('
                ."'pan','aadhaar','aadhaar_back','cheque','address_proof_front','address_proof_back','photo'"
                .') NOT NULL'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE kyc_documents MODIFY COLUMN type ENUM('
                ."'pan','aadhaar','cheque','address_proof_front','address_proof_back','photo'"
                .') NOT NULL'
            );
        }
    }
};
