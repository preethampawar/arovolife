<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Client 2026-11-04: revenue split GSB 42 / GBB 6 / FB 5 / MB 4 / RB 20 /
     * Awards 20 / AEC 3, and the combined monthly income cap ₹50 lakh → ₹1 crore.
     *
     * Each key moves only while its row still holds the previous default, so an
     * admin override is kept; the audit row records `moved` per key either way,
     * so an environment left on an old figure is visible. Fortune, Rank,
     * Awards and AEC shares are unchanged. A fresh database has no rows yet and
     * takes the new code defaults.
     *
     * @var array<string, array{0: string, 1: string}> key => [previous default, new value]
     */
    private const CHANGES = [
        'comp.gsb.pool_rate_bp' => ['4500', '4200'],
        'comp.gbb.pool_rate_bp' => ['400', '600'],
        'comp.msb.pool_rate_bp' => ['300', '400'],
        'comp.monthly_income_cap_paise' => ['500000000', '1000000000'],
    ];

    private const ACTION = 'plan.migration.apply_client_2026_11_04_revenue_split';

    public function up(): void
    {
        // `migrate --pretend` (app:deploy's dry run) only prints SQL, and the
        // audit row's binary hash cannot be printed — show the updates and stop.
        if (DB::pretending()) {
            foreach (self::CHANGES as $key => [$from, $to]) {
                DB::table('settings')->where('key', $key)->where('value', $from)->update(['value' => $to]);
            }

            return;
        }

        DB::transaction(function (): void {
            $results = [];
            $beforeMap = [];
            $afterMap = [];

            foreach (self::CHANGES as $key => [$from, $to]) {
                $before = DB::table('settings')->where('key', $key)->value('value');
                if ($before === null) {
                    continue;
                }

                $moved = DB::table('settings')
                    ->where('key', $key)
                    ->where('value', $from)
                    ->update(['value' => $to, 'updated_at' => now()]) > 0;

                $beforeMap[$key] = $before;
                $afterMap[$key] = $moved ? $to : $before;
                $results[$key] = [
                    'moved' => $moved,
                    'before' => $before,
                    'value' => $moved ? $to : $before,
                ];
            }

            if ($results === []) {
                return;
            }

            // Through the model, not a raw insert: the creating hook links the
            // row into the audit hash chain, which a raw insert would skip.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'setting',
                'subject_id' => null,
                // Digests of the settings before and after (raw BINARY(32) bytes).
                'before_hash' => AuditLog::digest((string) json_encode($beforeMap)),
                'after_hash' => AuditLog::digest((string) json_encode($afterMap)),
                'details' => [
                    'migration' => '2026_11_10_100000_apply_client_2026_11_04_revenue_split',
                    'reason' => 'Client 2026-11-04: GSB 42%, GBB 6%, MB 4%; monthly income cap ₹1 crore.',
                    'settings' => $results,
                ],
            ]);
        });
    }

    public function down(): void
    {
        // Never guess: a value may have been an admin override that was left
        // alone. Restore each key from the audit row's settings.<key>.before.
        throw new RuntimeException(
            'Cannot roll back '.self::ACTION.': restore each setting from the audit row\'s settings.<key>.before.'
        );
    }
};
