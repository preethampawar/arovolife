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
use Illuminate\Support\Facades\DB;
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
 * Every field is live and every surface reads them from here:
 * total_personal_bv and personal_sales_title from the BV ledger and the
 * personal-purchase title ladder, highest_rank / current_rank from the rank
 * engine, total_withdrawal_income from the settled payout line items. No
 * placeholders remain.
 *
 * Rank and personal BV are own-data-only by default. While the developer
 * setting `genealogy.downline_stats_visible` is ON (client decision
 * 2026-08-30, R-65, hard rule 3 as amended) they are ALSO shown to the
 * distributor's sponsor, their placement upline and admins — and to nobody
 * else. {@see self::compactMany()} enforces that per node, so every caller
 * (tree canvas, Details popup, dashboard) inherits the same guard; a tree
 * canvas resolves every visible card in one pass through it and never per
 * node. The personal-purchase title and the withdrawal income on the full
 * card stay own-data-only regardless of the setting.
 */
final class DistributorIdCardStats
{
    public const string DOWNLINE_STATS_SETTING = 'genealogy.downline_stats_visible';

    public function __construct(
        private readonly TeamStatsService $teamStats,
        private readonly BvLedgerService $bvLedger,
    ) {}

    /**
     * Compact 8-field stats — the subset rendered on each tree card and the
     * head of the full dashboard card.
     *
     * @return array<string, mixed>
     */
    public function compact(Distributor $distributor): array
    {
        return $this->compactMany([$distributor])[(int) $distributor->id];
    }

    /**
     * {@see self::compact()} for every distributor on a tree canvas, keyed by
     * distributor id. Batches the rank labels and the personal-BV totals so
     * a canvas of N cards costs three queries, not 3N.
     *
     * @param  iterable<Distributor>  $distributors
     * @return array<int, array<string, mixed>>
     */
    public function compactMany(iterable $distributors): array
    {
        $list = [];
        foreach ($distributors as $distributor) {
            $list[(int) $distributor->id] = $distributor;
        }
        if ($list === []) {
            return [];
        }

        $ids = array_keys($list);
        $visible = $this->statsVisibleIds($ids);
        $ranks = $this->rankLabels($visible);
        $this->bvLedger->warmPersonalBvCache($visible);

        $out = [];
        foreach ($list as $id => $distributor) {
            // The relation is non-null by schema (distributors.user_id is NOT
            // NULL with an FK to users.id) — Larastan correctly flags
            // nullsafe access here as unreachable. Read directly.
            $user = $distributor->user;
            $canSee = in_array($id, $visible, true);
            $paise = $canSee ? $this->bvLedger->totalPersonalBvPaise($id) : 0;

            $out[$id] = [
                'name' => $user->full_name ?: $user->email,
                'adn' => $distributor->adn,
                'highest_rank' => $canSee ? ($ranks[$id]['highest'] ?? null) : null,
                'current_rank' => $canSee ? ($ranks[$id]['current'] ?? null) : null,
                'region' => 'India',
                'verification_label' => $user->verificationLabel(),
                'verification_class' => $user->verificationClass(),
                'activation_date' => $user->activated_at,
                'total_personal_bv' => $paise > 0 ? Number::format($paise / 100, 0).' BV' : null,
            ];
        }

        return $out;
    }

    /**
     * Whether the downline-visibility switch is ON. Surfaces use this to
     * decide whether to show the "visible to you as their upline" notice —
     * zero trace while OFF.
     */
    public function downlineStatsVisible(): bool
    {
        try {
            return DB::table('settings')
                ->where('key', self::DOWNLINE_STATS_SETTING)
                ->value('value') === 'true';
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * The subset of $ids whose rank and personal BV the authenticated user may
     * see: always their own node; and, while the switch is ON, every node
     * they sponsor or sit above in the Genos (closure descendants), or every
     * node at all for super-staff. One closure query, one sponsor query.
     *
     * @param  int[]  $ids
     * @return int[]
     */
    private function statsVisibleIds(array $ids): array
    {
        $viewer = auth()->user();
        if ($viewer === null) {
            return [];
        }

        $own = $viewer->distributor?->id;
        $visible = $own !== null && in_array((int) $own, $ids, true) ? [(int) $own] : [];

        if (! $this->downlineStatsVisible()) {
            return $visible;
        }

        if ($viewer->isSuperStaff()) {
            return $ids;
        }

        if ($own === null) {
            return $visible;
        }

        $descendants = DB::table('genealogy_closure')
            ->where('ancestor_id', $own)
            ->where('depth', '>', 0)
            ->whereIn('descendant_id', $ids)
            ->pluck('descendant_id');

        $sponsored = Distributor::query()
            ->where('sponsor_id', $own)
            ->whereIn('id', $ids)
            ->whereColumn('id', '!=', 'sponsor_id')
            ->pluck('id');

        return array_values(array_unique(array_map('intval', array_merge(
            $visible,
            $descendants->all(),
            $sponsored->all(),
        ))));
    }

    /**
     * Current and highest achieved rank for the ids the viewer may see
     * (R-65), and only while the Rank Bonus feature is live, so a flag-off
     * bonus leaves no trace.
     *
     * @param  int[]  $ids
     * @return array<int, array{current: ?string, highest: ?string}>
     */
    private function rankLabels(array $ids): array
    {
        if (! Feature::for(null)->active(RankBonusFeature::class)) {
            return [];
        }

        try {
            return app(RankStatusService::class)->labelsForMany($ids);
        } catch (QueryException) {
            return [];
        }
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
