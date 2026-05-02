<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now()->format('Y-m-d H:i:s.v');

        // ADR-0003 deleted the placement.* admin settings. Placement is now
        // an invariant rule (referral link → single-level slot, default
        // left). Only state-age minimums remain.
        //
        // The whereIn(...)->delete() below is an idempotent legacy-cleanup;
        // the keys are NOT written by any current code path (verified via
        // grep across app/, resources/, routes/). Safe to remove this block
        // after one production deploy.
        DB::table('settings')
            ->whereIn('key', ['placement.default_side', 'placement.allow_sponsor_override'])
            ->delete();

        DB::table('settings')->upsert([
            [
                // JSON map of state-code → minimum age. Default of 18 is
                // applied for any state not listed here; Maharashtra requires 21.
                'key' => 'compliance.state_age_minimums',
                'value' => env('COMPLIANCE_STATE_AGE_MINIMUMS', '{"MH":21}'),
                'version' => 1,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['key'], ['value', 'version', 'updated_at']);
    }
}
