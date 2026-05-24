<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use Illuminate\Contracts\Session\Session;

final class WizardStateService
{
    private const KEY = 'registration_wizard';

    private const INTENT_KEY = 'registration_intent';

    public const STEPS = [
        1 => 'sponsor_placement',
        2 => 'account',
        3 => 'orientation',
        4 => 'consent',
        5 => 'pan',
        6 => 'aadhaar',
        7 => 'bank',
        8 => 'personal',
        9 => 'documents',
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

    public function start(int $sponsorId, int $placementId, ?string $sideOpt = null): void
    {
        // Pure session-based wizard: no user_id stored. User is created
        // atomically at step 10 finalisation. Step 2 data (account) is
        // saved to session like all other steps.
        $this->session->put(self::KEY, [
            'step' => 2,
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

    public function registrationSessionId(): string
    {
        return $this->session->getId();
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
        $state = $this->get() ?? ['step' => $step, 'sponsor_id' => null, 'placement_id' => null, 'side_opt' => null, 'data' => []];
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
        // Step 1 lives in `registration_intent`; steps 2+ live in session data.
        if ($step === 1) {
            return $this->intent() !== null;
        }

        return $this->getStepData($step) !== null;
    }

    public function clear(): void
    {
        $this->session->forget(self::KEY);
        $this->session->forget(self::INTENT_KEY);
    }


    /**
     * Map a wizard step number to its named route.
     * Used after draft restoration so we know where to redirect.
     */
    public static function stepRoute(int $step): string
    {
        return match (true) {
            $step <= 3 => 'register.orientation',
            $step === 4 => 'register.consent',
            $step === 5 => 'register.pan',
            $step === 6 => 'register.aadhaar',
            $step === 7 => 'register.bank',
            $step === 8 => 'register.personal',
            $step === 9 => 'register.documents',
            default => 'register.complete',
        };
    }
}
