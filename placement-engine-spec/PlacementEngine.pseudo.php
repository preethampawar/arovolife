<?php
declare(strict_types=1);

/**
 * Pseudocode reference for App\Modules\Genealogy\Services\PlacementEngine.
 *
 * Translate to a real Laravel service during /bootstrap-laravel.
 * Conventions: see docs/architecture/service-layer.md and CLAUDE.md.
 */

final class PlacementEngine
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly PlacementStrategyResolver $resolver,
        private readonly DistributorRepository $distributors,
        private readonly ClosureWriter $closure,
        private readonly SponsorshipWriter $sponsorship,
        private readonly EventDispatcherInterface $events,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Place a new distributor in the binary tree.
     *
     * @return PlacementResult { distributor_id, parent_id, side, depth, strategy_snapshot, side_chosen_by }
     */
    public function place(PlaceDistributorInput $in, StrategySnapshot $snapshot): PlacementResult
    {
        // Step 1 — resolve placement_id
        $placementId = $in->placementIdOpt ?? $in->sponsorId;

        // Step 2 — descendant validation
        if (!$this->isSelfOrDescendant($in->sponsorId, $placementId)) {
            $this->events->dispatch(new ForbiddenPlacementAttempted($in));
            throw new CrossLinePlacementError(
                "placement_id={$placementId} is not in the downline of sponsor_id={$in->sponsorId}"
            );
        }

        // Step 3 — resolve side using the FROZEN strategy snapshot
        [$chosenBy, $side] = $this->resolver->resolve(
            $snapshot->strategy,
            $in->sideOpt,
            $snapshot->allowSponsorOverride,
        );

        return $this->db->transaction(function () use ($in, $placementId, $snapshot, $chosenBy, $side) {
            $this->db->statement("SELECT GET_LOCK(?, 5)", ["placement:{$placementId}"]);
            try {
                // Step 4 — find first empty slot down the chosen spine
                [$parentId, $finalSide, $depth] = $this->findFirstEmptySlot($placementId, $side);

                // Step 5 — persist (distributor + closure + sponsorship)
                $distributorId = $this->distributors->insert([
                    'user_id'                       => $in->userId,
                    'adn'                           => $this->generateAdn(),
                    'pan_hash'                      => $in->panHash,
                    'pan_last4'                     => $in->panLast4,
                    'aadhaar_ref'                   => $in->aadhaarRef,
                    'aadhaar_last4'                 => $in->aadhaarLast4,
                    'bank_account_enc'              => $in->bankAccountEnc,
                    'bank_ifsc'                     => $in->bankIfsc,
                    'sponsor_id'                    => $in->sponsorId,
                    'placement_id_at_registration'  => $in->placementIdOpt,        // NULL means defaulted to sponsor
                    'placement_parent_id'           => $parentId,
                    'placement_side'                => $finalSide,
                    'placement_strategy_snapshot'   => $snapshot->strategy,
                    'side_chosen_by'                => $chosenBy,
                    'depth'                         => $depth,
                    'effective_date'                => $this->now(),
                    'cooling_off_end_at'            => $this->now()->addDays(30),
                    'state'                         => $in->state,
                    'is_primary_couple'             => (int) $in->isPrimaryCouple,
                ]);

                $this->closure->write($distributorId, $parentId);
                $this->sponsorship->write($in->sponsorId, $distributorId);

                $result = new PlacementResult(
                    distributorId: $distributorId,
                    parentId: $parentId,
                    side: $finalSide,
                    depth: $depth,
                    strategySnapshot: $snapshot->strategy,
                    sideChosenBy: $chosenBy,
                );

                $this->events->dispatch(new PlacementCreated($result, $in->sponsorId, $placementId));
                $this->events->dispatch(new DistributorRegistered($distributorId, $in->sponsorId));

                return $result;
            } finally {
                $this->db->statement("SELECT RELEASE_LOCK(?)", ["placement:{$placementId}"]);
            }
        });
    }

    /**
     * Walk down from $rootId on $startSide, taking the same side at each
     * level, until the chosen side is empty. Return (parent_id, side, depth).
     */
    private function findFirstEmptySlot(int $rootId, string $startSide): array
    {
        $current = $rootId;
        $depth = $this->distributors->depth($rootId);
        while (true) {
            $child = $this->distributors->child($current, $startSide);
            if ($child === null) {
                return [$current, $startSide, $depth + 1];
            }
            $current = $child;
            $depth++;
        }
    }

    private function isSelfOrDescendant(int $ancestorId, int $candidateId): bool
    {
        return $ancestorId === $candidateId
            || $this->closure->exists($ancestorId, $candidateId);
    }

    private function generateAdn(): string { /* short, unique, URL-safe */ }
    private function now(): \DateTimeImmutable { /* injected clock */ }
}
