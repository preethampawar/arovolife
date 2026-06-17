<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Content\Models\ContentPage;
use App\Modules\Identity\Models\User;
use App\Modules\Ledger\Models\LedgerAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Production-safe database seeder.
 *
 * Strict invariants:
 *
 *   1. Re-running this seeder NEVER mutates existing rows. Every insert uses
 *      "create if missing" semantics; if a key/slug/code already exists,
 *      this seeder leaves it alone — including any admin-edited values.
 *   2. No PII. No demo distributors, no demo orders, no test passwords. The
 *      DemoDownlineSeeder is intentionally not invoked here.
 *   3. The admin user is created from env vars (PROD_ADMIN_EMAIL +
 *      PROD_ADMIN_PASSWORD) so credentials never live in version control.
 *      If the env vars are missing, admin creation is skipped — bring one
 *      up manually via tinker after the first deploy.
 *   4. Phase-1 content pages are placeholder copy. Once compliance signs
 *      off the final docs, edit them through the admin Content Pages UI;
 *      this seeder will not overwrite them on subsequent runs.
 *
 * Run with:
 *
 *   php artisan db:seed --class=ProductionSeeder --force
 */
final class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedRolesAndAdmin();
            $this->seedRootDistributor();
            $this->seedSettings();
            $this->seedFeatureFlags();
            $this->seedContentPages();
            $this->seedLedgerAccounts();
            $this->seedProductCatalog();
        });

        $this->command->info('Production seeder finished. Existing rows were preserved.');
    }

    /**
     * Ensures the `admin` role exists and, if env credentials are provided,
     * provisions exactly one admin user. Re-running with the same env values
     * is a no-op; changing PROD_ADMIN_PASSWORD does NOT rotate the existing
     * password (rotate via the admin UI or tinker).
     */
    private function seedRolesAndAdmin(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        // Separation-of-duties roles + scoped permissions (R-17).
        $this->call(RolesAndPermissionsSeeder::class);

        $email = (string) config('arovolife.seeder.admin.email', '');
        $password = (string) config('arovolife.seeder.admin.password', '');

        if ($email === '' || $password === '') {
            $this->command->warn('PROD_ADMIN_EMAIL / PROD_ADMIN_PASSWORD not set — skipping admin provisioning.');

            return;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            $this->command->info("Admin already exists ({$email}); leaving password and profile untouched.");
            $existing->syncRoles(['admin']);

            return;
        }

        $admin = User::create([
            'email' => $email,
            'full_name' => (string) config('arovolife.seeder.admin.name', 'Administrator'),
            'phone_e164' => (string) config('arovolife.seeder.admin.phone', '+910000000000'),
            'password_hash' => Hash::make($password),
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $admin->syncRoles(['admin']);

        $this->command->info("Admin provisioned: {$email}");
    }

    /**
     * Create the L0 (genealogy root) distributor on a fresh install.
     *
     * Per ADR-0003 every registrant registers via a referral link from an
     * existing distributor — but a fresh DB has no distributors, so the
     * platform is unreachable. We bootstrap one self-referencing root
     * here whose ADN is shared with the company's first real recruits.
     *
     * Skipped (non-fatal warning) if PROD_ROOT_EMAIL is not set, or if any
     * Distributor row already exists. The latter check makes this seeder
     * safe to re-run after the company's first real registrations land.
     */
    private function seedRootDistributor(): void
    {
        if (DB::table('distributors')->exists()) {
            return;
        }

        $email = (string) config('arovolife.seeder.root_distributor.email', '');
        if ($email === '') {
            $this->command->warn('PROD_ROOT_EMAIL not set — skipping root distributor. Set it and re-run to bootstrap the genealogy.');

            return;
        }

        $name = (string) config('arovolife.seeder.root_distributor.name', 'Arovolife Company Root');
        $phone = (string) config('arovolife.seeder.root_distributor.phone', '+910000000001');
        $state = strtoupper((string) config('arovolife.seeder.root_distributor.state', 'TG'));
        $adn = (string) config('arovolife.seeder.root_distributor.adn', '111222333');
        $rootPassword = (string) (config('arovolife.seeder.root_distributor.password') ?? bin2hex(random_bytes(16)));

        $rootUser = User::query()->firstWhere('email', $email)
            ?? User::create([
                'full_name' => $name,
                'email' => $email,
                'phone_e164' => $phone,
                'password_hash' => Hash::make($rootPassword),
                'password_set_at' => now(),
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

        $now = now()->format('Y-m-d H:i:s.v');

        // Self-references on sponsor_id / placement_parent_id mean the FK
        // can't be satisfied at INSERT time. We disable the check, write
        // the row with a placeholder ID, then point the references back
        // at the row's own id.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $rootId = DB::table('distributors')->insertGetId([
                'user_id' => $rootUser->id,
                'adn' => $adn,
                'pan_hash' => random_bytes(32),
                'pan_last4' => '0000',
                'aadhaar_ref' => 'BOOTSTRAP_ROOT',
                'aadhaar_last4' => '0000',
                'bank_account_enc' => Crypt::encryptString('000000000000'),
                'bank_ifsc' => 'HDFC0000000',
                'sponsor_id' => 1,            // placeholder — rewritten below
                'placement_parent_id' => 1,
                'placement_side' => null,
                'side_chosen_by' => 'referral_default',
                'depth' => 0,
                'effective_date' => $now,
                'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
                'state' => $state,
                'is_primary_couple' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('distributors')->where('id', $rootId)->update([
                'sponsor_id' => $rootId,
                'placement_parent_id' => $rootId,
            ]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // genealogy_closure stores the transitive descendant set; every
        // node has a self-edge at depth 0.
        DB::table('genealogy_closure')->insert([
            'ancestor_id' => $rootId,
            'descendant_id' => $rootId,
            'depth' => 0,
        ]);

        $this->command->info("Root distributor provisioned: {$adn} ({$email}). Share /register?ref={$adn} with the first recruits.");
    }

    /**
     * Compliance / age-rule settings. Only inserts keys that don't exist —
     * any value already in the table (including admin overrides) is kept.
     */
    private function seedSettings(): void
    {
        $defaults = [
            'compliance.state_age_minimums' => (string) config('arovolife.seeder.compliance.state_age_minimums', '{"MH":21}'),
        ];

        $this->insertSettingsIfMissing($defaults);
    }

    /**
     * Commerce / compensation feature flags. Phase 1 ships with compensation
     * OFF (dark launch). Re-running is a no-op for any flag the admin has
     * already toggled in the UI.
     */
    private function seedFeatureFlags(): void
    {
        $defaults = [
            'placement.spillover.enabled' => 'false',   // ADR-0007 — off until PO/Compliance sign-off
            'placement.spillover.strategy' => 'breadth_balanced', // ADR-0007 — fill strategy when spillover is on
            'commerce.storefront.enabled' => 'true',
            'commerce.checkout.enabled' => 'true',
            'commerce.guest_checkout.enabled' => 'true',
            'commerce.attribution.window_days' => '30',
            'commerce.attribution.logged_in_overrides_ref' => 'true',
            'commerce.cooling_off.days' => '30',
            'commerce.orders.daily_cap_per_customer' => '5',
            'commerce.self_purchase.earns_bv' => 'true',
            'commerce.self_purchase.earns_retail_margin' => 'false',
            'commerce.shipping.india_mainland_only' => 'true',
            'commerce.shipping.fee_rupees' => '60',
            'commerce.shipping.free_threshold_rupees' => '4000',
            'compensation.accrual.enabled' => 'false',
            'compensation.unlock.enabled' => 'false',
            'compensation.payout.enabled' => 'false',
            'compliance.crawler.enabled' => 'false',
            'notifications.email_on_status_change' => 'true',
            'notifications.admin_order_email' => 'orders@arovolife.com', // placeholder — set real mailbox before launch

            'payments.cod.enabled' => 'false',
            'payments.gateway.razorpay.enabled' => 'false',
            'payments.gateway.stub.enabled' => 'false',
        ];

        $this->insertSettingsIfMissing($defaults);
    }

    /** @param  array<string, string>  $defaults */
    private function insertSettingsIfMissing(array $defaults): void
    {
        $existing = DB::table('settings')->whereIn('key', array_keys($defaults))->pluck('key')->all();
        $missing = array_diff(array_keys($defaults), $existing);

        if (count($missing) === 0) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($missing as $key) {
            $rows[] = [
                'key' => $key,
                'value' => $defaults[$key],
                'version' => 1,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('settings')->insert($rows);
        $this->command->info('Inserted '.count($rows).' new settings (existing keys untouched).');
    }

    /**
     * Phase-1 placeholder copy. Final docs replace these via the admin UI;
     * once a slug exists, this seeder leaves it alone forever.
     */
    private function seedContentPages(): void
    {
        $pages = [
            ['slug' => 'terms',     'title' => 'Direct Seller Agreement & Terms of Service'],
            ['slug' => 'privacy',   'title' => 'Privacy Policy'],
            ['slug' => 'ethics',    'title' => 'Code of Ethics'],
            ['slug' => 'grievance', 'title' => 'Grievance Redressal'],
        ];

        foreach ($pages as $page) {
            $exists = ContentPage::query()->where('slug', $page['slug'])->exists();
            if ($exists) {
                continue;
            }

            ContentPage::create([
                'slug' => $page['slug'],
                'title' => $page['title'],
                'meta_description' => $page['title'].' — placeholder copy. Replace via admin UI.',
                'body' => '<p><em>This is a Phase 1 placeholder. The final document will be issued before production launch. Edit this page via the admin Content Pages UI.</em></p>',
                'status' => ContentPage::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);
        }
    }

    /**
     * Chart of accounts. These codes are structural — they're referenced by
     * the ledger service. Adding the row when missing is safe; renaming an
     * existing one through the seeder is not, so we never update.
     */
    private function seedLedgerAccounts(): void
    {
        $accounts = [
            ['code' => 'asset.cash.gateway.razorpay',   'name' => 'Cash held at Razorpay',                         'type' => 'asset'],
            ['code' => 'asset.cash.bank.settlement',    'name' => 'Settlement bank account',                       'type' => 'asset'],
            ['code' => 'asset.inventory',               'name' => 'Product inventory at cost',                     'type' => 'asset'],
            ['code' => 'asset.gst_input_itc',           'name' => 'GST Input Tax Credit',                          'type' => 'asset'],
            ['code' => 'liability.customer_prepayment', 'name' => 'Customer prepayment (paid, not delivered)',     'type' => 'liability'],
            ['code' => 'liability.commission_held',     'name' => 'Commissions held (cooling-off)',                'type' => 'liability'],
            ['code' => 'liability.commission_payable',  'name' => 'Commissions payable (unlocked)',                'type' => 'liability'],
            ['code' => 'liability.tds_payable',         'name' => 'TDS payable to Income Tax Dept',                'type' => 'liability'],
            ['code' => 'liability.gst_output',          'name' => 'GST output (collected)',                        'type' => 'liability'],
            ['code' => 'liability.wallet_debt',         'name' => 'Distributor wallet debt',                       'type' => 'liability'],
            ['code' => 'revenue.sales',                 'name' => 'Product sales revenue',                         'type' => 'revenue'],
            ['code' => 'revenue.shipping',              'name' => 'Shipping revenue',                              'type' => 'revenue'],
            ['code' => 'revenue.house_margin',          'name' => 'Un-attributed retail margin',                   'type' => 'revenue'],
            ['code' => 'revenue.admin_charge',          'name' => 'Admin charge (3%) income',                      'type' => 'revenue'],
            ['code' => 'expense.cogs',                  'name' => 'Cost of goods sold',                            'type' => 'expense'],
            ['code' => 'expense.commission',            'name' => 'Commission expense',                            'type' => 'expense'],
            ['code' => 'equity.retained',               'name' => 'Retained earnings',                             'type' => 'equity'],
        ];

        $inserted = 0;
        foreach ($accounts as $a) {
            $created = LedgerAccount::firstOrCreate(['code' => $a['code']], $a);
            if ($created->wasRecentlyCreated) {
                $inserted++;
            }
        }

        if ($inserted > 0) {
            $this->command->info("Inserted {$inserted} new ledger accounts.");
        }
    }

    /**
     * Product catalogue. On a fresh database we seed the Phase-1 placeholder
     * catalogue so /shop has something to render. Once the merchandising
     * team imports real SKUs through the admin UI, those rows take
     * precedence and re-running this seeder will not touch them.
     *
     * The check is "if ANY product exists, skip entirely" — by design, so
     * we don't end up with a half-real / half-placeholder catalogue.
     */
    private function seedProductCatalog(): void
    {
        if (Product::query()->exists()) {
            return;
        }

        $products = [
            [
                'sku' => 'AV-HW-001', 'slug' => 'gentle-hand-wash', 'category' => 'personal-care',
                'name' => 'arovolife Gentle Hand Wash',
                'short_description' => 'Aloe vera & neem. 250 ml pump bottle.',
                'description' => 'A daily-use hand wash with aloe vera and neem extract. Paraben-free. Safe for sensitive skin.',
                'hsn_code' => '3401',
                'image_url' => 'https://images.unsplash.com/photo-1584305574647-0cc949a2bb9f?auto=format&fit=crop&w=600&h=600&q=80',
                'mrp' => 29500, 'sale' => 24500, 'cost' => 8000, 'bv' => 15000, 'gst_bp' => 1800,
            ],
            [
                'sku' => 'AV-SH-001', 'slug' => 'scalpcare-shampoo', 'category' => 'personal-care',
                'name' => 'arovolife ScalpCare Shampoo',
                'short_description' => 'Anti-dandruff formula. 300 ml.',
                'description' => 'Clinically tested anti-dandruff shampoo with tea tree and piroctone olamine. Sulphate-free.',
                'hsn_code' => '3305',
                'image_url' => 'https://images.unsplash.com/photo-1556228720-195a672e8a03?auto=format&fit=crop&w=600&h=600&q=80',
                'mrp' => 135000, 'sale' => 115000, 'cost' => 38000, 'bv' => 80000, 'gst_bp' => 1800,
            ],
            [
                'sku' => 'AV-MV-001', 'slug' => 'multi-vitamin', 'category' => 'health',
                'name' => 'arovolife Multi-Vitamin',
                'short_description' => '30 tablets. Daily essentials.',
                'description' => 'Daily multivitamin with 12 vitamins and 9 minerals. Consult a physician before use.',
                'hsn_code' => '2936',
                'image_url' => 'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?auto=format&fit=crop&w=600&h=600&q=80',
                'mrp' => 89900, 'sale' => 79900, 'cost' => 22000, 'bv' => 50000, 'gst_bp' => 1200,
            ],
            [
                'sku' => 'AV-OL-001', 'slug' => 'hair-essential-oil', 'category' => 'personal-care',
                'name' => 'arovolife Hair Essential Oil',
                'short_description' => 'Bhringraj & amla. 200 ml.',
                'description' => 'Traditional hair oil with bhringraj, amla and coconut base. For strength and shine.',
                'hsn_code' => '3305',
                'image_url' => 'https://images.unsplash.com/photo-1608248543803-ba4f8c70ae0b?auto=format&fit=crop&w=600&h=600&q=80',
                'mrp' => 115000, 'sale' => 99900, 'cost' => 28000, 'bv' => 70000, 'gst_bp' => 1800,
            ],
            [
                'sku' => 'AV-FD-001', 'slug' => 'herbal-green-tea', 'category' => 'food',
                'name' => 'arovolife Herbal Green Tea',
                'short_description' => '25 bags. Tulsi & ashwagandha.',
                'description' => 'Daily wellness tea blend with tulsi, ashwagandha and green tea leaves. Caffeine-lite.',
                'hsn_code' => '0902',
                'image_url' => 'https://images.unsplash.com/photo-1556679343-c7306c1976bc?auto=format&fit=crop&w=600&h=600&q=80',
                'mrp' => 45000, 'sale' => 39900, 'cost' => 12000, 'bv' => 25000, 'gst_bp' => 500,
            ],
        ];

        foreach ($products as $data) {
            $product = Product::create([
                'sku' => $data['sku'],
                'slug' => $data['slug'],
                'name' => $data['name'],
                'short_description' => $data['short_description'],
                'description' => $data['description'],
                'category' => $data['category'],
                'hsn_code' => $data['hsn_code'],
                'image_url' => $data['image_url'],
                'status' => Product::STATUS_ACTIVE,
            ]);

            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'variant_sku' => $data['sku'].'-V1',
                'name' => 'Default',
                'mrp_paise' => $data['mrp'],
                'sale_price_paise' => $data['sale'],
                'cost_paise' => $data['cost'],
                'bv_paise' => $data['bv'],
                'gst_rate_bp' => $data['gst_bp'],
                'inventory_policy' => 'track',
                'status' => 'active',
            ]);

            InventoryLevel::create([
                'product_variant_id' => $variant->id,
                'warehouse_code' => 'DEFAULT',
                'on_hand' => 500,
                'reserved' => 0,
            ]);
        }

        $this->command->info('Seeded '.count($products).' placeholder products (catalogue was empty).');
    }
}
