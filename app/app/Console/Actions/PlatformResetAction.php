<?php

declare(strict_types=1);

namespace App\Console\Actions;

use App\Modules\Compensation\Support\DerivedTables;
use App\Modules\Compensation\Support\EngineScheduleWindow;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\User;
use Closure;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\CommerceFeatureFlagSeeder;
use Database\Seeders\ContentPageSeeder;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One-shot full platform reset: wipes transactional data — including
 * every purchase-derived table (orders, BV, bonuses, wallets, payouts;
 * see PurchaseDataResetAction::wipeTables()) — scrubs S3 KYC files, then
 * re-seeds the canonical bootstrap state (roles, admin, settings,
 * content pages, ledger COA, feature flags, product catalog, and the
 * 31 company-blocked reserved distributors occupying tree levels 0-4).
 *
 * Idempotent — running twice yields the identical post-state because
 * every step either uses firstOrCreate/updateOrCreate semantics or fully
 * truncates before re-inserting. Stable ADN block (see ReservedAdns)
 * guarantees the reserved distributors get the same identifiers on
 * every run.
 */
final class PlatformResetAction
{
    /**
     * Tables wiped in FK-safe order. Order matters: children before parents.
     * Anything not listed here is left alone (e.g. schema_migrations).
     *
     * @var list<string>
     */
    private const PLATFORM_TABLES = [
        // Commerce identities + their trail
        'customer_addresses',
        'customers',
        'attribution_touches',
        'notifications',
        // Queue — pending jobs reference users/orders that no longer exist
        'jobs',
        'job_batches',
        'failed_jobs',
        // Transactional / leaf rows
        'consents',
        'orientation_views',
        'cooling_off_events',
        'kyc_documents',
        'line_change_requests',
        // Family Card members — keyed on distributor_id; a truncate with FK
        // checks off would otherwise leave them attached to a re-used id.
        'distributor_family_members',
        // Everything else keyed on a distributor, user or order. Left out until
        // 2026-10-19, and the prod wipe that day showed why each one matters:
        // truncating `distributors` restarts AUTO_INCREMENT, so the first real
        // sign-ups took ids 64 and 65 — ids whose profile, nominee and Arete
        // membership rows from that morning's test registrations had survived.
        // The profile insert hit the unique key and both joiners got a 500
        // after their distributor row had already committed.
        'distributor_profiles',
        'distributor_nominees',
        'distributor_request_documents',
        'distributor_requests',
        'arete_center_application_declarations',
        'arete_center_application_documents',
        'arete_center_applications',
        'arete_center_members',
        'gsb_cutoff_deferrals',
        'gsb_reversal_requests',
        'offline_payments',
        'payment_events',
        'shipment_events',
        'payout_gateway_events',
        'payout_bank_file_rows',
        'payout_bank_files',
        'ticket_attachments',
        'ticket_events',
        'tickets',
        'message_blocks',
        'message_reports',
        'messages',
        'announcement_reads',
        'action_center_snoozes',
        'contact_inquiries',
        // The tax-invoice series restarts with the invoices (CGST Rule 46):
        // a surviving counter would open the real series at test number 65.
        'invoice_number_sequences',
        // Pulse telemetry names users by id, and ids are reused after a wipe.
        'pulse_entries',
        'pulse_aggregates',
        'pulse_values',
        // Tree + main
        'sponsorship',
        'genealogy_closure',
        'distributors',
        // Audit
        'audit_log',
        // Spatie role/permission assignments — re-seeded by AdminUserSeeder
        'model_has_roles',
        'model_has_permissions',
        'role_has_permissions',
        'roles',
        'permissions',
        // Users — re-seeded by AdminUserSeeder + buildReservedTree()
        'password_reset_tokens',
        'sessions',
        'users',
    ];

    /**
     * Tables the reset deliberately keeps: configuration, the plan, the
     * catalogue and framework bookkeeping. Every table in the schema must be
     * in exactly one of wipeTables() or this list — PlatformResetCoverageTest
     * fails on a new table that is in neither, so it can't be silently kept.
     *
     * @var list<string>
     */
    public const KEPT_TABLES = [
        'migrations',
        'cache',
        'cache_locks',
        'settings',
        'features',
        'content_pages',
        'compliance_documents',
        'agreements',
        'announcements',
        'banners',
        'ledger_accounts',
        'gsb_slabs',
        'rank_tiers',
        'fortune_bonus_levels',
        'fortune_bonus_tiers',
        'lifetime_award_rewards',
        'lifetime_award_tranches',
        'arete_centers',
        'arete_center_declarations',
        'coupons',
        'monthly_offer_products',
        'product_categories',
        'products',
        'product_variants',
        'product_images',
        'product_attributes',
        'landing_price_history',
        'inventory_levels',
        'warehouses',
        'suppliers',
    ];

    /**
     * Person references inside KEPT_TABLES, nulled after the wipe. Users and
     * distributors restart at the same ids, so a surviving reference would
     * name — or, for arete_centers.assigned_distributor_id, hand an ADC and its
     * income to — whichever real joiner takes that id next.
     *
     * @var array<string, list<string>>
     */
    public const KEPT_TABLE_PERSON_COLUMNS = [
        'settings' => ['updated_by'],
        'content_pages' => ['updated_by_user_id'],
        'compliance_documents' => ['uploaded_by'],
        'announcements' => ['created_by_user_id'],
        'arete_centers' => ['assigned_distributor_id'],
        'arete_center_declarations' => ['accepted_by_user_id'],
        'coupons' => ['created_by_user_id'],
        'monthly_offer_products' => ['created_by_user_id'],
        'products' => ['created_by_user_id'],
        'landing_price_history' => ['changed_by_user_id'],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PurchaseDataResetAction $purchaseReset,
        private readonly SeedReservedTreeAction $reservedTree,
    ) {}

