<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence attached to a grievance. Policy §3.1 promises complainants may
 * attach PDFs, images and screenshots up to 10 MB each. Files live on the
 * private disk and are streamed through a controller — never a public URL,
 * because a grievance body routinely contains PII.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 32)->default('private');
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 128);
            $table->unsignedInteger('size_bytes');
            $table->dateTime('created_at', 3)->useCurrent();

            $table->index('ticket_id', 'idx_ticket_attachments_ticket');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
    }
};
