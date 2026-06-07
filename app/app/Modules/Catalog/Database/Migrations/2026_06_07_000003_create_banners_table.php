<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopping-mall carousel banners for the storefront (Atomy-style hero slider,
 * recommended 1520x350). Each banner image is EITHER uploaded to S3 (`s3_key`)
 * OR an external URL (`external_url`), mirroring product images.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 150)->nullable();
            $table->string('caption', 255)->nullable();
            $table->string('link_url', 500)->nullable();   // where the banner clicks through to
            $table->string('s3_key', 500)->nullable();     // uploaded image
            $table->string('external_url', 500)->nullable(); // OR a hosted URL
            $table->unsignedInteger('sort')->default(0);
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->timestamps();

            $table->index(['status', 'sort'], 'idx_banners_status_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};
