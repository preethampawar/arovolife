<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which hashing method sealed each audit row.
 *
 * Version 2 hashes the details with their keys sorted, so the hash survives
 * MySQL's JSON column reordering object keys on storage. Rows sealed by the
 * first method hashed the details in the caller's key order, which cannot be
 * recovered once MySQL has reordered them, so their content can no longer be
 * re-checked. They stay NULL on purpose: re-sealing them would attest to
 * history nobody verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->unsignedTinyInteger('hash_version')->nullable()->after('prev_hash');
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->dropColumn('hash_version');
        });
    }
};
