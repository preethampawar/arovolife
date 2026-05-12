<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Accepted-risk migration (logged in docs/compliance/risk-register.md).
    // The product owner asked for the full PAN and full Aadhaar to be held at
    // rest pending KYC review. After admin verification, ApproveKycSubmission
    // nulls both columns and purges the uploaded images, leaving only the
    // last-4 fields. Until that flip happens these rows ARE sensitive — keep
    // SESSION_ENCRYPT=true and APP_KEY in a secret store.
    public function up(): void
    {
        Schema::table('distributors', function (Blueprint $table) {
            $table->binary('pan_encrypted')->nullable()->after('pan_last4');
            $table->binary('aadhaar_encrypted')->nullable()->after('aadhaar_last4');
        });

        DB::statement('ALTER TABLE distributors MODIFY pan_encrypted VARBINARY(512) NULL');
        DB::statement('ALTER TABLE distributors MODIFY aadhaar_encrypted VARBINARY(512) NULL');
    }

    public function down(): void
    {
        Schema::table('distributors', function (Blueprint $table) {
            $table->dropColumn(['pan_encrypted', 'aadhaar_encrypted']);
        });
    }
};
