<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wide category banner (Atomy-style, recommended 1280x290) shown on the
 * category-filtered shop view. Image is EITHER an uploaded S3 object
 * (`banner_s3_key`) OR an external URL (`banner_external_url`), separate from
 * the small square tile image (`image_s3_key`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table): void {
            $table->string('banner_s3_key', 500)->nullable()->after('image_s3_key');
            $table->string('banner_external_url', 500)->nullable()->after('banner_s3_key');
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table): void {
            $table->dropColumn(['banner_s3_key', 'banner_external_url']);
        });
    }
};
