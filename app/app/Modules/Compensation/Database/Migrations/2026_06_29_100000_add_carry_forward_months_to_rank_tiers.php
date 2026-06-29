<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The "1+2 rule" carry-forward, made admin-configurable per rank
     * (KP 2026-06-28: Rank 1 = 2 extra months, Ranks 2-9 = 0). A rank with
     * carry_forward_months = N keeps paying a qualifier for N months after the
     * qualifying month while their repurchase continues.
     */
    public function up(): void
    {
        Schema::table('rank_tiers', function (Blueprint $table): void {
            $table->unsignedTinyInteger('carry_forward_months')->default(0)->after('structural_qualifiers_per_side');
        });
    }

    public function down(): void
    {
        Schema::table('rank_tiers', function (Blueprint $table): void {
            $table->dropColumn('carry_forward_months');
        });
    }
};
