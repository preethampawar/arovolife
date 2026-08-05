<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rank_tiers', function (Blueprint $table) {
            // Rank Achievement Points (KP 2026-08-05): when set, the rank's pool
            // is divided by total points (achievers × rap_points + AO-GO points)
            // instead of split equally. Null = equal split among achievers —
            // KP's ranks 2–9 model. Only Rank 1 carries points (10).
            $table->unsignedSmallInteger('rap_points')->nullable()->after('pyp_required');
        });
    }

    public function down(): void
    {
        Schema::table('rank_tiers', function (Blueprint $table) {
            $table->dropColumn('rap_points');
        });
    }
};
