<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\RegistrationDraft;
use Illuminate\Support\Facades\Crypt;

final class DraftStateService
{
    private const TTL_DAYS = 7;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $userId, int $sponsorId, int $placementId, ?string $sideOpt, array $data, int $currentStep = 3): string
    {
        // Delete any existing draft so the unique constraint is not violated.
        $this->delete($userId);

        $rawToken = bin2hex(random_bytes(32));

        RegistrationDraft::create([
            'user_id' => $userId,
            'draft_token_hash' => hash('sha256', $rawToken, true),
            'current_step' => $currentStep,
            'sponsor_id' => $sponsorId,
            'placement_id' => $placementId,
            'side_opt' => $sideOpt,
            'payload_enc' => Crypt::encryptString((string) json_encode($data)),
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        return $rawToken;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sync(int $userId, int $currentStep, array $data): void
    {
        RegistrationDraft::where('user_id', $userId)->update([
            'current_step' => $currentStep,
            'payload_enc' => Crypt::encryptString((string) json_encode($data)),
        ]);
    }

    public function updatePlacement(int $userId, int $sponsorId, int $placementId, ?string $sideOpt): void
    {
        RegistrationDraft::where('user_id', $userId)->update([
            'sponsor_id' => $sponsorId,
            'placement_id' => $placementId,
            'side_opt' => $sideOpt,
        ]);
    }

    public function resolveFromToken(string $rawToken): ?RegistrationDraft
    {
        $hash = hash('sha256', $rawToken, true);

        return RegistrationDraft::active()
            ->where('draft_token_hash', $hash)
            ->first();
    }

    public function findActiveByUserId(int $userId): ?RegistrationDraft
    {
        return RegistrationDraft::active()
            ->where('user_id', $userId)
            ->first();
    }

    public function restoreToWizard(RegistrationDraft $draft, WizardStateService $wizard): void
    {
        $data = json_decode(Crypt::decryptString($draft->payload_enc), true) ?? [];

        $wizard->restore(
            userId: $draft->user_id,
            sponsorId: $draft->sponsor_id,
            placementId: $draft->placement_id,
            sideOpt: $draft->side_opt,
            data: $data,
            currentStep: $draft->current_step,
        );
    }

    public function delete(int $userId): void
    {
        RegistrationDraft::where('user_id', $userId)->delete();
    }

    public function purgeExpired(): int
    {
        return RegistrationDraft::where('expires_at', '<', now())->delete();
    }
}
