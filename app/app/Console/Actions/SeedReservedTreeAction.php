<?php

declare(strict_types=1);

namespace App\Console\Actions;

use App\Modules\Genealogy\Support\ReservedAdns;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the 31 company-blocked reserved distributors (1 root + 2 + 4 +
 * 8 + 16 across tree levels 0-4) together with their users, the
 * genealogy_closure rows and the 30 sponsorship edges — each child is
 * sponsored by its direct binary-tree parent; the root gets NO edge (a
 * self-edge would make the company root its own direct referral).
 *
 * Shared by two callers:
 *
 *  - {@see PlatformResetAction} rebuilds the block after a full wipe
 *    (tables are empty, so {@see buildFresh()} runs unconditionally);
 *  - ProductionSeeder bootstraps a fresh install with the same block and,
 *    on environments seeded before the sponsorship edges existed, heals
 *    them idempotently via {@see backfillSponsorship()} (R-66).
 */
final class SeedReservedTreeAction
{
    public function __construct(private readonly DatabaseManager $db) {}

    /** True when any of the 31 reserved ADNs already has a distributor row. */
    public function reservedRowsExist(): bool
    {
        return $this->db->table('distributors')
            ->whereIn('adn', ReservedAdns::all())
            ->exists();
    }

    /**
     * Insert the full 31-account block. Caller must guarantee the block
     * does not already exist (fresh install or post-wipe) — this method
     * does blind inserts by design so a violated precondition surfaces as
     * a unique-constraint error rather than silent duplication.
     */
    public function buildFresh(): void
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
        // cannot be inserted with placeholder values while FKs are
        // enforced immediately.
        $this->withRelaxedForeignKeys(function () use ($adns, $now, $coolingOffEnd): void {
            $this->insertReservedRows($adns, $now, $coolingOffEnd);
        });
    }

    /**
     * Idempotent healer for environments whose reserved block predates the
     * sponsorship fix (2026-08-31): inserts the missing horizontal edges
     * (sponsor = direct binary parent) for reserved non-root rows that have
     * none, and never touches existing rows. Returns the number inserted —
     * 0 on every environment that is already correct.
     */
    public function backfillSponsorship(): int
    {
        $missing = $this->db->table('distributors')
            ->whereIn('adn', ReservedAdns::all())
            ->where('depth', '>', 0)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('sponsorship')
                    ->whereColumn('sponsorship.distributor_id', 'distributors.id');
            })
            ->get(['id', 'placement_parent_id', 'created_at']);

        if ($missing->isEmpty()) {
            return 0;
        }

        $rows = $missing->map(static fn (object $d): array => [
            'sponsor_id' => (int) $d->placement_parent_id,
            'distributor_id' => (int) $d->id,
            'created_at' => $d->created_at,
        ])->all();

        $this->db->table('sponsorship')->insert($rows);

        return count($rows);
    }

    /**
     * Driver-aware FK relaxation. MySQL flips the session switch; SQLite
     * (tests) ignores `PRAGMA foreign_keys` inside an open transaction, so
     * we defer FK validation to COMMIT instead — by which time the root's
     * self-references have been stamped and every constraint is satisfied.
     * The SQLite flag auto-resets at transaction end.
     */
    private function withRelaxedForeignKeys(Closure $callback): void
    {
        if ($this->db->getDriverName() === 'sqlite') {
            $this->db->statement('PRAGMA defer_foreign_keys = ON');
            $callback();

            return;
        }

        Schema::disableForeignKeyConstraints();

        try {
            $callback();
        } finally {
            Schema::enableForeignKeyConstraints();
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

        // ── 2.5. Sponsorship rows (horizontal tree). The reserved accounts are
        // permanent company accounts, so they need the same sponsorship edges
        // PlacementEngine writes for organic joiners: each node's sponsor is
        // its direct parent in the binary tree. Without these rows anything
        // that reads `sponsorship` (MSB accrual, direct-referral lists, team
        // stats) treats them as sponsorless. The root gets no row — a
        // self-edge would make the company root its own direct referral.
        $sponsorshipRows = [];
        for ($i = 1; $i < 31; $i++) {
            $sponsorshipRows[] = [
                'sponsor_id' => $distributorIds[intdiv($i - 1, 2)],
                'distributor_id' => $distributorIds[$i],
                'created_at' => $now,
            ];
        }
        $this->db->table('sponsorship')->insert($sponsorshipRows);

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
}
