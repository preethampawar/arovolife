<?php

declare(strict_types=1);

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;

/**
 * Who may see a distributor's record (T-6.1 finding M-6).
 *
 * Three answers, and the middle one is the one that needs stating.
 *
 *  1. **Themselves** — always.
 *  2. **Their upline** — only what the genealogy screens already show, which
 *     is placement and status, never PAN, Aadhaar, bank details or address.
 *     This policy governs the record; the views govern the fields, and neither
 *     alone is sufficient.
 *  3. **Staff** — through the admin routes, which carry their own permission.
 *
 * Downline membership is answered by `TeamStatsService`, the single source for
 * that question. Re-implementing a closure-table walk here would create a
 * second definition of "in my downline" that could drift from the one the
 * dashboards use — and a security check that disagrees with the UI is worse
 * than no check, because it will be trusted.
 */
final class DistributorPolicy
{
    public function view(User $user, Distributor $distributor): bool
    {
        return $this->isSelf($user, $distributor);
    }

    /**
     * Editing identity, credentials or KYC is staff work gated by
     * `distributor.credentials` and `kyc.review`. A distributor cannot edit
     * their own PAN or bank account — that would defeat the KYC.
     */
    public function update(User $user, Distributor $distributor): bool
    {
        return $user->can('distributor.credentials');
    }

    private function isSelf(User $user, Distributor $distributor): bool
    {
        return (int) $distributor->user_id === (int) $user->id;
    }
}
