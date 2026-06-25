<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_batches', function (Blueprint $table): void {
            $table->enum('batch_type', ['gsb_weekly', 'manual'])->default('gsb_weekly')->after('id');
            $table->unsignedBigInteger('approved_by')->nullable()->after('processed_at');
            $table->timestamp('approved_at')->nullable()->after('approved_by');

            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payout_batches', function (Blueprint $table): void {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['batch_type', 'approved_by', 'approved_at']);
        });
    }
};
