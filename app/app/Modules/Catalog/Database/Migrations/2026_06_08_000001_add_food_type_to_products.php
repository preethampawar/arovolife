<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Veg / Non-veg classification for food products (FSSAI-style mark). NULL means
 * "not applicable" (e.g. personal-care / agri items that aren't food).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->enum('food_type', ['veg', 'non_veg'])->nullable()->after('country_of_origin');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('food_type');
        });
    }
};
