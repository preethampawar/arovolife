<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence that a distributor was given notice of a compensation-plan change
 * (plan page §12, R-123): when the pop-up first showed, which version entries
 * it listed, when the notice email was actually sent or failed, and which
 * consent row recorded their agreement. One row per distributor per text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_change_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distributor_id')->constrained('distributors')->cascadeOnDelete();
            $table->binary('doc_hash_sha256', length: 32, fixed: true);
            $table->string('labels_shown', 255)->nullable();
            $table->dateTime('shown_at', 3)->nullable();
            $table->dateTime('emailed_at', 3)->nullable();
            $table->dateTime('email_failed_at', 3)->nullable();
            $table->foreignId('accepted_consent_id')->nullable()->constrained('consents')->nullOnDelete();
            $table->timestamps(3);

            $table->unique(['distributor_id', 'doc_hash_sha256'], 'uniq_plan_change_notices_distributor_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_change_notices');
    }
};
