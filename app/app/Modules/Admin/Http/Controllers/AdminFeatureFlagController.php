<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\FranchiseFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\GsbDailyPoolPricingFeature;
use App\Modules\Shared\Features\HibpPasswordCheck;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Features\RegistrationKillswitch;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Laravel\Pennant\Feature;

final class AdminFeatureFlagController extends Controller
{
    /**
     * Single registry of toggleable feature flags. Adding a new flag means:
     * (1) create the resolver class, (2) add a row here.
     *
     * `owner` decides who may see and toggle the flag:
     *  - 'incident'  — operational/compliance controls that must be reachable
     *                  during a live incident: the whole admin family plus
     *                  compliance. A killswitch only one absent person can
     *                  pull is not a killswitch.
     *  - 'developer' — engine and security configuration.
     * Flags a viewer does not own are filtered out server-side and 404 on the
     * toggle endpoint.
     *
     * `requires` lists the registry keys of flags this feature builds on —
     * purely informational for the admin UI (a dependent flag left ON without
     * its prerequisites computes on missing data rather than erroring, so
     * enforcement would only block viewing historical reports).
     *
     * @return array<string, array{class: class-string, label: string, description: string, owner: string, requires?: list<string>}>
     */
    private function registry(): array
    {
        return [
            'registration.killswitch' => [
                'class' => RegistrationKillswitch::class,
                'label' => 'Registration killswitch',
                'description' => 'When OFF, the public /register and /join entry points return a "temporarily closed" page. In-progress wizards continue. Use this to halt new registrations immediately during a compliance or security incident.',
                'owner' => 'incident',
            ],
            'password.hibp_check' => [
                'class' => HibpPasswordCheck::class,
                'label' => 'HIBP password breach check',
                'description' => 'Extra layer of password security. When ON, every new/changed password is checked against the Have-I-Been-Pwned breach database via k-anonymity API (api.pwnedpasswords.com). When OFF, the breach check is skipped — only the zxcvbn entropy gate runs. Keep ON in production; safe to turn OFF on offline staging boxes or for demo seeding.',
                'owner' => 'developer',
            ],

            // ── Phase 4 compensation features (default OFF — partner sign-off required) ──
            'compensation.genos_sales_bonus' => [
                'class' => GenosSalesBonusFeature::class,
                'label' => 'Genos Sales Bonus (Phase 4)',
                'description' => 'The foundational daily GSB engine. When OFF, the gsb:daily-cutoff and gsb:weekly-payout commands no-op — no GSB is earned or paid (and the Mentorship Bonus, computed alongside GSB, is skipped too). Enable after partners approve the plan.',
                'owner' => 'developer',
            ],
            'compensation.gsb_daily_pool_pricing' => [
                'class' => GsbDailyPoolPricingFeature::class,
                'label' => 'GSB daily pool pricing — slabs 3–7 (KP 2026-07-29)',
                'requires' => ['compensation.genos_sales_bonus'],
                'description' => '⚠ COMPLIANCE GATE (risk register R-33). When ON, GSB slabs 3–7 are no longer paid a fixed ₹ amount: each day their score value is pro-rated from the 45% company-BV pool (slabs 1–2 stay fixed and always pay in full). This changes what distributors are paid, so it must NOT be enabled in any environment paying real distributors until the DSA §6.2 thirty-day written notice has run its course AND the formula is published at /p/compensation. When OFF, the engine is byte-identical to the fixed-bonus behaviour.',
                'owner' => 'developer',
            ],
            'compensation.mentorship_bonus' => [
                'class' => MentorshipBonusFeature::class,
                'label' => 'Mentorship Bonus (Phase 4)',
                'requires' => ['compensation.genos_sales_bonus'],
                'description' => 'Shows the Mentorship Bonus income tab and admin views for distributors, and lets the daily cut-off accrue and credit MSB. When a directly sponsored sponsee matches a GSB slab, the sponsor earns that slab\'s MSB points; each day\'s point value is the MSB pool (default 3% of the day\'s company BV) divided by the day\'s total points, floored to whole rupees. Enable after partners approve the mentorship plan.',
                'owner' => 'developer',
            ],
            'compensation.repurchase_engine' => [
                'class' => RepurchaseEngineFeature::class,
                'label' => 'Repurchase / income-eligibility engine (Phase 4)',
                'requires' => ['compensation.genos_sales_bonus'],
                'description' => 'When ON, the daily GSB cut-off consults each distributor\'s monthly repurchase status: if they missed their repurchase due date the bonus is held (grace) or, after grace, suspended — GSB/Fortune/GBB only, never Mentorship or Rank. When OFF, repurchase status is ignored. Run repurchase:evaluate daily.',
                'owner' => 'developer',
            ],
            'compensation.growth_booster_bonus' => [
                'class' => GrowthBoosterBonusFeature::class,
                'label' => 'Growth Booster Bonus (Phase 4)',
                'requires' => ['compensation.genos_sales_bonus', 'compensation.rank_bonus'],
                'description' => 'Enables the monthly GBB pool (5% of turnover) distributed via AGP points. Shows the Growth Booster tab in income views and admin GBB dashboard. Also gates the gbb:monthly-run artisan command.',
                'owner' => 'developer',
            ],

            // ── Phase 5 compensation features (default OFF) ──
            'compensation.rank_bonus' => [
                'class' => RankBonusFeature::class,
                'label' => 'Rank Bonus (Phase 5)',
                'description' => 'Enables the 21% rank bonus pool split across 9 ranks (Silver → Elite Diamond). Paid monthly on the 8th. Requires rank qualification engine and 1+2 rule tracking.',
                'owner' => 'developer',
            ],
            'compensation.lifetime_awards' => [
                'class' => LifetimeAwardsFeature::class,
                'label' => 'Lifetime Awards & Rewards (Phase 5)',
                'requires' => ['compensation.rank_bonus'],
                'description' => 'Non-cash rewards triggered on rank achievement (32% of turnover, non-cash). Tracks award delivery workflow for cars, insurance, trips. Requires perquisite tax verification before release.',
                'owner' => 'developer',
            ],

            // ── Phase 6 compensation features (default OFF) ──
            'compensation.fortune_bonus' => [
                'class' => FortuneBonusFeature::class,
                'label' => 'Fortune Bonus (Phase 6)',
                'requires' => ['compensation.genos_sales_bonus', 'compensation.rank_bonus'],
                'description' => 'Enables the 3×9 monthly matrix bonus (replaces Auto Pool). Participation-based, first-come-first-served placement. Monthly reset. Capped at Rank 5.',
                'owner' => 'developer',
            ],

            // ── Phase 7 compensation features (default OFF) ──
            'commerce.purchase_offers' => [
                'class' => PurchaseOffersFeature::class,
                'label' => 'Purchase offers (half-price product + redeem points)',
                'description' => 'Enables the two offers for distributors who hold no rank: one company-announced product at half the distributor price in a month they repurchased the qualifying volume, and redeem points for a six-month purchase streak (one point = one rupee off a future purchase). Both hang entirely off the distributor\'s own purchases — the "joining" trigger in the original spec was dropped, since an offer earned by joining would break hard rules 1 and 2. OFF leaves no trace. Gates: the DSA 6.2 notice, the effective date on /p/compensation 11.2, and KP confirming the two readings in R-47.',
                'owner' => 'developer',
                'requires' => [],
            ],
            'compensation.franchise' => [
                'class' => FranchiseFeature::class,
                'label' => 'Franchise programme (fulfilment network)',
                'description' => 'Enables the franchise register, the collection-point picker at checkout, and the monthly 3% fulfilment commission. A franchise is a company-owned pickup point operated by a distributor: company consignment stock, sales still online and ADN-attributed, franchise code separate from the ADN and never in the Genos. OFF leaves no trace anywhere. Two gates before production: the DSA 6.2 thirty-day notice (it adds an earning stream to the plan), and R-24, a written counsel opinion on the combined binary-tree plus franchise surface.',
                'owner' => 'developer',
                'requires' => [],
            ],
            'compensation.arete_development_center_bonus' => [
                'class' => AreteDevelopmentCenterBonusFeature::class,
                'label' => 'Arete Development Center Bonus (Phase 7)',
                'description' => 'Enables 3% BV-based bonus for official Arete Development Centers, capped at ₹1 lakh/month per center. Paid on the 8th. Requires center assignment and approved center records.',
                'owner' => 'developer',
            ],
        ];
    }

