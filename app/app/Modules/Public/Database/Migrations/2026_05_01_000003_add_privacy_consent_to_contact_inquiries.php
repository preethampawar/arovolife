<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DPDP Act 2023 §6 — record explicit, dated consent at the moment the form
 * is submitted, so the platform can prove informed consent for processing
 * the personal data captured by the contact form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_inquiries', function (Blueprint $table): void {
            $table->dateTime('privacy_consent_at', 3)->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('contact_inquiries', function (Blueprint $table): void {
            $table->dropColumn('privacy_consent_at');
        });
    }
};
