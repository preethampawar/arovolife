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
    /** The retired Mentorship Bonus rate-ladder settings and their seeded values (KP 2026-07-25). */
    private const RETIRED_SETTINGS = [
        'comp.mb.step_paise' => '3000000',
        'comp.mb.start_rate_pct' => '10',
        'comp.mb.floor_rate_pct' => '1',
    ];

    public function up(): void
    {
        // 2026_07_25_110000 adds this column unconditionally, so finding it
        // already gone can only mean an earlier run of *this* migration dropped
        // it and then died before recording its audit row — which is exactly
        // what happened on staging on 2026-07-31, when the insert below named a
        // non-existent `updated_at` column. MySQL does not roll DDL back, so
        // that run left the column dropped and the settings deleted.
        $resumingPartialRun = ! Schema::hasColumn('gsb_slabs', 'msb_score_value_paise');

        if (! $resumingPartialRun) {
            Schema::table('gsb_slabs', function (Blueprint $table) {
                $table->dropColumn('msb_score_value_paise');
            });
        }

        // Dropping them from the registry only makes them unreachable; the
        // seeded rows would linger in the settings table forever. Deleting a
        // compensation-plan setting is an audited change like any other, so the
        // delete and its audit row go in one transaction — the settings must
        // never again be able to disappear without the row that explains why.
        DB::transaction(function () use ($resumingPartialRun): void {
            $removed = DB::table('settings')
                ->whereIn('key', array_keys(self::RETIRED_SETTINGS))
                ->pluck('value', 'key')
                ->all();
            $reconstructed = false;

            if ($removed !== []) {
                DB::table('settings')->whereIn('key', array_keys($removed))->delete();
            } elseif ($resumingPartialRun) {
                // The failed run deletes the settings and only then writes the
                // audit row, so a resume finds nothing left to delete. It only
                // ever got that far because the rows existed, so their values
                // were the seeded ones; recording them keeps the deletion
                // audited rather than silently losing the trail.
                $removed = self::RETIRED_SETTINGS;
                $reconstructed = true;
            }

            if ($removed === []) {
                return;
            }

            $details = [
                'migration' => '2026_07_30_100001_drop_msb_score_value_from_gsb_slabs',
                'reason' => 'Mentorship Bonus rate ladder retired; MSB is priced daily from the pool.',
                'before' => $removed,
                'after' => null,
            ];

            if ($reconstructed) {
                $details['before_reconstructed'] = 'The deletion itself happened during an earlier, failed run of this migration; these are the seeded values it removed.';
            }

            // audit_log is append-only: it has created_at and no updated_at.
            DB::table('audit_log')->insert([
                'action' => 'settings.migration_delete',
                'subject_type' => 'setting',
                'details' => json_encode($details),
                'created_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('gsb_slabs', 'msb_score_value_paise')) {
            Schema::table('gsb_slabs', function (Blueprint $table) {
                $table->unsignedBigInteger('msb_score_value_paise')->default(25_000)->after('msb_score');
            });
        }

        // Restore the ladder settings so the rollback is a true rollback.
        foreach (self::RETIRED_SETTINGS as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()],
            );
        }
    }
};
