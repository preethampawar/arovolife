<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * User decision 2026-10-17: no month-end repurchase-wallet gate on the Fortune
 * Bonus, so the toggle comp.fortune.require_repurchase_wallet_zero has nothing
 * to switch. The key is gone from the registry, the seeder and the plan
 * defaults; this removes the row so admins no longer see a control that does
 * nothing.
 *
 * The audit row records the value it held (`deleted: false` when the row was
 * already absent).
 */
return new class extends Migration
{
    private const ACTION = 'plan.migration.remove_fortune_require_repurchase_wallet_zero_setting';

    private const KEY = 'comp.fortune.require_repurchase_wallet_zero';

    public function up(): void
    {
        DB::transaction(function (): void {
            $before = DB::table('settings')->where('key', self::KEY)->value('value');
            $deleted = DB::table('settings')->where('key', self::KEY)->delete() > 0;

            // Through the model, not a raw insert: the creating hook links the
            // row into the audit hash chain, which a raw insert would skip.
            AuditLog::create([
                'actor_id' => null,
                'action' => self::ACTION,
                'subject_type' => 'setting',
                'subject_id' => null,
                'details' => [
                    'migration' => '2026_10_17_100100_remove_fortune_require_repurchase_wallet_zero_setting',
                    'reason' => 'User decision 2026-10-17: no month-end repurchase-wallet gate on the Fortune Bonus.',
                    'key' => self::KEY,
                    'value_before' => $before !== null ? (string) $before : null,
                    'deleted' => $deleted,
                ],
            ]);
        });
    }

    public function down(): void
    {
        // Never guess: the deleted value is only known from the audit row.
        throw new RuntimeException(
            'Cannot roll back '.self::ACTION.': restore from the plan.migration.* audit row '
            .'(value_before holds the deleted value).'
        );
    }
};
