<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_attributes')) {
            return;
        }

        Schema::create('product_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // e.g. "Ingredients", "Nutritional information", "Storage".
            $table->string('label', 150);
            // Sanitized WYSIWYG body — may contain tables / inline images (e.g. a
            // nutritional-facts table or image). Cleaned with the 'products'
            // HTMLPurifier profile before save.
            $table->longText('value_html');
            $table->unsignedInteger('sort')->default(0);
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index(['product_id', 'sort'], 'idx_product_attributes_product_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
    }
};
