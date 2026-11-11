<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEY = 'comp.tds.rate_bp';

    private const VALUE = '200'; // 2% (user decision 2026-11-11; was 500 = 5%)

    private const ACTION = 'plan.migration.set_tds_rate_to_two_percent';

    /**
     * TDS on bonuses at payout drops to 2% (user decision 2026-11-11, pending
     * the CA's confirmation of the section). The existing row is overwritten
     * whatever it holds; the audit row keeps the previous value. A fresh
     * database has no row and takes the new code default.
     */
    public function up(): void
    {
        // `migrate --pretend` only prints SQL; the audit row's binary hash
        // cannot be printed — show the update and stop.
        if (DB::pretending()) {
            DB::table('settings')->where('key', self::KEY)->update(['value' => self::VALUE]);

            return;
        }

        DB::transaction(function (): void {
            $before = DB::table('settings')->where('key', self::KEY)->value('value');
            if ($before === null) {
                return;
            }

            DB::table('settings')
                ->where('key', self::KEY)
                ->update(['value' => self::VALUE, 'updated_at' => now()]);

            // Through the model so the creating hook links the hash chain.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'setting',
                'subject_id' => null,
                'before_hash' => AuditLog::digest((string) json_encode([self::KEY => $before])),
                'after_hash' => AuditLog::digest((string) json_encode([self::KEY => self::VALUE])),
                'details' => [
                    'migration' => '2026_11_11_090000_set_tds_rate_to_two_percent',
                    'reason' => 'User decision 2026-11-11: TDS on bonuses 2% until the CA confirms the section and rate.',
                    'settings' => [self::KEY => [
                        'moved' => $before !== self::VALUE,
                        'before' => $before,
                        'value' => self::VALUE,
                    ]],
                ],
            ]);
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Cannot roll back '.self::ACTION.': restore the setting from the audit row\'s settings.'.self::KEY.'.before.'
        );
    }
};