    /**
     * May this viewer see and toggle a flag with the given owner?
     * Incident controls are reachable by the whole console; everything else
     * belongs to platform configuration.
     */
    private function canUse(?User $viewer, string $owner): bool
    {
        if ($viewer === null) {
            return false;
        }

        return $owner === 'incident' || $viewer->hasRole('developer');
    }

    public function index(Request $request): View
    {
        $viewer = $request->user();

        $flags = [];
        foreach ($this->registry() as $key => $meta) {
            if (! $this->canUse($viewer, $meta['owner'])) {
                continue;
            }

            $flags[$key] = $meta + [
                // Read against the global (null) scope so admins see the same
                // state that unauthenticated registration visitors see, not
                // an accidental admin-scoped override from before the fix.
                'active' => Feature::for(null)->active($meta['class']),
            ];
        }

        return view('admin.feature-flags.index', ['flags' => $flags]);
    }

    public function toggle(Request $request, string $key): RedirectResponse
    {
        $registry = $this->registry();
        abort_unless(isset($registry[$key]), 404);
        // A flag this viewer doesn't own is a 404, matching the response an
        // unknown key gets — probing can't confirm it exists.
        abort_unless($this->canUse($request->user(), $registry[$key]['owner']), 404);

        $class = $registry[$key]['class'];
        // Read against global scope (null) — without this, Pennant defaults to
        // the current authenticated user, so the admin saw their own override
        // instead of the global state that registration (unauthenticated) sees.
        $before = Feature::for(null)->active($class);
        $action = $request->input('action');
        abort_unless(in_array($action, ['activate', 'deactivate'], true), 422);

        // Admin-toggleable flags must affect ALL users, including unauthenticated
        // visitors on the registration wizard. Pennant defaults to the currently
        // authenticated user's scope; without `for(null)` we'd store an override
        // scoped to the admin alone, leaving the global default untouched.
        if ($action === 'activate') {
            Feature::for(null)->activate($class);
        } else {
            Feature::for(null)->deactivate($class);
        }

        $after = Feature::for(null)->active($class);

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'feature_flag.toggled',
            'subject_type' => 'feature_flag',
            'subject_id' => null,
            'details' => [
                'flag' => $key,
                'class' => $class,
                'from' => $before,
                'to' => $after,
            ],
            'ip' => $request->ip(),
        ]);

        return back()->with('status', sprintf('Feature %s set to %s.', $key, $after ? 'active' : 'inactive'));
    }
}
