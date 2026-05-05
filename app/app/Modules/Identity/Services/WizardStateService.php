<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use Illuminate\Contracts\Session\Session;

final class WizardStateService
{
    private const KEY = 'registration_wizard';

    private const INTENT_KEY = 'registration_intent';

    public const STEPS = [
        1 => 'account',
        2 => 'orientation',
        3 => 'personal',
        4 => 'pan',
        5 => 'aadhaar',
        6 => 'bank',
        7 => 'documents',
        8 => 'placement',
        9 => 'consent',
        10 => 'complete',
    ];

    public function __construct(private readonly Session $session) {}

    /**
     * Stash the resolved referral-link context BEFORE the user account is
     * created. Lives in a separate session bucket so the wizard's main state
     * (which only exists once the user submits step 1) can pull from it.
     *
     * @param  array<string, string>  $extras  e.g. resolved ADNs for display in the wizard
     */
    public function stashIntent(int $sponsorId, int $placementId, ?string $sideOpt, array $extras = []): void
    {
        $this->session->put(self::INTENT_KEY, array_merge([
            'sponsor_id' => $sponsorId,
            'placement_id' => $placementId,
            'side_opt' => $sideOpt,
        ], $extras));
    }

    /** @return array<string, mixed>|null */
    public function intent(): ?array
    {
        $intent = $this->session->get(self::INTENT_KEY);

        return is_array($intent) ? $intent : null;
    }

    public function intentSponsorId(): ?int
    {
        $intent = $this->intent();

        return isset($intent['sponsor_id']) ? (int) $intent['sponsor_id'] : null;
    }

    public function intentPlacementId(): ?int
    {
        $intent = $this->intent();

        return isset($intent['placement_id']) ? (int) $intent['placement_id'] : null;
    }

    public function intentSideOpt(): ?string
    {
        $intent = $this->intent();
        $side = $intent['side_opt'] ?? null;

        return is_string($side) && in_array($side, ['L', 'R'], true) ? $side : null;
    }

    public function clearIntent(): void
    {
        $this->session->forget(self::INTENT_KEY);
    }

    public function start(int $userId, int $sponsorId, int $placementId, ?string $sideOpt = null): void
    {
        // Orientation is now step 1 (public, before account creation), so the
        // wizard state opens at step 3 — the first auth-gated step. Step 2
        // (account) is implicit (the User row's existence is the proof).
        // handleAccount() backfills the step-2 orientation block immediately
        // after this call so the wizard data reads coherently downstream.
        $this->session->put(self::KEY, [
            'step' => 3,
            'user_id' => $userId,
            'sponsor_id' => $sponsorId,
            'placement_id' => $placementId,
            'side_opt' => $sideOpt,
            'data' => [],
        ]);
    }

    public function get(): ?array
    {
        return $this->session->get(self::KEY);
    }

    public function currentStep(): int
    {
        return (int) ($this->get()['step'] ?? 1);
    }

    public function userId(): ?int
    {
        $state = $this->get();

        return $state ? (int) $state['user_id'] : null;
    }

    public function sponsorId(): ?int
    {
        $state = $this->get();

        return $state ? (int) $state['sponsor_id'] : null;
    }

    public function placementId(): ?int
    {
        $state = $this->get();

        return isset($state['placement_id']) ? (int) $state['placement_id'] : null;
    }

    public function placementSideOpt(): ?string
    {
        $state = $this->get();
        $side = $state['side_opt'] ?? null;

        return is_string($side) && in_array($side, ['L', 'R'], true) ? $side : null;
    }

    public function saveStepData(int $step, array $data): void
    {
        $state = $this->get() ?? ['step' => $step, 'user_id' => null, 'sponsor_id' => null, 'data' => []];
        $state['data'][self::STEPS[$step]] = $data;
        $state['step'] = max($state['step'], $step + 1);
        $this->session->put(self::KEY, $state);
    }

    public function getStepData(int $step): ?array
    {
        return $this->get()['data'][self::STEPS[$step]] ?? null;
    }

    public function isStepComplete(int $step): bool
    {
        if ($step === 1) {
            return $this->userId() !== null;
        }

        return $this->getStepData($step) !== null;
    }

    public function clear(): void
    {
        $this->session->forget(self::KEY);
        $this->session->forget(self::INTENT_KEY);
    }
}
