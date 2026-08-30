<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A distributor's formal request about their own record — name correction,
 * name change, date-of-birth correction, membership transfer to an immediate
 * blood relation, or ID cancellation (the client's 30-08-2026 "Distributor
 * Request" form). Each is reviewed by staff; the type-specific answers are
 * a JSON snapshot so the vocabulary can grow without an ALTER.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distributor_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_no', 20)->unique();
            $table->foreignId('distributor_id')->constrained('distributors')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('status', 20)->default('submitted');
            // Type-specific answers (requested name / DOB / transferee …).
            $table->json('details');
            $table->text('reason');
            // What the record held when the request was made, for the audit
            // trail and so approval can show a before/after.
            $table->json('snapshot_before')->nullable();
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            // Set when approval changed the record itself (name / DOB).
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['distributor_id', 'status'], 'idx_dr_distributor_status');
            $table->index(['status', 'submitted_at'], 'idx_dr_status_submitted');
            $table->index(['type', 'status'], 'idx_dr_type_status');
        });

        Schema::create('distributor_request_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('distributor_requests')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('original_name', 255);
            $table->string('object_storage_key', 500);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('checksum_sha256', 64);
            $table->timestamps();

            $table->index(['request_id', 'type'], 'idx_drd_request_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_request_documents');
        Schema::dropIfExists('distributor_requests');
    }
};
