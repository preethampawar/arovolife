<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distributor_id')
                ->index('idx_kyc_distributor')
                ->constrained('distributors')->cascadeOnDelete();
            $table->enum('type', ['pan', 'aadhaar', 'cheque', 'address_proof_front', 'address_proof_back', 'photo']);
            $table->string('object_storage_key', 512);
            $table->binary('checksum_sha256')->nullable();
            $table->dateTime('verified_at', 3)->nullable();
            $table->foreignId('verifier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE kyc_documents MODIFY checksum_sha256 BINARY(32) NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
    }
};
