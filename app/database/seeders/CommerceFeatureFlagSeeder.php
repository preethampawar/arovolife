<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class CommerceFeatureFlagSeeder extends Seeder
{
    public function run(): void
    {
        $flags = [
            // Storefront
            ['key' => 'commerce.storefront.enabled',              'value' => 'true'],
            ['key' => 'commerce.checkout.enabled',                'value' => 'true'],
            ['key' => 'commerce.guest_checkout.enabled',          'value' => 'true'],   // Default: ON (Part 16 decision 1)

            // Attribution & cooling-off
            ['key' => 'commerce.attribution.window_days',         'value' => '30'],      // Default: 30 (Part 16 decision 2)
            ['key' => 'commerce.attribution.logged_in_overrides_ref', 'value' => 'true'],
            ['key' => 'commerce.cooling_off.days',                'value' => '30'],      // Statutory floor

            // Anti-fraud
            ['key' => 'commerce.orders.daily_cap_per_customer',   'value' => '5'],       // Default: 5 (Part 16 decision 5)

            // Self-purchase
            ['key' => 'commerce.self_purchase.earns_pv',          'value' => 'true'],    // Default: PV yes (Part 16 decision 3)
            ['key' => 'commerce.self_purchase.earns_retail_margin', 'value' => 'false'], // Default: retail margin no

            // Shipping scope
            ['key' => 'commerce.shipping.india_mainland_only',    'value' => 'true'],    // Default: YES (Part 16 decision 4)

            // Compensation (OFF in Phase 2 — dark-launch insurance)
            ['key' => 'compensation.accrual.enabled',             'value' => 'false'],
            ['key' => 'compensation.unlock.enabled',              'value' => 'false'],
            ['key' => 'compensation.payout.enabled',              'value' => 'false'],

            // Compliance
            ['key' => 'compliance.crawler.enabled',               'value' => 'false'],   // Manual review in Phase 2

            // Payments
            ['key' => 'payments.gateway.razorpay.enabled',        'value' => 'false'],
            ['key' => 'payments.gateway.stub.enabled',            'value' => 'true'],    // Dev default
        ];

        foreach ($flags as $flag) {
            DB::table('settings')->updateOrInsert(
                ['key' => $flag['key']],
                ['value' => $flag['value'], 'version' => 1, 'updated_at' => now()],
            );
        }

        $this->command->info('Seeded '.count($flags).' commerce/compensation feature flags.');
    }
}
