<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Events\DistributorRegistered;
use App\Modules\Genealogy\Events\ForbiddenPlacementAttempted;
use App\Modules\Genealogy\Events\PlacementCreated;
use App\Modules\Genealogy\Services\DTOs\PlaceDistributorInput;
use App\Modules\Genealogy\Services\DTOs\PlacementResult;
use App\Modules\Genealogy\Services\Exceptions\CrossLinePlacementError;
use App\Modules\Genealogy\Services\Exceptions\PlacementSlotFullError;
use App\Modules\Genealogy\Services\Exceptions\PlacementSlotsExhaustedError;
use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Services\TeamStatsService;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * ADR-0003 / ADR-0007 — referral-link placement.
 *
 * Default (ADR-0003, single-level): the new registrant is placed *exactly* at
 * `placement_id.<side>`; if that slot is full the registration is rejected and
 * the wizard surfaces the `placement_full` Contact Us redirect.
 *
 * When the admin setting `placement.spillover.enabled` is on (ADR-0007), a full
 * target instead spills the joiner into the next open slot below it (directed
 * BFS within the chosen leg, or balanced BFS when no side is given). The actual
 * parent may then be a descendant of `placement_id`; the intended target is
 * always preserved in `placement_id_at_registration`.
 */
final class PlacementEngine
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
        private readonly TeamStatsService $teamStats,
    ) {}

    public function place(PlaceDistributorInput $in): PlacementResult
    {
        // 1. Cross-line guard.
        if (! $this->isSelfOrDescendant($in->sponsorId, $in->placementId)) {
            $this->events->dispatch(new ForbiddenPlacementAttempted($in, $in->placementId));
            AuditLog::create([
                'actor_id' => null,
                'action' => 'genealogy.placement.rejected',
                'subject_type' => 'distributor',
                'subject_id' => $in->sponsorId,
                'details' => [
                    'reason' => 'cross_line',
                    'sponsor_id' => $in->sponsorId,
                    'placement_id' => $in->placementId,
                ],
            ]);
            throw new CrossLinePlacementError(
                "placement_id={$in->placementId} is not in the downline of sponsor_id={$in->sponsorId}"
            );
        }

        return $this->db->transaction(function () use ($in): PlacementResult {
            // 2. Per-placement_id advisory lock — prevents two registrations
            //    targeting the same placement_id from racing into the same slot.
            //    GET_LOCK returns 1 on success, 0 on timeout, NULL on error.
            //    If we cannot acquire the lock we MUST NOT proceed — the
            //    unique index on (placement_parent_id, placement_side) is the
            //    last-line defence, but reading state without the lock could
            //    let two concurrent transactions both pass resolveSlot() and
            //    only one wins at insert time, so we'd surface the second's
            //    failure as an opaque DB error rather than the dedicated
            //    PlacementSlotsExhaustedError.
            // SQLite (test driver) does not implement GET_LOCK / RELEASE_LOCK.
            // The test suite is single-threaded, the :memory: PDO is
            // exclusive to the process, and the unique index on
            // (placement_parent_id, placement_side) is still the
            // last-line defence — so we safely skip the advisory lock
            // off MySQL.
            if ($this->db->connection()->getDriverName() === 'mysql') {
                $acquired = $this->db->selectOne(
                    'SELECT GET_LOCK(?, 5) AS got',
                    ["placement:{$in->placementId}"]
                );
                if ((int) ($acquired->got ?? 0) !== 1) {
                    throw new PlacementSlotsExhaustedError($in->placementId);
                }
            }

            try {
                // ADR-0007: when the admin setting `placement.spillover.enabled`
                // is on, a full target spills the joiner into the next open slot
                // below it; otherwise placement is single-level (ADR-0003). The
                // actual parent (`$parentId`) may therefore be a descendant of
                // the link's `placement_id`. The intended target is preserved in
                // `placement_id_at_registration` regardless.
                $now = now();
                $spillover = $this->spilloverEnabled();
                [$distributorId, $parentId, $side, $chosenBy, $depth] =
                    $this->resolveAndInsert($in, $spillover, $now);

                $this->writeClosureRows($distributorId, $parentId);

                $this->db->table('sponsorship')->insert([
                    'sponsor_id' => $in->sponsorId,
                    'distributor_id' => $distributorId,
                    'created_at' => $now->format('Y-m-d H:i:s.v'),
                ]);

                AuditLog::create([
                    'actor_id' => null,
                    'action' => 'genealogy.placement.created',
                    'subject_type' => 'distributor',
                    'subject_id' => $distributorId,
                    'details' => [
                        'distributor_id' => $distributorId,
                        'sponsor_id' => $in->sponsorId,
                        'placement_id' => $in->placementId,   // intended target
                        'parent_id' => $parentId,             // actual parent (may differ under spillover)
                        'side' => $side,
                        'depth' => $depth,
                        'side_chosen_by' => $chosenBy,
                        'strategy' => $spillover ? $this->spilloverStrategy() : null,
                    ],
                ]);

                $result = new PlacementResult(
                    distributorId: $distributorId,
                    userId: $in->userId,
                    parentId: $parentId,
                    side: $side,
                    depth: $depth,
                    sideChosenBy: $chosenBy,
                );

                $this->events->dispatch(new PlacementCreated($result, $in->sponsorId, $in->placementId));
                $this->events->dispatch(new DistributorRegistered($distributorId, $in->sponsorId));

                return $result;
            } finally {
                if ($this->db->connection()->getDriverName() === 'mysql') {
                    $this->db->statement('SELECT RELEASE_LOCK(?)', ["placement:{$in->placementId}"]);
                }
            }
        });
    }

    /**
     * Returns `[side, chosenBy]`. Throws if the requested slot (or both
     * slots when no side was specified) is full.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveSlot(int $placementId, ?string $sideOpt): array
    {
        $taken = $this->db->table('distributors')
            ->where('placement_parent_id', $placementId)
            ->where('id', '!=', $placementId)            // exclude root self-reference
            ->whereIn('placement_side', ['L', 'R'])
            ->pluck('placement_side')
            ->all();

        $lTaken = in_array('L', $taken, true);
        $rTaken = in_array('R', $taken, true);

        if ($sideOpt !== null) {
            $slotTaken = ($sideOpt === 'L') ? $lTaken : $rTaken;
            if ($slotTaken) {
                throw new PlacementSlotFullError($sideOpt, $placementId);
            }

            return [$sideOpt, 'referral_explicit'];
        }

        if (! $lTaken) {
            return ['L', 'referral_default'];
        }

        if (! $rTaken) {
            return ['R', 'referral_fallback_right'];
        }

        throw new PlacementSlotsExhaustedError($placementId);
    }

    /**
     * Resolve the slot (single-level, or with spillover when enabled) and
     * insert the distributor row. Under spillover a unique-slot collision from
     * a concurrent registration (an overlapping-subtree race the per-target
     * advisory lock can't cover) re-runs the BFS to the next open slot.
     *
     * @return array{0: int, 1: int, 2: string, 3: string, 4: int}
     *                                                             [distributorId, parentId, side, chosenBy, depth]
     */
    private function resolveAndInsert(PlaceDistributorInput $in, bool $spillover, Carbon $now): array
    {
        $maxAttempts = $spillover ? 3 : 1;

        for ($attempt = 1; ; $attempt++) {
            if ($spillover) {
                [$parentId, $side, $chosenBy] = $this->resolveSlotWithSpillover($in->placementId, $in->sideOpt);
            } else {
                [$side, $chosenBy] = $this->resolveSlot($in->placementId, $in->sideOpt);
                $parentId = $in->placementId;
            }

            $parentDepth = $this->db->table('distributors')->where('id', $parentId)->value('depth');
            if ($parentDepth === null) {
                // Cross-line guard already proved placement_id is in the
                // sponsor's downline (or is the sponsor), and a spillover parent
                // is always a real descendant of it — so this is unreachable from
                // the wizard. Kept safe for direct service / fixture use.
                throw new PlacementSlotsExhaustedError($in->placementId);
            }
            $depth = (int) $parentDepth + 1;

            try {
                $distributorId = $this->db->table('distributors')->insertGetId([
                    'user_id' => $in->userId,
                    'adn' => $this->generateAdn(),
                    'pan_hash' => $in->panHash,
                    'pan_last4' => $in->panLast4,
                    'pan_encrypted' => $in->panEncrypted,
                    'aadhaar_ref' => $in->aadhaarRef,
                    'aadhaar_last4' => $in->aadhaarLast4,
                    'aadhaar_encrypted' => $in->aadhaarEncrypted,
                    'bank_account_enc' => $in->bankAccountEnc,
                    'bank_ifsc' => $in->bankIfsc,
                    'sponsor_id' => $in->sponsorId,
                    'placement_id_at_registration' => $in->placementId,
                    'placement_parent_id' => $parentId,
                    'placement_side' => $side,
                    'side_chosen_by' => $chosenBy,
                    'depth' => $depth,
                    'effective_date' => $now->format('Y-m-d H:i:s.v'),
                    'cooling_off_end_at' => $now->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
                    'state' => $in->state,
                    'is_primary_couple' => (int) $in->isPrimaryCouple,
                    'created_at' => $now->format('Y-m-d H:i:s.v'),
                    'updated_at' => $now->format('Y-m-d H:i:s.v'),
                ]);

                return [$distributorId, $parentId, $side, $chosenBy, $depth];
            } catch (QueryException $e) {
                // 23000 = integrity constraint violation (the unique
                // (placement_parent_id, placement_side) slot, or an ADN clash).
                // Retry only under spillover, where re-running the BFS finds the
                // next open slot. Off spillover, surface the error unchanged.
                if ($spillover && $attempt < $maxAttempts && (string) $e->getCode() === '23000') {
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * ADR-0007 spillover slot resolution — dispatches to the admin-selected fill
     * strategy (`placement.spillover.strategy`). All strategies direct into the
     * chosen leg when a side is given (or operate across both legs when none is).
     * A binary tree is never full, so each always returns; the returned
     * `parentId` may be a descendant of `$targetId` (a genuine spillover).
     *
     * @return array{0: int, 1: string, 2: string} [parentId, side, chosenBy]
     */
    private function resolveSlotWithSpillover(int $targetId, ?string $sideOpt): array
    {
        return match ($this->spilloverStrategy()) {
            'depth_outer' => $this->resolveDepthOuter($targetId, $sideOpt),
            'weaker_leg' => $this->resolveWeakerLeg($targetId, $sideOpt),
            default => $this->resolveBreadthBalanced($targetId, $sideOpt),
        };
    }

    /**
     * `breadth_balanced` (default): breadth-first search for the SHALLOWEST open
     * slot — within the chosen child's subtree (side given) or across both legs
     * (no side). Fills the leg level-by-level.
     *
     * @return array{0: int, 1: string, 2: string} [parentId, side, chosenBy]
     */
    private function resolveBreadthBalanced(int $targetId, ?string $sideOpt): array
    {
        // Frontier of [nodeId, sidesToConsider]. At the target we only enter the
        // requested side (or both when none is given); one level down we always
        // consider both L and R so a chosen leg fills breadth-first.
        $queue = [[$targetId, $sideOpt !== null ? [$sideOpt] : ['L', 'R']]];

        while ($queue !== []) {
            /** @var array{0: int, 1: list<string>} $head */
            $head = array_shift($queue);
            [$nodeId, $sides] = $head;
            $children = $this->childrenBySide($nodeId);

            foreach ($sides as $side) {
                if ($children[$side] === null) {
                    return [$nodeId, $side, $this->spilloverChosenBy($nodeId === $targetId, $sideOpt, $side)];
                }
            }

            foreach ($sides as $side) {
                $queue[] = [(int) $children[$side], ['L', 'R']];
            }
        }

        throw new PlacementSlotsExhaustedError($targetId);
    }

    /**
     * `depth_outer`: ride a single monotone edge down the chosen side
     * (side=L → L→L→…, side=R → R→R→…, no side → outer-left) to the first open
     * slot on that edge. Builds one deep outer leg.
     *
     * @return array{0: int, 1: string, 2: string} [parentId, side, chosenBy]
     */
    private function resolveDepthOuter(int $targetId, ?string $sideOpt): array
    {
        $edgeSide = $sideOpt ?? 'L';
        $nodeId = $targetId;

        while (true) {
            $children = $this->childrenBySide($nodeId);
            if ($children[$edgeSide] === null) {
                return [$nodeId, $edgeSide, $this->spilloverChosenBy($nodeId === $targetId, $sideOpt, $edgeSide)];
            }
            $nodeId = (int) $children[$edgeSide];
        }
    }

    /**
     * `weaker_leg`: enter the chosen leg, then at each fully-occupied node
     * descend into the sub-leg with FEWER members until an open slot. Balances
     * the leg by headcount.
     *
     * @return array{0: int, 1: string, 2: string} [parentId, side, chosenBy]
     */
    private function resolveWeakerLeg(int $targetId, ?string $sideOpt): array
    {
        $nodeId = $targetId;
        $sides = $sideOpt !== null ? [$sideOpt] : ['L', 'R'];

        while (true) {
            $children = $this->childrenBySide($nodeId);

            // Open slot among the considered sides → take it (L-first tie-break).
            foreach ($sides as $side) {
                if ($children[$side] === null) {
                    return [$nodeId, $side, $this->spilloverChosenBy($nodeId === $targetId, $sideOpt, $side)];
                }
            }

            // Fully occupied → descend into the lighter sub-leg; both sub-legs
            // are in play from here on.
            $nodeId = $this->lighterChild($nodeId, $sides, $children);
            $sides = ['L', 'R'];
        }
    }

    /**
     * The child id under `$nodeId` whose subtree currently has the fewest
     * members. When only one side is in play (directed entry at the target),
     * that side's child is returned. Subtree size comes from the single-source
     * {@see TeamStatsService} (see the team-stats-single-source rule).
     *
     * @param  list<string>  $sides
     * @param  array{L: int|null, R: int|null}  $children
     */
    private function lighterChild(int $nodeId, array $sides, array $children): int
    {
        if (count($sides) === 1) {
            return (int) $children[$sides[0]];
        }

        $node = Distributor::findOrFail($nodeId);
        $left = $this->teamStats->scopedCount($node, 'left');
        $right = $this->teamStats->scopedCount($node, 'right');

        return $left <= $right ? (int) $children['L'] : (int) $children['R'];
    }

    /**
     * The placement children of a node, keyed by side.
     *
     * @return array{L: int|null, R: int|null}
     */
    private function childrenBySide(int $nodeId): array
    {
        $rows = $this->db->table('distributors')
            ->where('placement_parent_id', $nodeId)
            ->where('id', '!=', $nodeId)            // exclude root self-reference
            ->whereIn('placement_side', ['L', 'R'])
            ->pluck('id', 'placement_side');

        return [
            'L' => isset($rows['L']) ? (int) $rows['L'] : null,
            'R' => isset($rows['R']) ? (int) $rows['R'] : null,
        ];
    }

    /**
     * `side_chosen_by` for a spillover-mode placement. When the joiner lands at
     * the immediate target slot (no descent) it is recorded with the same
     * referral_* value as single-level placement; an actual spillover gets a
     * spillover_* value so the audit distinguishes the two.
     */
    private function spilloverChosenBy(bool $atTarget, ?string $sideOpt, string $resolvedSide): string
    {
        if ($atTarget) {
            if ($sideOpt !== null) {
                return 'referral_explicit';
            }

            return $resolvedSide === 'L' ? 'referral_default' : 'referral_fallback_right';
        }

        return match ($sideOpt) {
            'L' => 'spillover_left',
            'R' => 'spillover_right',
            default => 'spillover_balanced',
        };
    }

    private function spilloverEnabled(): bool
    {
        return $this->db->table('settings')
            ->where('key', 'placement.spillover.enabled')
            ->value('value') === 'true';
    }

    /**
     * The selected spillover fill strategy. Falls back to `breadth_balanced`
     * for an absent or unrecognised value (so the default + the enum guard both
     * resolve to the safe default).
     */
    private function spilloverStrategy(): string
    {
        $v = $this->db->table('settings')
            ->where('key', 'placement.spillover.strategy')
            ->value('value');

        return is_string($v) && in_array($v, ['breadth_balanced', 'depth_outer', 'weaker_leg'], true)
            ? $v
            : 'breadth_balanced';
    }

    public function isSelfOrDescendant(int $ancestorId, int $candidateId): bool
    {
        if ($ancestorId === $candidateId) {
            return true;
        }

        return $this->db->table('genealogy_closure')
            ->where('ancestor_id', $ancestorId)
            ->where('descendant_id', $candidateId)
            ->where('depth', '>=', 1)
            ->exists();
    }

    /**
     * Returns true if at least one of `placement_id.L` / `placement_id.R` is
     * open (or, when `$side` is supplied, that specific slot is open). Used
     * by the wizard's `start()` to reject referral links pre-flight.
     */
    public function hasOpenSlot(int $placementId, ?string $side = null): bool
    {
        $taken = $this->db->table('distributors')
            ->where('placement_parent_id', $placementId)
            ->where('id', '!=', $placementId)
            ->whereIn('placement_side', ['L', 'R'])
            ->pluck('placement_side')
            ->all();

        if ($side === 'L') {
            return ! in_array('L', $taken, true);
        }
        if ($side === 'R') {
            return ! in_array('R', $taken, true);
        }

        return ! (in_array('L', $taken, true) && in_array('R', $taken, true));
    }

    private function writeClosureRows(int $distributorId, int $parentId): void
    {
        $rows = [];

        $rows[] = [
            'ancestor_id' => $distributorId,
            'descendant_id' => $distributorId,
            'depth' => 0,
        ];

        $ancestors = $this->db->table('genealogy_closure')
            ->where('descendant_id', $parentId)
            ->select(['ancestor_id', 'depth'])
            ->get();

        foreach ($ancestors as $ancestor) {
            $rows[] = [
                'ancestor_id' => $ancestor->ancestor_id,
                'descendant_id' => $distributorId,
                'depth' => $ancestor->depth + 1,
            ];
        }

        $this->db->table('genealogy_closure')->insert($rows);
    }

    /**
     * Generate a distributor ADN — random 9-digit integer in
     * (100000000, 999999999]. The 31 ADNs in {@see ReservedAdns::all()}
     * (root `444555666` + 30 company-blocked nodes seeded by `platform:reset`)
     * are permanently reserved and never re-issued to organic distributors.
     *
     * Random allocation (rather than monotonic) prevents enumeration of
     * the user base via sequential URL probing. Collision probability for
     * any single pick is well under 1-in-1M once the table fills with
     * even hundreds of thousands of rows; the retry loop, the in-memory
     * reserved-ADN skip, and the `uniq_distributors_adn` unique index
     * together guarantee uniqueness.
     */
    private function generateAdn(): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            // 100000001..999999999 inclusive — root 444555666 (and 30 fixed
            // children) are filtered out below by ReservedAdns::isReserved().
            $candidate = (string) random_int(100_000_001, 999_999_999);

            if (ReservedAdns::isReserved($candidate)) {
                continue;
            }

            if (! $this->db->table('distributors')->where('adn', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            'Could not allocate a unique ADN after 8 attempts — investigate concurrent inserts.'
        );
    }
}
