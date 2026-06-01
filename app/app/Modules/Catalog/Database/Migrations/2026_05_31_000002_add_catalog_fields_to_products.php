<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // FK to the new category master. The legacy string `category`
            // column is retained for back-compat and backfilled by the
            // category seeder; the storefront migrates to category_id.
            // Guards make this idempotent so a partially-migrated DB self-heals.
            if (! Schema::hasColumn('products', 'category_id')) {
                $table->foreignId('category_id')->nullable()->after('category')
                    ->constrained('product_categories')->nullOnDelete();
            }
            if (! Schema::hasColumn('products', 'manufacturer')) {
                $table->string('manufacturer', 200)->nullable()->after('category_id');
            }
            if (! Schema::hasColumn('products', 'country_of_origin')) {
                $table->string('country_of_origin', 64)->nullable()->default('India')->after('manufacturer');
            }
            // Sanitized WYSIWYG body (Trix → HTMLPurifier 'products' profile).
            // The legacy plain-text `description` stays as a short fallback.
            if (! Schema::hasColumn('products', 'description_html')) {
                $table->longText('description_html')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['manufacturer', 'country_of_origin', 'description_html']);
        });
    }
};
