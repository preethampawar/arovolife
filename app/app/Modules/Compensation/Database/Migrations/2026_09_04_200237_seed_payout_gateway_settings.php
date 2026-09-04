<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Defaults for the payout gateway levers. Deliberately additive — an existing
 * row is never overwritten, so re-running the migration on an environment
 * where ops already chose a gateway does not silently switch it back.
 *
 * Ships as `manual_neft`: no environment may start dispatching real bank
 * transfers through an API just because a migration ran.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const DEFAULTS = [
        'payout.gateway' => 'manual_neft',
        'payout.razorpay.max_retries' => '3',
        'payout.razorpay.auto_retry_hours' => '24',
        'payout.razorpay.transfer_mode' => 'NEFT',
        'payout.razorpay.narration' => 'Arovolife Commission',
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::DEFAULTS as $key => $value) {
            if (DB::table('settings')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('settings')->insert([
                'key' => $key,
                'value' => $value,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_keys(self::DEFAULTS))->delete();
    }
};
