<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Packed size of one unit, in millimetres — integers, like `weight_g`.
 *
 * A courier prices a parcel on the greater of its actual and volumetric weight,
 * so Shiprocket refuses a booking without length, breadth and height. NULL
 * means "not recorded"; an order containing such a variant cannot be sent
 * through Shiprocket until it is filled in (manual dispatch is unaffected).
 * Strictly additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unsignedInteger('length_mm')->nullable()->after('weight_g');
            $table->unsignedInteger('breadth_mm')->nullable()->after('length_mm');
            $table->unsignedInteger('height_mm')->nullable()->after('breadth_mm');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn(['length_mm', 'breadth_mm', 'height_mm']);
        });
    }
};
