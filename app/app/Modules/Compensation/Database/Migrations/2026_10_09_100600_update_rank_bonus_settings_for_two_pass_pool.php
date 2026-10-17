<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Client 2026-10-05 Rank Income Point System: the AGO offer carries 36
     * points (was 5), every Rank Achievement Point is worth at most ₹200, and
     * the AGO offer plus Ranks 1–3 are priced first from the whole envelope.
     *
     * The AO-GO points move only while the row still holds the old default
     * (5), so an admin override is kept; the audit row records `moved` either
     * way so an environment still on 5 is visible. The two new keys are
     * inserted with insertOrIgnore and the audit row records whether each was
     * actually inserted.
     */
    private const ACTION = 'plan.migration.rank_bonus_settings_two_pass';

    private const AOGO_KEY = 'comp.rank.aogo_points';

    private const NEW_KEYS = [
        'comp.rank.point_value_cap_paise' => '20000',
        'comp.rank.first_pass_max_rank' => '3',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $aogoBefore = DB::table('settings')->where('key', self::AOGO_KEY)->value('value');
            $moved = DB::table('settings')
                ->where('key', self::AOGO_KEY)
                ->where('value', '5')
                ->update(['value' => '36', 'updated_at' => now()]) > 0;

            $now = now();
            $inserted = [];
            foreach (self::NEW_KEYS as $key => $value) {
                $inserted[$key] = [
                    'key' => $key,
                    'inserted' => DB::table('settings')->insertOrIgnore([
                        'key' => $key,
                        'value' => $value,
                        'version' => 1,
                        'updated_by' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]) > 0,
                    'value' => (string) DB::table('settings')->where('key', $key)->value('value'),
                ];
            }

            // Through the model, not a raw insert: the creating hook links the
            // row into the audit hash chain, which a raw insert would skip.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'setting',
                'subject_id' => null,
                'details' => [
                    'migration' => '2026_10_09_100600_update_rank_bonus_settings_for_two_pass_pool',
                    'reason' => 'Client 2026-10-05 Rank Income Point System: AGO 36 points, ₹200 point value cap, Ranks 1–3 priced in pass 1.',
                    'aogo_points' => [
                        'key' => self::AOGO_KEY,
                        'moved' => $moved,
                        'before' => $aogoBefore,
                        'value' => $moved ? '36' : $aogoBefore,
                    ],
                    'point_value_cap_paise' => $inserted['comp.rank.point_value_cap_paise'],
                    'first_pass_max_rank' => $inserted['comp.rank.first_pass_max_rank'],
                ],
            ]);
        });
    }

    public function down(): void
    {
        // Never guess: aogo_points may have been an admin override that was
        // left alone, and a new key may have pre-existed. Restore from the
        // audit row (aogo_points.before; delete a new key only where
        // `inserted` is true).
        throw new RuntimeException('restore from the '.self::ACTION.' audit row');
    }
};
