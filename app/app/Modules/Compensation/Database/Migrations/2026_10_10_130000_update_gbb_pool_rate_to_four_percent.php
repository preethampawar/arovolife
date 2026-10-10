<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Client 2026-10-10: the Growth Booster pool drops from 5% to 4% of the
     * month's company BV.
     *
     * The rate moves only while the row still holds the old default (500), so
     * an admin override is kept; the audit row records `moved` either way so an
     * environment still on 5% is visible. Same rule as the R.S.P. branch's
     * GBB migration, which becomes a no-op for the rate once this has run.
     */
    private const ACTION = 'plan.migration.update_gbb_pool_rate_to_four_percent';

    private const POOL_RATE_KEY = 'comp.gbb.pool_rate_bp';

    public function up(): void
    {
        // `migrate --pretend` (run by app:deploy as a dry run) only prints SQL,
        // and the audit row's binary hash cannot be printed — show the update
        // and stop.
        if (DB::pretending()) {
            DB::table('settings')->where('key', self::POOL_RATE_KEY)->where('value', '500')->update(['value' => '400']);

            return;
        }

        DB::transaction(function (): void {
            $before = DB::table('settings')->where('key', self::POOL_RATE_KEY)->value('value');
            $moved = DB::table('settings')
                ->where('key', self::POOL_RATE_KEY)
                ->where('value', '500')
                ->update(['value' => '400', 'updated_at' => now()]) > 0;

            // Through the model, not a raw insert: the creating hook links the
            // row into the audit hash chain, which a raw insert would skip.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'setting',
                'subject_id' => null,
                'details' => [
                    'migration' => '2026_10_10_130000_update_gbb_pool_rate_to_four_percent',
                    'reason' => 'Client 2026-10-10: Growth Booster pool 4% of the month\'s company BV.',
                    'pool_rate' => [
                        'key' => self::POOL_RATE_KEY,
                        'moved' => $moved,
                        'before' => $before,
                        'value' => $moved ? '400' : $before,
                    ],
                ],
            ]);
        });
    }

    public function down(): void
    {
        // Never guess: the rate may have been an admin override that was left
        // alone. Restore comp.gbb.pool_rate_bp from the audit row's
        // pool_rate.before by hand.
        throw new RuntimeException(
            'Cannot roll back '.self::ACTION.': restore comp.gbb.pool_rate_bp from the audit row\'s pool_rate.before.'
        );
    }
};
