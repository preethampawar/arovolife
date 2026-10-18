<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * kyc:purge-abandoned-uploads asks "does any row point into reg_<session>/?"
 * once per folder on the kyc disk, and completed registrations keep their
 * folder — so without an index each check scans the whole table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table): void {
            $table->index('object_storage_key', 'idx_kyc_object_storage_key');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table): void {
            $table->dropIndex('idx_kyc_object_storage_key');
        });
    }
};
