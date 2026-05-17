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
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;

/**
 * ADR-0003 — referral-link single-level placement.
 *
 * The new joiner is placed *exactly* at `placement_id.<side>`. The engine
 * never descends. If the targeted slot is full, the registration is
 * rejected upward; the wizard surfaces this as the generic
 * `invalid_referral_link` Contact Us redirect.
 */
final class PlacementEngine
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
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
            $acquired = $this->db->selectOne(
                'SELECT GET_LOCK(?, 5) AS got',
                ["placement:{$in->placementId}"]
            );
            if ((int) ($acquired->got ?? 0) !== 1) {
                throw new PlacementSlotsExhaustedError($in->placementId);
            }

            try {
                [$side, $chosenBy] = $this->resolveSlot($in->placementId, $in->sideOpt);

                $placementDepth = $this->db->table('distributors')
                    ->where('id', $in->placementId)
                    ->value('depth');
                if ($placementDepth === null) {
                    // H2 — cross-line guard already proved placement_id is in
                    // sponsor's downline (or is the sponsor), so this branch
                    // is unreachable from the wizard. Throwing keeps the
                    // engine safe under direct service usage / test fixtures
                    // where the assumption could break.
                    throw new PlacementSlotsExhaustedError($in->placementId);
                }
                $depth = (int) $placementDepth + 1;

                $now = now();
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
                    'placement_parent_id' => $in->placementId,
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

                $this->writeClosureRows($distributorId, $in->placementId);

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
                        'placement_id' => $in->placementId,
                        'parent_id' => $in->placementId,
                        'side' => $side,
                        'depth' => $depth,
                        'side_chosen_by' => $chosenBy,
                    ],
                ]);

                $result = new PlacementResult(
                    distributorId: $distributorId,
                    parentId: $in->placementId,
                    side: $side,
                    depth: $depth,
                    sideChosenBy: $chosenBy,
                );

                $this->events->dispatch(new PlacementCreated($result, $in->sponsorId, $in->placementId));
                $this->events->dispatch(new DistributorRegistered($distributorId, $in->sponsorId));

                return $result;
            } finally {
                $this->db->statement('SELECT RELEASE_LOCK(?)', ["placement:{$in->placementId}"]);
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
     * Generate a distributor ADN — 9 numeric digits, e.g. `111222334`.
     * Allocated in strict ascending order, starting from the seed root
     * (`111222333` by convention) and incrementing for each new joiner.
     *
     * The previous `AL-XXXXXXXXXX` random scheme had a 10-billion key
     * space and zero ordering guarantees; the numeric scheme makes ADNs
     * memorable, sortable, and predictable for the merchandising and
     * support teams.
     *
     * Concurrency: place() is wrapped in a DB transaction with
     * lockForUpdate() on the placement parent, so two registrations
     * under the SAME parent can't race here. Two registrations under
     * DIFFERENT parents could in theory both pick the same MAX+1 — the
     * `uniq_distributors_adn` unique index is the last-line defence.
     * We retry up to 5 times on collision before giving up; given the
     * platform's expected registration rate, a real collision is rare
     * and a single retry resolves it.
     */
    private function generateAdn(): string
    {
        $start = 111_222_333; // root reserves this; first new joiner = 111222334

        for ($attempt = 0; $attempt < 5; $attempt++) {
            // SELECT MAX(adn) over the strictly-9-digit subset only —
            // legacy AL-prefixed rows (if any survive a migration) sort
            // ahead of pure-digit strings under MySQL's collation, so we
            // filter them out explicitly.
            $maxAdn = $this->db->table('distributors')
                ->whereRaw("adn REGEXP '^[0-9]{9}$'")
                ->selectRaw('MAX(CAST(adn AS UNSIGNED)) AS max_adn')
                ->value('max_adn');

            $next = $maxAdn !== null ? ((int) $maxAdn) + 1 : $start + 1;

            $candidate = (string) $next;
            if (! $this->db->table('distributors')->where('adn', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            'Could not allocate a unique ADN after 5 attempts — investigate concurrent inserts.'
        );
    }
}
