<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a product gallery image point at an externally-hosted/CDN URL instead
 * of an uploaded S3 object. Adds a nullable `external_url` column and makes
 * `s3_key` nullable so a URL-only image can be recorded without an upload.
 * Guarded so it is safe to re-run on partially-migrated environments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            if (! Schema::hasColumn('product_images', 'external_url')) {
                // Hosted/CDN image URL — when set, ProductImage::url() returns
                // this verbatim and no S3 object exists for the row.
                $table->string('external_url', 1000)->nullable()->after('s3_key');
            }
        });

        // A URL-only gallery image has no S3 object key. Making `s3_key`
        // nullable is idempotent (a no-op when already nullable).
        Schema::table('product_images', function (Blueprint $table): void {
            $table->string('s3_key', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table): void {
            if (Schema::hasColumn('product_images', 'external_url')) {
                $table->dropColumn('external_url');
            }
        });
    }
};
