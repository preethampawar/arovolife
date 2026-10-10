<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional wedding anniversary date, asked at registration step 6 only when
 * the distributor says they are married, so the company can send anniversary
 * wishes (client, 2026-10-10).
 *
 * Forward-only and idempotent: the column is added only when absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributor_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('distributor_profiles', 'wedding_anniversary_date')) {
                $table->date('wedding_anniversary_date')->nullable()->after('marital_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('distributor_profiles', function (Blueprint $table): void {
            $table->dropColumn('wedding_anniversary_date');
        });
    }
};
