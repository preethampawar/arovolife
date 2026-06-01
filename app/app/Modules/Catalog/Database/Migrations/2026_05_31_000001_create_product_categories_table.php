<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_categories')) {
            return;
        }

        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 128)->unique('uniq_product_categories_slug');
            $table->string('name', 150);
            // Self-referential parent for an Atomy-style category hierarchy.
            // Nullable = top-level category.
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->text('description')->nullable();
            // S3 key (on the public `s3` disk) for the category tile image.
            $table->string('image_s3_key', 500)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index(['status', 'sort'], 'idx_product_categories_status_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
