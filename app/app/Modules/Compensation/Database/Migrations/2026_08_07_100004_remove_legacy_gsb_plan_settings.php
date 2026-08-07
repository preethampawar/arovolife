<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retires the last two legacy GSB settings keys (user directive 2026-08-07 —
 * no superseded plan setting may survive for any bonus type).
 *
 * 1. `comp.gsb.score_rate_paise` — the single global ₹360 score rate, replaced
 *    on 2026-07-21 by per-slab gsb_slabs.score_value_paise. Its accessor,
 *    registry entry and seeder line are gone, so the row is dead config.
 * 2. `payout.gsb_min_bv_paise` — the pre-service spelling of the 600 BV income
 *    minimum. CompensationPlanSettingsService::gsbMinBvPaise() used to prefer
 *    it over `comp.gsb.min_bv_paise`; that fallback is removed, so any value an
 *    admin set on the legacy key would silently stop applying. It is therefore
 *    COPIED to the new key first (only when the new key is absent, so an
 *    explicitly-set new value always wins) and only then deleted.
 *
 * Both are plan config rows in `settings`, not distributor data. The delete and
 * its audit row share one transaction, mirroring
 * 2026_07_30_100001_drop_msb_score_value_from_gsb_slabs.
 */
return new class extends Migration
{
    private const RETIRED_SCORE_RATE_KEY = 'comp.gsb.score_rate_paise';

    private const LEGACY_MIN_BV_KEY = 'payout.gsb_min_bv_paise';

    private const CURRENT_MIN_BV_KEY = 'comp.gsb.min_bv_paise';

    public function up(): void
    {
        DB::transaction(function (): void {
            $legacyMinBv = DB::table('settings')->where('key', self::LEGACY_MIN_BV_KEY)->value('value');
            $currentMinBvExists = DB::table('settings')->where('key', self::CURRENT_MIN_BV_KEY)->exists();
            $copied = null;

            if ($legacyMinBv !== null && ! $currentMinBvExists) {
                DB::table('settings')->insert([
                    'key' => self::CURRENT_MIN_BV_KEY,
                    'value' => (string) $legacyMinBv,
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $copied = (string) $legacyMinBv;
            }

            $removed = DB::table('settings')
                ->whereIn('key', [self::RETIRED_SCORE_RATE_KEY, self::LEGACY_MIN_BV_KEY])
                ->pluck('value', 'key')
                ->all();

            if ($removed === []) {
                return;
            }

            DB::table('settings')->whereIn('key', array_keys($removed))->delete();

            // audit_log is append-only: it has created_at and no updated_at.
            DB::table('audit_log')->insert([
                'action' => 'settings.migration_delete',
                'subject_type' => 'setting',
                'details' => json_encode([
                    'migration' => '2026_08_07_100004_remove_legacy_gsb_plan_settings',
                    'reason' => 'Global GSB score rate superseded by per-slab score values (KP 2026-07-21); legacy min-BV key folded into comp.gsb.min_bv_paise.',
                    'before' => $removed,
                    'after' => null,
                    'copied_to_'.self::CURRENT_MIN_BV_KEY => $copied,
                ]),
                'created_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => self::RETIRED_SCORE_RATE_KEY],
            ['value' => '36000', 'version' => 1, 'created_at' => now(), 'updated_at' => now()],
        );

        $currentMinBv = DB::table('settings')->where('key', self::CURRENT_MIN_BV_KEY)->value('value');

        DB::table('settings')->updateOrInsert(
            ['key' => self::LEGACY_MIN_BV_KEY],
            ['value' => (string) ($currentMinBv ?? '60000'), 'version' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
    }
};
