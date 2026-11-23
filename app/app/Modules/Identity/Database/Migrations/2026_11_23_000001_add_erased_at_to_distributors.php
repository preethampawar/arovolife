<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a developer erased this partner (and their Genos subtree). The row is a
 * tombstone from then on: personal data stripped, pointers rewritten to itself,
 * kept only so the money and tax rows that cascade from it survive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributors', function (Blueprint $table): void {
            $table->datetime('erased_at', 3)->nullable()->after('reregistration_allowed_from')->index();
        });
    }

    public function down(): void
    {
        Schema::table('distributors', function (Blueprint $table): void {
            $table->dropIndex(['erased_at']);
            $table->dropColumn('erased_at');
        });
    }
};
