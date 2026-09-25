<?php

declare(strict_types=1);

namespace App\Console\Actions;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Genealogy\Support\ReservedAdns;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use ZxcvbnPhp\Zxcvbn;

/**
 * Builds the company-blocked reserved distributors listed in
 * {@see ReservedAdns} (63 today: 1 root + 2 + 4 + 8 + 16 + 32 across tree
 * levels 0-5) together with their users, the genealogy_closure rows and one
 * sponsorship edge per child — each child is sponsored by its direct
 * binary-tree parent; the root gets NO edge (a self-edge would make the
 * company root its own direct referral).
 *
 * Shared by two callers:
 *
 *  - {@see PlatformResetAction} rebuilds the block after a full wipe
 *    (tables are empty, so {@see buildFresh()} runs unconditionally);
 *  - ProductionSeeder bootstraps a fresh install with the same block, heals
 *    environments seeded before the sponsorship edges existed via
 *    {@see backfillSponsorship()} (R-66), grows an existing block when a
 *    level is added to ReservedAdns via {@see extendMissingNodes()}, and
 *    hands never-activated accounts their sign-in details via
 *    {@see issueCredentials()}.
 */
final class SeedReservedTreeAction
{
    public function __construct(private readonly DatabaseManager $db) {}

    /** True when any of the reserved ADNs already has a distributor row. */
    public function reservedRowsExist(): bool
    {
        return $this->db->table('distributors')
            ->whereIn('adn', ReservedAdns::all())
            ->exists();
    }

