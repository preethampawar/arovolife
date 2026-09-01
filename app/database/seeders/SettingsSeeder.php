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

        $this->seedCompensationPlanScalars($now);
        $this->seedSecuritySettings($now);
    }

    /**
     * Compensation-plan scalar parameters (KP-confirmed defaults). Rates are
     * stored as integer basis points (5% = 500) because the settings registry
     * has no float type; CompensationPlanSettingsService divides by 10,000.
     *
     * These are seeded so the admin "Compensation plan" group shows a value
     * immediately. They are upserted on `value` ONLY (not version) so an admin
     * who later edits one via /admin/settings won't have their change reverted
     * by a re-run of this seeder — EXCEPT we deliberately do NOT re-write a key
     * that already exists, to avoid clobbering live edits. Hence insertOrIgnore.
     */
    private function seedCompensationPlanScalars(string $now): void
    {
        $defaults = [
            'comp.admin_charge.rate_bp' => '300',          // 3%
            // Admin-charge scope toggles — ON for the 6 cash bonuses; OFF for
            // non-cash Lifetime Awards (KP 2026-06-27 Round-2 Q6: only cash/
            // cheque releases carry admin charge + TDS).
            'comp.admin_charge.applies_to_gsb' => 'true',
            'comp.admin_charge.applies_to_mb' => 'true',
            'comp.admin_charge.applies_to_rank' => 'true',
            'comp.admin_charge.applies_to_gbb' => 'true',
            'comp.admin_charge.applies_to_fortune' => 'true',
            'comp.admin_charge.applies_to_adc' => 'true',
            'comp.admin_charge.applies_to_awards' => 'false',
            'comp.tds.rate_bp' => '500',                    // 5%
            'comp.gsb.power_cf_cap_paise' => '45000000',    // 4,50,000 BV
            'comp.gsb.pool_rate_bp' => '4500',              // 45% daily GSB pool (slabs 3–7 pro-rated)
            'comp.gsb.min_bv_paise' => '60000',             // 600 BV personal minimum before income accrues
            'comp.gsb.topup_golive_date' => '1970-01-01',   // permissive default; production pins it in GsbSlabsSeeder
            'comp.msb.pool_rate_bp' => '300',               // 3% daily MSB pool (÷ the day's total MSB points)
            'comp.gbb.pool_rate_bp' => '500',               // 5% of the month's company BV
            'comp.gbb.agp_cap' => '120',
            'comp.rank.envelope_bp' => '2000',              // 20% of company BV funds all nine rank pools
            'comp.rank.pay_highest_rank_only' => 'true',    // exclusive rank pools (plan text); false = cumulative
            'comp.rank.aogo_points' => '5',                 // AO-GO points per grant (Rank-1 pool)
            'comp.rank.aogo_lifetime_max' => '3',           // AO-GO uses per distributor, lifetime
            'comp.adc.rate_bp' => '300',                    // 3%
            'comp.adc.cap_paise' => '10000000',             // ₹1,00,000
            'comp.repurchase.rate_bp' => '1000',            // 10%
            'comp.repurchase.cap_paise' => '1000000',       // ₹10,000
            'comp.repurchase.grace_days' => '0',
            'comp.repurchase.non_ranked_bv_paise' => '60000', // 600 BV
            'comp.fortune.pool_rate_bp' => '500',           // 5% of the month's company BV (÷ the month's total FB points)
            'comp.fortune.exclude_rank_6' => 'true',
            'comp.fortune.exclude_rank_7' => 'true',
            'comp.fortune.exclude_rank_8' => 'true',
            'comp.fortune.exclude_rank_9' => 'true',
        ];

        $records = [];
        foreach ($defaults as $key => $value) {
            $records[] = [
                'key' => $key,
                'value' => $value,
                'version' => 1,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // insertOrIgnore: never overwrite an admin's live edit on re-seed.
        DB::table('settings')->insertOrIgnore($records);

        // KP amendment: minimum payout threshold ₹500 → ₹100 (10,000 paise).
        // This is a deliberate default change. Only flip it if it is still at
        // the old built-in default (50,000) or absent — never clobber a value
        // an admin has intentionally tuned to something else.
        $existing = DB::table('settings')->where('key', 'payout.min_threshold_paise')->first();
        if ($existing === null) {
            DB::table('settings')->insert([
                'key' => 'payout.min_threshold_paise',
                'value' => '10000',
                'version' => 1,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } elseif ((string) $existing->value === '50000') {
            DB::table('settings')->where('key', 'payout.min_threshold_paise')->update([
                'value' => '10000',
                'version' => ((int) ($existing->version ?? 0)) + 1,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Security / rate-limiting defaults. insertOrIgnore so an admin who has
     * already tuned these (e.g. after a bot wave) keeps their edit on re-seed.
     */
    private function seedSecuritySettings(string $now): void
    {
        DB::table('settings')->insertOrIgnore([
            [
                'key' => 'security.registration_throttle_requests',
                'value' => '60',
                'version' => 1,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'security.registration_throttle_window_minutes',
                'value' => '60',
                'version' => 1,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}
