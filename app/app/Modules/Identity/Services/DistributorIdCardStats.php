<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Facades\Storage;

/**
 * The ID-card stats panel rendered in three places:
 *
 *   1. Distributor dashboard ("Your ADN" expanded card) — full 15-field
 *      view via {@see self::full()}.
 *   2. Tree-view distributor card (binary + sponsorship) — compact
 *      8-field view via {@see self::compact()}.
 *   3. The "Details" modal opened from the tree card's menu — full view
 *      reusing the same partial as the dashboard.
 *
 * Centralising the assembly here is the project's single-source-of-truth
 * contract for these stats. Pages MUST go through this service rather
 * than read from $user / $distributor directly, so when later phases
 * wire the placeholder fields (rank engine, BV ledger, payouts) every
 * surface picks up the new values automatically.
 *
 * Five fields are Phase-2+ placeholders that resolve to `null` for now.
 * Grep for `PHASE_LATER_PLACEHOLDER` to find every wire-up site.
 */
final class DistributorIdCardStats
{
    public function __construct(
        private readonly TeamStatsService $teamStats,
        private readonly BvLedgerService $bvLedger,
    ) {}

    /**
     * Compact 8-field stats — the subset rendered on each tree card. No
     * team-count joins, so cheap enough to call once per node.
     *
     * @return array<string, mixed>
     */
    public function compact(Distributor $distributor): array
    {
        // The relation is non-null by schema (distributors.user_id is NOT
        // NULL with an FK to users.id) — Larastan correctly flags
        // nullsafe access here as unreachable. Read directly.
        $user = $distributor->user;

        return [
            'name' => $user->full_name ?: $user->email,
            'adn' => $distributor->adn,
            'highest_rank' => null, // PHASE_LATER_PLACEHOLDER (Phase 4 — rank engine)
            'current_rank' => null, // PHASE_LATER_PLACEHOLDER (Phase 4 — rank engine)
            'region' => 'India',
            'verification_label' => $user->verificationLabel(),
            'verification_class' => $user->verificationClass(),
            'activation_date' => $user->activated_at,
            'total_personal_bv' => $this->ownPersonalBv($distributor),
        ];
    }

    /**
     * The distributor's accumulated personal BV (ADR-0006), formatted for
     * display — but ONLY when the card belongs to the authenticated viewer.
     * A downline member's personal BV is never exposed to an upline or admin
     * via the tree/Details surfaces (hard rule #3 — own data only). Returns
     * null (renders "—") for other distributors or when nothing has accrued.
     */
    private function ownPersonalBv(Distributor $distributor): ?string
    {
        if (auth()->id() !== $distributor->user_id) {
            return null;
        }

        $paise = $this->bvLedger->totalPersonalBvPaise($distributor->id);

        return $paise > 0 ? number_format($paise / 100, 0).' BV' : null;
    }

    /**
     * Full 15-field stats — the dashboard's "Your ADN" panel and the
     * tree's Details popup. Adds team counts and the remaining
     * dashboard-only fields on top of {@see self::compact()}.
     *
     * @return array<string, mixed>
     */
    public function full(Distributor $distributor): array
    {
        $compact = $this->compact($distributor);
        $teamCounts = $this->teamStats->counts($distributor);

        return array_merge($compact, [
            'registration_date' => $distributor->effective_date,
            'franchise' => 'Arovolife Private Limited',
            'personal_sales_position' => null, // PHASE_LATER_PLACEHOLDER (Phase 2 — sales ledger)
            'left_team' => $teamCounts['left_team'],
            'right_team' => $teamCounts['right_team'],
            'total_team' => $teamCounts['total_team'],
            'total_withdrawal_income' => null, // PHASE_LATER_PLACEHOLDER (Phase 5 — payouts)
        ]);
    }

    /**
     * Short-lived signed URL for the distributor's self-uploaded ID
     * photo, or null if no photo / S3 unreachable in dev. Both the
     * dashboard panel and the Details popup display this — same source.
     */
    public function photoUrl(Distributor $distributor): ?string
    {
        $key = $distributor->user->id_photo_path;
        if ($key === null) {
            return null;
        }

        try {
            return Storage::disk('s3')->temporaryUrl($key, now()->addMinutes(15));
        } catch (\Throwable) {
            return null;
        }
    }
}
