<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reversing a GSB credit locks the Mentorship rows earned on it — looked up
     * by sponsee and day (client decision M5). Neither existing index leads
     * with the sponsee, so the FOR UPDATE would scan, and lock, far more rows
     * than the one or two it needs while the nightly engine writes the table.
     */
    public function up(): void
    {
        Schema::table('mentorship_bonus_results', function (Blueprint $table): void {
            $table->index(['sponsee_id', 'cutoff_date'], 'idx_mb_result_sponsee_date');
        });
    }

    public function down(): void
    {
        Schema::table('mentorship_bonus_results', function (Blueprint $table): void {
            $table->dropIndex('idx_mb_result_sponsee_date');
        });
    }
};