    /**
     * The full nuke list, in FK-safe order: everything the purchase reset
     * removes (which in turn single-sources its compensation half from
     * {@see DerivedTables}), then the
     * platform's own identity, tree, consent and audit tables.
     *
     * @return list<string>
     */
    public static function wipeTables(): array
    {
        return [...PurchaseDataResetAction::wipeTables(), ...self::PLATFORM_TABLES];
    }

    /**
     * @param  Closure(string): void|null  $progress  optional callback for CLI output
     */
    public function execute(?Closure $progress = null): void
    {
        if (EngineScheduleWindow::isActiveNow()) {
            throw PurchaseResetBlocked::duringEngineWindow();
        }

        $log = $progress ?? static fn (string $_m): null => null;

        $log('Cleaning S3 KYC objects...');
        $this->wipeS3Files($log);

        $log('Truncating transactional tables...');
        $this->truncateAll();

        $log('Removing test ADCs and person references from kept tables...');
        $this->clearPersonReferences();

        $log('Resetting derived counters (coupons.used_count, inventory reserved)...');
        $this->purchaseReset->resetDerivedColumns();

        if (app()->isProduction()) {
            // Production re-seeds through ProductionSeeder alone: env-provided
            // admin, no demo catalogue, create-if-missing settings, the plan
            // tables and the launch flag defaults, and the reserved block with
            // fresh sign-ins (CSV under storage/app/private/reserved-credentials/).
            $log('Re-seeding production defaults and the reserved company accounts (ProductionSeeder)...');
            Artisan::call('db:seed', ['--class' => ProductionSeeder::class, '--force' => true]);
        } else {
            $log('Re-seeding platform metadata (roles, admin, settings, content, ledger, flags)...');
            $this->seedPlatformMetadata();

            $log('Building the 31 reserved distributor tree...');
            $this->buildReservedTree();
        }

        $log('Writing platform.reset audit-log entry...');
        AuditLog::create([
            'actor_id' => $this->resolveAdminUserId(),
            'action' => 'platform.reset',
            'subject_type' => 'platform',
            'subject_id' => 0,
            'details' => [
                'reserved_root_adn' => ReservedAdns::ROOT,
                'reserved_children_count' => count(ReservedAdns::CHILDREN),
                'sponsorship_rows' => count(ReservedAdns::CHILDREN),
                'note' => 'Full platform reset via php artisan platform:reset',
            ],
        ]);

        $log('Reset complete.');
    }

    /** @param Closure(string): void $log */
    private function wipeS3Files(Closure $log): void
    {
        try {
            // Best-effort: every top-level folder on the `kyc` disk (where
            // KycDocumentVault writes), not just those kyc_documents still
            // names — an abandoned registration leaves a reg_ folder with no
            // row. Session ids are mixed-case, so reg_ allows [A-Za-z0-9].
            $disk = Storage::disk('kyc');

            foreach ($disk->directories() as $prefix) {
                // Allowlist: the KYC uploader writes to `user_<id>/` or `reg_<sessionId>/`.
                // Anything else is corrupt data or an injection attempt —
                // skip rather than risk wiping unrelated bucket contents
                // (the kyc disk targets real AWS in staging/prod).
                if (! preg_match('/^(user_\d+|reg_[A-Za-z0-9]+)$/', $prefix)) {
                    $log(sprintf('  kyc: skipping non-allowlisted prefix %s', $prefix));

                    continue;
                }
                $log(sprintf('  kyc:deleteDirectory %s', $prefix));
                $disk->deleteDirectory($prefix);
            }
        } catch (Throwable $e) {
            $log('  s3 wipe skipped: '.$e->getMessage());
        }
    }

    private function truncateAll(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            foreach (self::wipeTables() as $table) {
                if ($this->db->getSchemaBuilder()->hasTable($table)) {
                    $this->db->table($table)->truncate();
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * Every Arete centre except the company default was approved from a test
     * ADC application, so it goes with its declarations; the default centre is
     * kept as-is (its address and state drive the GST tax head).
     */
    private function clearPersonReferences(): void
    {
        $testCenters = $this->db->table('arete_centers')->where('is_company_default', false)->pluck('id');
        $this->db->table('arete_center_declarations')->whereIn('center_id', $testCenters)->delete();
        $this->db->table('arete_centers')->whereIn('id', $testCenters)->delete();

        foreach (self::KEPT_TABLE_PERSON_COLUMNS as $table => $columns) {
            if ($this->db->getSchemaBuilder()->hasTable($table)) {
                $this->db->table($table)->update(array_fill_keys($columns, null));
            }
        }
    }

    private function seedPlatformMetadata(): void
    {
        // Each seeder is idempotent (firstOrCreate / updateOrCreate); they
        // are safe to run after the wipe because their target rows no
        // longer exist, so they fall into the "create" branch.
        foreach ([
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
            SettingsSeeder::class,
            ContentPageSeeder::class,
            LedgerAccountSeeder::class,
            CommerceFeatureFlagSeeder::class,
            ProductCatalogSeeder::class,
        ] as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }
    }

    private function buildReservedTree(): void
    {
        // Tables are empty post-wipe, so the unconditional fresh build is
        // safe. The block itself (users, distributors, genealogy_closure,
        // 30 sponsorship edges) is single-sourced in SeedReservedTreeAction,
        // shared with ProductionSeeder.
        $this->reservedTree->buildFresh();
    }

    private function resolveAdminUserId(): ?int
    {
        return User::query()->where('email', 'admin@arovolife.test')->value('id');
    }
}
