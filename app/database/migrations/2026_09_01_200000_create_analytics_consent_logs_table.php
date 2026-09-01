<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log for Google Analytics consent decisions (DPDP Act 2023 §5-§6).
 *
 * Analytics consent is separate from the four DSA consents in `consents`:
 *   - It is optional — declining does not affect the distributorship.
 *   - It applies to guests as well as authenticated distributors.
 *   - Withdrawal resets the browser preference but does NOT terminate the ADN.
 *
 * The table stores every grant and revocation as an immutable append-only row.
 * Querying the latest row for a session / distributor gives the current stance.
 * Rows are never deleted; they are the evidence that the platform obtained (or
 * was refused) consent before loading GA4.
 *
 * Retention: DPDP §8(7) requires data to be held for the period necessary.
 * Consent records are needed for the lifetime of the account plus any dispute
 * window thereafter. A prune job should archive rows older than seven years.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_consent_logs', function (Blueprint $table): void {
            $table->id();

            // Null for pre-registration guests — they have a Laravel session
            // but no distributor row yet. Authenticated users carry both.
            $table->foreignId('distributor_id')
                ->nullable()
                ->index('idx_acl_distributor')
                ->constrained('distributors')
                ->nullOnDelete();

            // Laravel session ID (hex string). Lets us correlate a guest's
            // decision across requests and later join to the distributor if
            // they register in the same session.
            $table->string('session_id', 128)->index('idx_acl_session');

            // 'granted' or 'denied'. Append-only, never updated.
            $table->enum('decision', ['granted', 'denied']);

            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->dateTime('decided_at', 3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_consent_logs');
    }
};