    /**
     * Insert the full reserved block. Caller must guarantee the block
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
        $adns = ReservedAdns::all(); // index 0 = root, then the children in BFS order

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
     * Grow an existing reserved block to the full {@see ReservedAdns} list —
     * the path a new level takes on an environment that already has the
     * block. Walks the list in BFS order so every parent exists before its
     * children, and only ever inserts: a node whose tree slot is already held
     * by an organic distributor (or whose parent could not be placed) is
     * reported in `blocked` and left alone. Moving real people out of the way
     * is a line-change decision, never a seeder side effect.
     *
     * @return array{added: int, blocked: list<string>}
     */
    public function extendMissingNodes(): array
    {
        $adns = ReservedAdns::all();
        $now = now()->format('Y-m-d H:i:s.v');
        $added = 0;
        $blocked = [];

        $this->db->transaction(function () use ($adns, $now, &$added, &$blocked): void {
            /** @var array<string, int> $ids */
            $ids = $this->db->table('distributors')
                ->whereIn('adn', $adns)
                ->lockForUpdate()
                ->pluck('id', 'adn')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if (! isset($ids[ReservedAdns::ROOT])) {
                return;
            }

            for ($i = 1, $n = count($adns); $i < $n; $i++) {
                $adn = $adns[$i];
                if (isset($ids[$adn])) {
                    continue;
                }

                $parentId = $ids[$adns[intdiv($i - 1, 2)]] ?? null;
                $side = (($i - 1) % 2) === 0 ? 'L' : 'R';

                $slotTaken = $parentId === null || $this->db->table('distributors')
                    ->where('placement_parent_id', $parentId)
                    ->where('placement_side', $side)
                    ->where('id', '!=', $parentId)
                    ->exists();

                if ($slotTaken) {
                    $blocked[] = $adn;

                    continue;
                }

                $id = $this->insertNode($i, $adn, $parentId, $now);

                $this->db->table('sponsorship')->insert([
                    'sponsor_id' => $parentId,
                    'distributor_id' => $id,
                    'created_at' => $now,
                ]);

                // The new leaf's ancestors are its parent's ancestors, one
                // hop further, plus the parent itself (the parent's self-row).
                $closure = $this->db->table('genealogy_closure')
                    ->where('descendant_id', $parentId)
                    ->get(['ancestor_id', 'depth'])
                    ->map(static fn (object $row): array => [
                        'ancestor_id' => (int) $row->ancestor_id,
                        'descendant_id' => $id,
                        'depth' => (int) $row->depth + 1,
                    ])
                    ->push(['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0])
                    ->all();
                $this->db->table('genealogy_closure')->insert($closure);

                $ids[$adn] = $id;
                $added++;
            }
        });

        return ['added' => $added, 'blocked' => $blocked];
    }

    /**
     * Give every reserved account that has never had a password a sign-in:
     * a unique plus-address on `$emailBase` (`name+<ADN>@domain`, so every
     * account still delivers to the one inbox) and a random password. An
     * account whose password has already been set is never touched, so
     * re-running issues nothing. Each issue is audit-logged; the password
     * itself never reaches the audit row, a log or the console — it is only
     * in the returned list, which the caller must store privately.
     *
     * @return array{issued: list<array{adn: string, email: string, password: string}>, skipped: list<string>}
     */
    public function issueCredentials(string $emailBase): array
    {
        if (filter_var($emailBase, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Reserved-account email base is not a valid email address.');
        }

        [$local, $domain] = explode('@', $emailBase, 2);
        $local = explode('+', $local, 2)[0];
        $order = array_flip(ReservedAdns::all());
        $issued = [];
        $skipped = [];

        $this->db->transaction(function () use ($local, $domain, $order, &$issued, &$skipped): void {
            $accounts = $this->db->table('distributors as d')
                ->join('users as u', 'u.id', '=', 'd.user_id')
                ->whereIn('d.adn', ReservedAdns::all())
                ->whereNull('u.password_set_at')
                ->lockForUpdate()
                ->get(['d.adn', 'u.id as user_id', 'u.email'])
                ->sortBy(static fn (object $row): int => $order[$row->adn]);

            foreach ($accounts as $account) {
                $email = "{$local}+{$account->adn}@{$domain}";

                $clash = $this->db->table('users')
                    ->where('email', $email)
                    ->where('id', '!=', $account->user_id)
                    ->exists();
                if ($clash) {
                    $skipped[] = (string) $account->adn;

                    continue;
                }

                $password = self::randomPassword();
                $stamp = now();

                $this->db->table('users')->where('id', $account->user_id)->update([
                    'email' => $email,
                    'email_verified_at' => $stamp,
                    'password_hash' => Hash::make($password),
                    'password_set_at' => $stamp,
                    'login_throttle_cleared_at' => $stamp,
                    'updated_at' => $stamp,
                ]);

                $this->db->table('password_reset_tokens')
                    ->whereIn('email', [(string) $account->email, $email])
                    ->delete();

                AuditLog::create([
                    'actor_id' => null,
                    'action' => 'reserved.credentials_issued',
                    'subject_type' => 'user',
                    'subject_id' => (int) $account->user_id,
                    'before_hash' => AuditDigests::of(['email' => (string) $account->email, 'password_set_at' => '']),
                    'after_hash' => AuditDigests::of(['email' => $email, 'password_set_at' => (string) $stamp]),
                    'details' => ['adn' => (string) $account->adn, 'from' => (string) $account->email, 'to' => $email, 'method' => 'production_seeder'],
                    'ip' => null,
                ]);

                $issued[] = ['adn' => (string) $account->adn, 'email' => $email, 'password' => $password];
            }
        });

        return ['issued' => $issued, 'skipped' => $skipped];
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
        // ── 1. Create the users + their distributor stubs. Track ids by tree index.
        $distributorIds = [];
        $count = count($adns);

        for ($i = 0; $i < $count; $i++) {
            $parentId = $i === 0 ? null : $distributorIds[intdiv($i - 1, 2)];
            $distributorIds[$i] = $this->insertNode($i, $adns[$i], $parentId, $now, $coolingOffEnd);
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
        for ($i = 1; $i < $count; $i++) {
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
        for ($i = 0; $i < $count; $i++) {
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

    /**
     * One reserved user + distributor stub at BFS index `$i`. The root
     * (`$parentId` null) gets placeholder parent ids; the caller stamps its
     * self-references afterwards.
     */
    private function insertNode(int $i, string $adn, ?int $parentId, string $now, ?string $coolingOffEnd = null): int
    {
        // Reserved company nodes have no cooling-off rights (see buildFresh()).
        $coolingOffEnd ??= $now;

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
        $side = $i === 0 ? null : ((($i - 1) % 2) === 0 ? 'L' : 'R');
        $depth = self::depthOfIndex($i);

        // Compute synthetic PAN (must be unique 10-char + hash). Use ADN-derived deterministic string.
        $syntheticPan = sprintf('ARVO%07d', (int) $adn % 9_999_999); // visibly fake
        $panHash = hash('sha256', $syntheticPan, true);

        return $this->db->table('distributors')->insertGetId([
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
            'sponsor_id' => $parentId ?? 0,
            'placement_id_at_registration' => $parentId,
            'placement_parent_id' => $parentId ?? 0,
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

    /** 16 characters from four classes, re-drawn until zxcvbn scores it 3+. */
    private static function randomPassword(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '@#%&*!?'];
        $all = implode('', $sets);
        $zxcvbn = new Zxcvbn;

        do {
            $chars = array_map(static fn (string $set): string => $set[random_int(0, strlen($set) - 1)], $sets);
            while (count($chars) < 16) {
                $chars[] = $all[random_int(0, strlen($all) - 1)];
            }
            for ($k = count($chars) - 1; $k > 0; $k--) {
                $j = random_int(0, $k);
                [$chars[$k], $chars[$j]] = [$chars[$j], $chars[$k]];
            }
            $password = implode('', $chars);
        } while ((int) ($zxcvbn->passwordStrength($password)['score'] ?? 0) < 3);

        return $password;
    }

    /** BFS index → depth in a complete binary tree rooted at index 0. */
    private static function depthOfIndex(int $i): int
    {
        // 0 → 0; 1,2 → 1; 3..6 → 2; 7..14 → 3; 15..30 → 4; 31..62 → 5
        return (int) floor(log($i + 1, 2));
    }
}
