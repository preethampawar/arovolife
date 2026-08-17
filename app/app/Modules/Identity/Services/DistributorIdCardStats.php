<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Compensation\Services\RankStatusService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Support\IndianNumber as Number;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

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
 * Every field is now live: total_personal_bv and personal_sales_title from the
 * BV ledger and the personal-purchase title ladder, highest_rank /
 * current_rank from the rank engine, total_withdrawal_income from the settled
 * payout line items. The tree-card partials still render three of these as
 * hard-coded `—`; grep for `PHASE_LATER_PLACEHOLDER` to find them.
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

        $ranks = $this->ownRankLabels($distributor);

        return [
            'name' => $user->full_name ?: $user->email,
            'adn' => $distributor->adn,
            'highest_rank' => $ranks['highest'],
            'current_rank' => $ranks['current'],
            'region' => 'India',
            'verification_label' => $user->verificationLabel(),
            'verification_class' => $user->verificationClass(),
            'activation_date' => $user->activated_at,
            'total_personal_bv' => $this->ownPersonalBv($distributor),
        ];
    }

    /**
     * The distributor's current and highest achieved rank — shown ONLY to the
     * authenticated owner, exactly like personal BV below (hard rule #3 — own
     * data only), and only while the Rank Bonus feature is live, so a
     * flag-off bonus leaves no trace on the card.
     *
     * @return array{current: ?string, highest: ?string}
     */
    private function ownRankLabels(Distributor $distributor): array
    {
        if (auth()->id() !== $distributor->user_id) {
            return ['current' => null, 'highest' => null];
        }

        if (! Feature::for(null)->active(RankBonusFeature::class)) {
            return ['current' => null, 'highest' => null];
        }

        try {
            return app(RankStatusService::class)->labelsFor((int) $distributor->id);
        } catch (QueryException) {
            return ['current' => null, 'highest' => null];
        }
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

        return $paise > 0 ? Number::format($paise / 100, 0).' BV' : null;
    }

    /**
     * The title the distributor currently holds on the personal-purchase ladder
     * — Retailer, Dealer, Wholesaler and up — resolved from their lifetime
     * personal BV by {@see PersonalBvTitleService}, the same service My Business
     * and the admin cut-off reports read. Null (renders "—") below the first
     * rung, and for any card that is not the authenticated viewer's own (hard
     * rule #3 — own data only).
     */
    private function ownPersonalTitle(Distributor $distributor): ?string
    {
        if (auth()->id() !== $distributor->user_id) {
            return null;
        }

        try {
            return app(PersonalBvTitleService::class)
                ->forBvPaise($this->bvLedger->totalPersonalBvPaise($distributor->id))
                ->title;
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * Lifetime money actually settled to the distributor's bank, net of every
     * deduction — the same figure the wallet page shows as "Total paid out",
     * from the same service. Own data only; null (renders "—") before the
     * first transfer clears.
     */
    private function ownTotalWithdrawalIncome(Distributor $distributor): ?string
    {
        if (auth()->id() !== $distributor->user_id) {
            return null;
        }

        try {
            $paise = app(PayoutService::class)->totalTransferredPaise((int) $distributor->id);
        } catch (QueryException) {
            return null;
        }

        return $paise > 0 ? '₹'.Number::format($paise / 100, 2) : null;
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
            'personal_sales_title' => $this->ownPersonalTitle($distributor),
            'left_team' => $teamCounts['left_team'],
            'right_team' => $teamCounts['right_team'],
            'total_team' => $teamCounts['total_team'],
            'total_withdrawal_income' => $this->ownTotalWithdrawalIncome($distributor),
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
