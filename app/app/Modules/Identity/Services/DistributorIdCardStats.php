<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\PayoutService;
use App\Modules\Compensation\Services\PersonalBvTitleService;
use App\Modules\Compensation\Services\RankStatusService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Support\IndianNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 * Rank, personal BV and the purchase mark are own-data-only by default. Two
 * developer settings release them to the downline audience independently —
 * `genealogy.downline_stats_visible` for rank and BV, and
 * `genealogy.purchase_mark_visible` for the mark, which is three buckets
 * rather than a figure and so can be released on its own (client decision
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

    /**
     * The purchase mark has its own switch because it is a far coarser
     * disclosure than the BV figure it derives from: three buckets, no number.
     * Both switches scope to the same audience by the same rules — this one
     * only decides whether the mark travels with the number or without it.
     */
    public const string PURCHASE_MARK_SETTING = 'genealogy.purchase_mark_visible';

    /** Active, and personal BV at or above the qualifying gate. */
    public const string MARK_QUALIFIED = 'qualified';

    /** Active and buying, but still short of the gate. */
    public const string MARK_PURCHASED = 'purchased';

    /** Not active, or active with nothing purchased yet. */
    public const string MARK_NONE = 'none';

    /**
     * Presentation for the three purchase marks — the one source shared by the
     * tree card's mark and the canvas legend, so the two can never drift.
     * `:bv` interpolates the live gate: it is a plan setting the client can
     * move, not a constant 600.
     *
     * The middle state is yellow-400 rather than an amber: amber sits close
     * enough to red-600 in hue that at a zoomed-out dot the two read as one
     * colour, and the whole point of the mark is being legible at that size.
     * `icon` rides alongside `class` because the glyph cannot be white on a
     * colour that bright — a white star on yellow all but disappears, so the
     * bright fill takes a dark star and the two dark fills take a white one.
     *
     * @var array<string, array{class: string, icon: string, label: string, hint: string}>
     */
    private const PURCHASE_MARKS = [
        self::MARK_QUALIFIED => ['class' => 'bg-green-600', 'icon' => 'text-white', 'label' => 'Qualified · :bv+ BV', 'hint' => 'Active, and has purchased :bv BV or more'],
        self::MARK_PURCHASED => ['class' => 'bg-yellow-400', 'icon' => 'text-yellow-950', 'label' => 'Buying · under :bv BV', 'hint' => 'Active and purchasing, but still under :bv BV'],
        self::MARK_NONE => ['class' => 'bg-red-600', 'icon' => 'text-white', 'label' => 'No purchase yet', 'hint' => 'Nothing purchased yet, or the account is not active'],
    ];

    /** Memoised {@see self::qualifyBvPaise()}; false until first resolved. */
    private int|false|null $qualifyBvPaise = false;

    /**
     * Memoised {@see self::switchOn()} values, keyed by settings key.
     *
     * @var array<string, string|null>|null
     */
    private ?array $switches = null;

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
        // Two audiences, resolved together from one pair of queries: the stats
        // rows and the purchase mark have separate switches, so a viewer can be
        // entitled to the mark on a card whose BV figure stays hidden.
        ['stats' => $visible, 'mark' => $markVisible] = $this->visibleIds($ids);
        $qualifyPaise = $this->qualifyBvPaise();
        $ranks = $this->rankLabels($visible);
        // Personal BV feeds both, so warm their union — still one query.
        $this->bvLedger->warmPersonalBvCache(
            array_values(array_unique(array_merge($visible, $markVisible)))
        );

        $out = [];
        foreach ($list as $id => $distributor) {
            // The relation is non-null by schema (distributors.user_id is NOT
            // NULL with an FK to users.id) — Larastan correctly flags
            // nullsafe access here as unreachable. Read directly.
            $user = $distributor->user;
            $canSee = in_array($id, $visible, true);
            $canSeeMark = in_array($id, $markVisible, true);
            $paise = $canSee || $canSeeMark ? $this->bvLedger->totalPersonalBvPaise($id) : 0;

            $out[$id] = [
                'name' => $user->full_name ?: $user->email,
                'adn' => $distributor->adn,
                'highest_rank' => $canSee ? ($ranks[$id]['highest'] ?? null) : null,
                'current_rank' => $canSee ? ($ranks[$id]['current'] ?? null) : null,
                'region' => 'India',
                'verification_label' => $user->verificationLabel(),
                'verification_class' => $user->verificationClass(),
                'activation_date' => $user->activated_at,
                'total_personal_bv' => $canSee && $paise > 0 ? IndianNumber::format($paise / 100, 0).' BV' : null,
                // Gated on its own switch. A three-bucket read of personal
                // BV is still personal BV and still R-65 territory, but it is
                // a small enough disclosure to release independently: the card
                // can carry the mark while the BV figure stays hidden.
                'purchase_state' => $canSeeMark && $qualifyPaise !== null
                    ? $this->purchaseState((string) $user->status, $paise, $qualifyPaise)
                    : null,
            ];
        }

        return $out;
    }

    /**
     * The purchase-mark presentation map keyed by state, with the live BV gate
     * interpolated into label and hint. Empty when the gate cannot be read,
     * which suppresses the mark everywhere rather than colouring it from a
     * guess. `label` is the legend's short form, `hint` the card tooltip's.
     *
     * @return array<string, array{class: string, icon: string, label: string, hint: string}>
     */
    public function purchaseMarkMap(): array
    {
        $gate = $this->qualifyBvPaise();
        if ($gate === null) {
            return [];
        }

        $bv = IndianNumber::format($gate / 100, 0);

        return array_map(
            fn (array $mark): array => [
                'class' => $mark['class'],
                'icon' => $mark['icon'],
                'label' => str_replace(':bv', $bv, $mark['label']),
                'hint' => str_replace(':bv', $bv, $mark['hint']),
            ],
            self::PURCHASE_MARKS,
        );
    }

    /**
     * Which of the three marks a card carries. Red covers both halves of "no
     * business yet" — an account that is not active, and an active one that
     * has never accrued personal BV. Amber is buying but below the gate;
     * green is at or above it.
     */
    private function purchaseState(string $status, int $personalBvPaise, int $qualifyBvPaise): string
    {
        if ($status !== 'active' || $personalBvPaise <= 0) {
            return self::MARK_NONE;
        }

        return $personalBvPaise >= $qualifyBvPaise ? self::MARK_QUALIFIED : self::MARK_PURCHASED;
    }

    /**
     * Lifetime personal BV (paise) at which a distributor counts as qualified
     * — comp.gsb.min_bv_paise, the same gate the repurchase cycle card and the
     * GSB engine read, never a hardcoded 600. Memoised: one canvas resolves it
     * once for every card and the legend. Null when the settings table is
     * unreadable.
     */
    private function qualifyBvPaise(): ?int
    {
        if ($this->qualifyBvPaise !== false) {
            return $this->qualifyBvPaise;
        }

        try {
            return $this->qualifyBvPaise = app(CompensationPlanSettingsService::class)->gsbMinBvPaise();
        } catch (QueryException $e) {
            Log::warning('DistributorIdCardStats::qualifyBvPaise query failed — hiding the purchase mark', [
                'exception' => $e,
            ]);

            return $this->qualifyBvPaise = null;
        }
    }

    /**
     * Whether the downline rank / personal-BV switch is ON. Surfaces use this
     * to decide whether to show the "visible to you as their upline" notice —
     * zero trace while OFF.
     */
    public function downlineStatsVisible(): bool
    {
        return $this->switchOn(self::DOWNLINE_STATS_SETTING);
    }

    /**
     * Whether the purchase mark may be shown on downline cards. Independent of
     * {@see self::downlineStatsVisible()}: the mark is three buckets, not a
     * figure, so the client can release it without releasing BV totals.
     */
    public function purchaseMarkVisible(): bool
    {
        return $this->switchOn(self::PURCHASE_MARK_SETTING);
    }

    /**
     * Both visibility switches, read in one query and memoised for the life of
     * the instance: a canvas asks up to four times (two audiences, plus the
     * notice) and must not pay for each. An unreadable settings table is
     * treated as OFF — a privacy switch fails closed, never open.
     */
    private function switchOn(string $key): bool
    {
        if ($this->switches === null) {
            try {
                $this->switches = DB::table('settings')
                    ->whereIn('key', [self::DOWNLINE_STATS_SETTING, self::PURCHASE_MARK_SETTING])
                    ->pluck('value', 'key')
                    ->all();
            } catch (QueryException $e) {
                Log::warning('DistributorIdCardStats::downlineStatsVisible query failed — treating both visibility switches as OFF', [
                    'exception' => $e,
                ]);

                $this->switches = [];
            }
        }

        return ($this->switches[$key] ?? null) === 'true';
    }

    /**
     * Who may see what on this canvas, as two subsets of $ids: `stats` for the
     * rank / personal-BV rows, `mark` for the purchase mark. Each is the
     * viewer's own node always, widened to the R-65 downline audience only
     * while that subset's own switch is ON. The audience costs one closure
     * query and one sponsor query, resolved once and shared by both — and not
     * run at all while both switches are OFF. Staff always get every id in `mark` on the admin Genos (admin.tree.*).
     *
     * @param  int[]  $ids
     * @return array{stats: int[], mark: int[]}
     */
    private function visibleIds(array $ids): array
    {
        $viewer = auth()->user();
        if ($viewer === null) {
            return ['stats' => [], 'mark' => []];
        }

        $own = $viewer->distributor?->id;
        $ownOnly = $own !== null && in_array((int) $own, $ids, true) ? [(int) $own] : [];

        $statsOn = $this->downlineStatsVisible();
        $markOn = $this->purchaseMarkVisible();
        // Staff see the purchase mark on every card they can open (the admin
        // Genos). The switch exists for the R-65 downline audience — a
        // distributor looking at other distributors — and staff already see
        // personal BV on the admin distributor page. Stats rows stay switched.
        // Scoped to the admin Genos only: the same service feeds the Details
        // popup, the dashboard and the membership card, where a staff member
        // who also holds a distributor record is just another viewer.
        $staff = $viewer->isStaff() && request()->routeIs('admin.tree.*');

        if (! $statsOn && ! $markOn) {
            return ['stats' => $ownOnly, 'mark' => $staff ? $ids : $ownOnly];
        }

        $audience = $this->downlineAudience($ids, $ownOnly, $own === null ? null : (int) $own);

        return [
            'stats' => $statsOn ? $audience : $ownOnly,
            'mark' => $staff ? $ids : ($markOn ? $audience : $ownOnly),
        ];
    }

    /**
     * The R-65 audience within $ids: the viewer's own node, plus every node
     * they sponsor or sit above in the Genos (closure descendants) — or every
     * node on the canvas for super-staff.
     *
     * @param  int[]  $ids
     * @param  int[]  $ownOnly
     * @return int[]
     */
    private function downlineAudience(array $ids, array $ownOnly, ?int $own): array
    {
        if (auth()->user()?->isSuperStaff() === true) {
            return $ids;
        }

        if ($own === null) {
            return $ownOnly;
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
            $ownOnly,
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
        } catch (QueryException $e) {
            Log::warning('DistributorIdCardStats::rankLabels query failed — hiding ranks', [
                'distributor_ids' => $ids,
                'exception' => $e,
            ]);

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
        } catch (QueryException $e) {
            Log::warning('DistributorIdCardStats::ownPersonalTitle query failed — hiding title', [
                'distributor_id' => $distributor->id,
                'exception' => $e,
            ]);

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
        } catch (QueryException $e) {
            Log::warning('DistributorIdCardStats::ownTotalWithdrawalIncome query failed — hiding figure', [
                'distributor_id' => $distributor->id,
                'exception' => $e,
            ]);

            return null;
        }

        return $paise > 0 ? '₹'.IndianNumber::format($paise / 100, 2) : null;
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
            'company' => 'Arovolife Private Limited',
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
        } catch (\Throwable $e) {
            Log::warning('DistributorIdCardStats::photoUrl S3 temporary URL failed — omitting photo', [
                'distributor_id' => $distributor->id,
                'exception' => $e,
            ]);

            return null;
        }
    }
}
