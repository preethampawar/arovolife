<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributors', static function (Blueprint $table): void {
            // Reserved company nodes can be flipped between active (slot
            // occupied, no payouts) and inactive (slot still occupied for
            // tree integrity, but flagged dormant for admin audit). Real
            // distributors stay active by default; freeze() lives elsewhere.
            $table->enum('status', ['active', 'inactive'])
                ->default('active')
                ->after('is_primary_couple');
        });
    }

    public function down(): void
    {
        Schema::table('distributors', static function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
