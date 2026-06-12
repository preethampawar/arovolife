<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A banner can now belong to a category. NULL = shopping-mall (home) carousel
 * banner (unchanged behaviour); a category_id assigns the banner to that
 * category's page, where several such banners slide like the mall carousel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->after('id')
                ->constrained('product_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
