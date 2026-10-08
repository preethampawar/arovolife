<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Client 2026-10-09: from the royalty rank a sponsor who is failed on
        // their repurchase condition keeps earning Mentorship, capped per
        // cut-off day; the excess is withheld. Every row freezes the verdict it
        // was judged with (F-3), whether that verdict was read while the
        // sponsor's own evaluation was deferred (F-4), the cap it was priced
        // with (F-5) and what the cap withheld. Schema only: no row moves.
        Schema::table('mentorship_bonus_results', function (Blueprint $table): void {
            $table->boolean('sponsor_repurchase_failed')->default(false)->after('status');
            $table->boolean('sponsor_verdict_stale')->default(false)->after('sponsor_repurchase_failed');
            $table->unsignedBigInteger('royalty_cap_paise')->nullable()->after('sponsor_verdict_stale');
            $table->unsignedBigInteger('royalty_cap_withheld_paise')->default(0)->after('royalty_cap_paise');
        });

        // Stale since the 2026-07-30 points engine: always written null, read nowhere.
        Schema::table('mentorship_bonus_results', function (Blueprint $table): void {
            $table->dropColumn(['mb_rate_pct', 'sponsee_cumulative_gsb_paise']);
        });
    }

    public function down(): void
    {
        // The legacy columns come back empty. Points-engine rows (since
        // 2026-07-30) always held null; any older rate-ladder values are not
        // restorable from here — this is a re-add, never a restore.
        Schema::table('mentorship_bonus_results', function (Blueprint $table): void {
            $table->unsignedTinyInteger('mb_rate_pct')->nullable();
            $table->bigInteger('sponsee_cumulative_gsb_paise')->nullable();
        });

        Schema::table('mentorship_bonus_results', function (Blueprint $table): void {
            $table->dropColumn(['sponsor_repurchase_failed', 'sponsor_verdict_stale', 'royalty_cap_paise', 'royalty_cap_withheld_paise']);
        });
    }
};
