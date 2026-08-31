<?php

declare(strict_types=1);

namespace App\Console\Actions;

use App\Modules\Compensation\Support\DerivedTables;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\User;
use Closure;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\CommerceFeatureFlagSeeder;
use Database\Seeders\ContentPageSeeder;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\ProductCatalogSeeder;
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
        $log = $progress ?? static fn (string $_m): null => null;

        $log('Cleaning S3 KYC objects...');
        $this->wipeS3Files($log);

        $log('Truncating transactional tables...');
        $this->truncateAll();

        $log('Resetting derived counters (coupons.used_count, inventory reserved)...');
        $this->purchaseReset->resetDerivedColumns();

        $log('Re-seeding platform metadata (roles, admin, settings, content, ledger, flags)...');
        $this->seedPlatformMetadata();

        $log('Building the 31 reserved distributor tree...');
        $this->buildReservedTree();

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
            // Best-effort: enumerate every kyc_documents.object_storage_key
            // BEFORE the table is dropped, derive distinct user_<id>/ prefixes,
            // then deleteDirectory each.
            if (! $this->db->getSchemaBuilder()->hasTable('kyc_documents')) {
                return;
            }
            $keys = $this->db->table('kyc_documents')->pluck('object_storage_key');
            $prefixes = $keys->map(static function ($key): ?string {
                if (! is_string($key) || $key === '') {
                    return null;
                }
                $slash = strpos($key, '/');

                return $slash === false ? null : substr($key, 0, $slash);
            })->filter()->unique()->values();

            foreach ($prefixes as $prefix) {
                // Allowlist: the KYC uploader writes to `user_<id>/` or `reg_<sessionId>/`.
                // Anything else is corrupt data or an injection attempt —
                // skip rather than risk wiping unrelated bucket contents
                // (the s3 disk targets real AWS in staging/prod).
                if (! is_string($prefix) || ! preg_match('/^(user_\d+|reg_[a-f0-9]+)$/', $prefix)) {
                    $log(sprintf('  s3: skipping non-allowlisted prefix %s', (string) $prefix));

                    continue;
                }
                $log(sprintf('  s3:deleteDirectory %s', $prefix));
                Storage::disk('s3')->deleteDirectory($prefix);
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
