<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_images')) {
            return;
        }

        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            // Nullable so a WYSIWYG inline image can be uploaded before the
            // product row is saved (orphans reconciled by a later janitor),
            // and so deleting a product detaches rather than hard-fails.
            $table->foreignId('product_id')->nullable()->constrained('products')->cascadeOnDelete();
            // Object key on the public `s3` disk.
            $table->string('s3_key', 500);
            $table->string('alt', 255)->nullable();
            $table->unsignedInteger('sort')->default(0);
            // gallery  — shown in the product gallery
            // inline   — embedded inside the WYSIWYG description body
            $table->enum('kind', ['gallery', 'inline'])->default('gallery');
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->index(['product_id', 'kind', 'sort'], 'idx_product_images_product_kind_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
