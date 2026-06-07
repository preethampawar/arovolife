<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved-address book: a short user-chosen label (Home / Work / Office / custom)
 * so distributors can keep and reuse multiple shipping addresses. Existing rows
 * stay unlabelled (null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table): void {
            $table->string('label', 40)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table): void {
            $table->dropColumn('label');
        });
    }
};
