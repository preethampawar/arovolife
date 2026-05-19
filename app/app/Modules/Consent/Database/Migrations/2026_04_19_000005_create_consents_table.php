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
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distributor_id')
                ->index('idx_consents_distributor')
                ->constrained('distributors')->cascadeOnDelete();
            $table->enum('document_type', ['tnc', 'ethics', 'plan', 'privacy']);
            $table->string('document_version', 32);
            $table->binary('doc_hash_sha256')->nullable();
            $table->dateTime('accepted_at', 3);
            $table->string('ip', 64);
            $table->string('user_agent', 512);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE consents MODIFY doc_hash_sha256 BINARY(32) NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
