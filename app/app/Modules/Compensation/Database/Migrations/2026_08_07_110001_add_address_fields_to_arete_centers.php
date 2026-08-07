<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured address on Arete Development Centers so the monthly ADC
 * calculation report can be searched and grouped by pincode, district and
 * state. Additive only — the legacy free-text `location` column stays and is
 * still matched by the report's area search.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arete_centers', function (Blueprint $table): void {
            $table->string('pincode', 6)->nullable()->after('location');
            $table->string('district', 100)->nullable()->after('pincode');
            $table->string('state', 100)->nullable()->after('district');

            $table->index('pincode', 'idx_ac_pincode');
        });
    }

    public function down(): void
    {
        Schema::table('arete_centers', function (Blueprint $table): void {
            $table->dropIndex('idx_ac_pincode');
            $table->dropColumn(['pincode', 'district', 'state']);
        });
    }
};
