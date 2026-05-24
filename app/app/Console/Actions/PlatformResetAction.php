<?php

declare(strict_types=1);

namespace App\Console\Actions;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\User;
use Closure;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\CommerceFeatureFlagSeeder;
use Database\Seeders\ContentPageSeeder;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One-shot full platform reset: wipes transactional data, scrubs S3 KYC
 * files, then re-seeds the canonical bootstrap state (roles, admin,
 * settings, content pages, ledger COA, feature flags, product catalog,
 * and the 31 company-blocked reserved distributors).
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
    private const WIPE_TABLES = [
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

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @param  Closure(string): void|null  $progress  optional callback for CLI output
     */
    public function execute(?Closure $progress = null): void
    {
        $log = $progress ?? static fn (string $_m): null => null;

        $log('Cleaning S3 KYC objects...');
        $this->wipeS3Files($log);

        $log('Truncating transactional tables...');
        $this->wipeTables();

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

    private function wipeTables(): void
    {
        $this->db->connection()->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::WIPE_TABLES as $table) {
            if ($this->db->getSchemaBuilder()->hasTable($table)) {
                $this->db->table($table)->truncate();
            }
        }
        $this->db->connection()->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function seedPlatformMetadata(): void
    {
        // Each seeder is idempotent (firstOrCreate / updateOrCreate); they
        // are safe to run after the wipe because their target rows no
        // longer exist, so they fall into the "create" branch.
        foreach ([
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
        $now = now()->format('Y-m-d H:i:s.v');
        // Reserved company nodes have no cooling-off rights — they exist to
        // block tree slots, not to participate in commerce. Setting the end
        // date equal to effective_date renders the cooling-off period as
        // already-expired in the admin UI (matches operator expectation)
        // and ensures any accidental cancellation attempt is a no-op.
        $coolingOffEnd = $now;
        $adns = ReservedAdns::all(); // index 0 = root, 1..30 = level-2..level-5 in BFS

        // The distributors table has NOT NULL self-FKs on sponsor_id and
        // placement_parent_id, so the root row (which references itself)
        // cannot be inserted with placeholder values without temporarily
        // disabling FK checks. We re-enable them at the end (and on the
        // unhappy path) so any post-action queries see normal enforcement.
        $this->db->connection()->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $this->insertReservedRows($adns, $now, $coolingOffEnd);
        } finally {
            $this->db->connection()->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * @param  list<string>  $adns
     */
    private function insertReservedRows(array $adns, string $now, string $coolingOffEnd): void
    {
        // ── 1. Create 31 users + their distributor stubs. Track ids by tree index.
        $userIds = [];
        $distributorIds = [];

        for ($i = 0; $i < 31; $i++) {
            $adn = $adns[$i];
            $userId = $this->db->table('users')->insertGetId([
                'full_name' => 'Arovolife Private Limited',
                'email' => sprintf('reserved-%02d@arovolife.local', $i),
                'phone_e164' => sprintf('+9180000%05d', $i), // synthetic; not validated
                'password_hash' => Hash::make('reserved-'.bin2hex(random_bytes(16))),
                'password_set_at' => null,
                'email_verified_at' => $now,
                // Reserved nodes skip the KYC funnel that normally writes
                // `activated_at` (see ApproveKycSubmission), so set it
                // explicitly here so the dashboard "Activation Date"
                // stat reads a real date rather than `—`. Conceptually
                // the reserved tree is "activated" the moment it's
                // seeded — there's no human KYC review to defer to.
                'activated_at' => $now,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userIds[$i] = $userId;

            // Parent in the binary tree: index 0 root, parent of index n>=1 is floor((n-1)/2)
            $parentIdx = $i === 0 ? null : intdiv($i - 1, 2);
            $side = $i === 0 ? null : ((($i - 1) % 2) === 0 ? 'L' : 'R');
            $depth = self::depthOfIndex($i);

            // Compute synthetic PAN (must be unique 10-char + hash). Use ADN-derived deterministic string.
            $syntheticPan = sprintf('ARVO%07d', (int) $adn % 9_999_999); // visibly fake
            $panHash = hash('sha256', $syntheticPan, true);

            $distributorIds[$i] = $this->db->table('distributors')->insertGetId([
                'user_id' => $userId,
                'adn' => $adn,
                'pan_hash' => $panHash,
                'pan_last4' => substr($syntheticPan, -4),
                'pan_encrypted' => null,
                'aadhaar_ref' => 'RESERVED_'.$adn,
                'aadhaar_last4' => '0000',
                'aadhaar_encrypted' => null,
                'bank_account_enc' => null,
                'bank_ifsc' => null,
                'sponsor_id' => $i === 0 ? 0 : ($distributorIds[$parentIdx] ?? 0),
                'placement_id_at_registration' => $i === 0 ? null : ($distributorIds[$parentIdx] ?? null),
                'placement_parent_id' => $i === 0 ? 0 : ($distributorIds[$parentIdx] ?? 0),
                'placement_side' => $side,
                'side_chosen_by' => 'referral_explicit',
                'depth' => $depth,
                'effective_date' => $now,
                'cooling_off_end_at' => $coolingOffEnd,
                'state' => 'TG',
                'spouse_distributor_id' => null,
                'is_primary_couple' => 0,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // ── 2. Fix root self-reference: sponsor_id and placement_parent_id should
        // point at itself for the L0 row (matches DemoDownline pattern).
        $rootDistributorId = $distributorIds[0];
        $this->db->table('distributors')->where('id', $rootDistributorId)->update([
            'sponsor_id' => $rootDistributorId,
            'placement_parent_id' => $rootDistributorId,
        ]);

        // ── 3. Build genealogy_closure rows. For every distributor i, insert one
        // (self, self, 0) row; for every ancestor a of i (a != i), insert (a, i, depth-diff).
        // BFS index parent function gives the ancestor chain.
        $closureRows = [];
        for ($i = 0; $i < 31; $i++) {
            $descendantId = $distributorIds[$i];
            // self-row
            $closureRows[] = [
                'ancestor_id' => $descendantId,
                'descendant_id' => $descendantId,
                'depth' => 0,
            ];

            // Walk ancestors
            $cursor = $i;
            $hops = 0;
            while ($cursor !== 0) {
                $parentIdx = intdiv($cursor - 1, 2);
                $hops++;
                $closureRows[] = [
                    'ancestor_id' => $distributorIds[$parentIdx],
                    'descendant_id' => $descendantId,
                    'depth' => $hops,
                ];
                $cursor = $parentIdx;
            }
        }
        // Bulk insert in chunks to keep query size sane
        foreach (array_chunk($closureRows, 500) as $chunk) {
            $this->db->table('genealogy_closure')->insert($chunk);
        }
    }

    /** BFS index → depth in a complete binary tree rooted at index 0. */
    private static function depthOfIndex(int $i): int
    {
        // 0 → 0; 1,2 → 1; 3..6 → 2; 7..14 → 3; 15..30 → 4
        return (int) floor(log($i + 1, 2));
    }

    private function resolveAdminUserId(): ?int
    {
        return User::query()->where('email', 'admin@arovolife.test')->value('id');
    }
}
