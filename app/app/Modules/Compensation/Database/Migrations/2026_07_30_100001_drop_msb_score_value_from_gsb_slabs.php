<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes every trace of the configured MSB point value (KP 2026-07-30).
 *
 * The Mentorship Bonus point value is no longer a plan parameter: it is
 * computed at each daily cut-off as the day's 3% pool ÷ the day's total MSB
 * score points (see MsbDailyPoolService). The per-slab column and the three
 * long-deprecated rate-ladder settings therefore have no readers left.
 *
 * mentorship_bonus_results.msb_point_value_paise is deliberately KEPT — it is
 * now the snapshot of the day's computed value, which is what keeps history
 * immune to later plan changes.
 */
return new class extends Migration
{
    /** The retired Mentorship Bonus rate-ladder settings (KP 2026-07-25). */
    private const RETIRED_SETTINGS = [
        'comp.mb.step_paise',
        'comp.mb.start_rate_pct',
        'comp.mb.floor_rate_pct',
    ];

    public function up(): void
    {
        if (Schema::hasColumn('gsb_slabs', 'msb_score_value_paise')) {
            Schema::table('gsb_slabs', function (Blueprint $table) {
                $table->dropColumn('msb_score_value_paise');
            });
        }

        // Dropping them from the registry only makes them unreachable; the
        // seeded rows would linger in the settings table forever. Deleting a
        // compensation-plan setting is an audited change like any other.
        $removed = DB::table('settings')
            ->whereIn('key', self::RETIRED_SETTINGS)
            ->pluck('value', 'key')
            ->all();

        if ($removed !== []) {
            DB::table('settings')->whereIn('key', array_keys($removed))->delete();

            DB::table('audit_log')->insert([
                'action' => 'settings.migration_delete',
                'subject_type' => 'setting',
                'details' => json_encode([
                    'migration' => '2026_07_30_100001_drop_msb_score_value_from_gsb_slabs',
                    'reason' => 'Mentorship Bonus rate ladder retired; MSB is priced daily from the pool.',
                    'before' => $removed,
                    'after' => null,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('gsb_slabs', 'msb_score_value_paise')) {
            Schema::table('gsb_slabs', function (Blueprint $table) {
                $table->unsignedBigInteger('msb_score_value_paise')->default(25_000)->after('msb_score');
            });
        }

        // Restore the ladder settings so the rollback is a true rollback.
        foreach (['comp.mb.step_paise' => '3000000', 'comp.mb.start_rate_pct' => '10', 'comp.mb.floor_rate_pct' => '1'] as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()],
            );
        }
    }
};
