<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded premises documents for an ADC application (§A4). Stored on the
 * private `adc` disk under the application id — never under the
 * distributor's KYC folder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arete_center_application_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('arete_center_applications')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('original_name', 255);
            $table->string('object_storage_key', 500);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('checksum_sha256', 64);
            $table->timestamps();

            $table->index(['application_id', 'type'], 'idx_acad_app_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arete_center_application_documents');
    }
};
