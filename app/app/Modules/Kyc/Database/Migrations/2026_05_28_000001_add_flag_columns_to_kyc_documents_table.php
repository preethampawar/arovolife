<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table) {
            $table->string('flagged_reason', 1024)->nullable()->after('verifier_id');
            $table->dateTime('flagged_at')->nullable()->after('flagged_reason');
            $table->foreignId('flagged_by')->nullable()->after('flagged_at')->constrained('users')->nullOnDelete();
            $table->index(['distributor_id', 'flagged_at'], 'idx_kyc_docs_flagged');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table) {
            $table->dropIndex('idx_kyc_docs_flagged');
            $table->dropConstrainedForeignId('flagged_by');
            $table->dropColumn(['flagged_reason', 'flagged_at']);
        });
    }
};
