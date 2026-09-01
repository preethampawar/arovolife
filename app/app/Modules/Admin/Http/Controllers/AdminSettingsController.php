<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compensation\Events\CompensationPlanChanged;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AreteCenterApplicationsFeature;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Laravel\Pennant\Feature;

final class AdminSettingsController extends Controller
{
    /**
     * Admin-editable settings registry. Each entry describes how the
     * platform-settings UI should render and validate one key.
     *
     * Setting types:
     *   - 'bool'   : stored as the string 'true' | 'false'
     *   - 'int'    : stored as a decimal integer string; clamped to [min, max]
     *   - 'enum'   : one of the values in `options` (each a ['value', 'label',
     *                'note'] map — a flat list of strings will not render)
     *   - 'string' : free text, truncated to `max` (default 255). The optional
     *                `format` field narrows it: 'email' validates an address,
     *                'date' requires a real YYYY-MM-DD calendar date. Both
     *                also pick the HTML input type.
     *   - 'json'   : free-form JSON; validation is custom (see updateStateAgeMinimums)
     *
     * The 'group' field controls grouping in the friendly admin UI.
     *
     * The 'read_only' flag locks the setting against in-UI edits — used for
     * compensation switches and any key whose change would violate one of the
     * eight hard rules (see CLAUDE.md). Read-only keys still appear in the
     * advanced engineer view so they're discoverable, but the friendly UI
     * surfaces them as informational rows only.
     *
     * The optional 'owner' field overrides the group's default owner for a
     * single key — see ownerForKey().
     *
     * The optional 'feature' field ties the key to a Pennant feature class:
     * while that flag is off the key is invisible on the settings page and a
     * 404 on the update endpoint — a disabled feature leaves no trace.
     *
     * @return array<string, array{
     *   group: string,
     *   owner?: string,
     *   feature?: class-string,
     *   label: string,
     *   description: string,
     *   impact?: string,
     *   type: string,
     *   default?: string,
     *   options?: array<int, array{value: string, label: string, note?: string}>,
     *   min?: int,
     *   max?: int,
     *   format?: string,
     *   read_only?: bool,
     *   read_only_reason?: string,
     * }>
     */
    public static function registry(): array
    {
        return [
            // ── Registration & KYC ─────────────────────────────────────────
            'compliance.state_age_minimums' => [
                'group' => 'registration',
                'label' => 'State-wise minimum age',
                'description' => 'Override the default 18-year minimum registration age for specific states. JSON map of two-letter state code to age (e.g. {"MH":21}).',
                'type' => 'json',
                'default' => '{"MH":21}',
            ],

            // ── Security / rate-limiting ───────────────────────────────────
            'security.registration_throttle_requests' => [
                'group' => 'security',
                'owner' => 'developer',
                'label' => 'Registration rate-limit — max requests',
                'description' => 'Maximum number of registration form submissions (steps 1 and 2) allowed from a single IP address within the configured window. Raise it when distributors onboard many people from the same office or carrier NAT; lower it when you suspect bot activity.',
                'impact' => 'Effective within 5 minutes (cached). Existing blocked IPs are unblocked only when their current window expires.',
                'type' => 'int',
                'min' => 1,
                'max' => 1000,
                'default' => '60',
            ],
            'security.registration_throttle_window_minutes' => [
                'group' => 'security',
                'owner' => 'developer',
                'label' => 'Registration rate-limit — window (minutes)',
                'description' => 'Rolling time window over which the request count is measured. 60 means "60 requests per 60-minute rolling window per IP". Shorten it for tighter burst control; lengthen it to protect against slow floods.',
                'impact' => 'Effective within 5 minutes (cached). Does not retroactively clear existing counters.',
                'type' => 'int',
                'min' => 1,
                'max' => 1440,
                'default' => '60',
            ],

            // ── Tax identity (GST invoicing) ───────────────────────────────
            'tax.seller_gstin' => [
                'group' => 'commerce',
                'owner' => 'developer',
                'label' => 'Company GSTIN',
                'description' => 'The company\'s GST registration number, printed on every tax invoice (CGST Rule 46(b)). **While this is blank the platform issues order receipts, not tax invoices**, and says so on the document — an invoice without a GSTIN is not a tax invoice and no buyer can claim input credit against it.',
                'impact' => 'Setting this turns every subsequent order document into a tax invoice. Do not set it before the registration is real.',
                'type' => 'string',
                'max' => 15,
                'default' => '',
            ],
            'tax.seller_legal_name' => [
                'group' => 'commerce',
                'owner' => 'developer',
                'label' => 'Registered legal name',
                'description' => 'The name the company is registered under, as it must appear on a tax invoice.',
                'type' => 'string',
                'max' => 200,
                'default' => 'Arovolife Private Limited',
            ],
            'tax.seller_state' => [
                'group' => 'commerce',
                'owner' => 'developer',
                'label' => 'Supply-from state code',
                'description' => 'Two-letter state of supply. Decides the CGST+SGST versus IGST split: a place of supply in this state is intra-state, anywhere else is inter-state.',
                'type' => 'string',
                'max' => 2,
                'default' => 'TG',
            ],
            'tax.seller_state_code' => [
                'group' => 'commerce',
                'owner' => 'developer',
                'label' => 'GST state code',
                'description' => 'The numeric GST state code, printed alongside the state name. Telangana is 36.',
                'type' => 'string',
                'max' => 2,
                'default' => '36',
            ],
            'tax.seller_address' => [
                'group' => 'commerce',
                'owner' => 'developer',
                'label' => 'Registered address for invoices',
                'description' => 'The supplier address printed on every tax invoice.',
                'type' => 'string',
                'max' => 500,
                'default' => 'H No 6-51/2, Bank Colony, Pothireddipally, Sangareddy B/s Complex, Sangareddy, Medak — 502001, Telangana, India',
            ],

            // ── Purchase offers ────────────────────────────────────────────
            // Feature-gated: invisible and 404 on update while the offers flag
            // is off, so an unlaunched programme leaves no trace.
            'offers.activation_bv_paise' => [
                'group' => 'compensation_plan',
                'owner' => 'developer',
                'feature' => PurchaseOffersFeature::class,
                'label' => 'Offer activation threshold (BV in paise)',
                'description' => 'Lifetime personal BV a distributor must reach before the half-price product offer applies. 300000 paise = 3,000 BV (the Retailer title). BV is stored at 100 paise to the BV.',
                'type' => 'int',
                'min' => 0,
                'max' => 100_000_000,
                'default' => '300000',
            ],
            'offers.qualifying_bv_paise' => [
                'group' => 'compensation_plan',
                'owner' => 'developer',
                'feature' => PurchaseOffersFeature::class,
                'label' => 'Monthly qualifying volume (BV in paise)',
                'description' => 'Personal BV a distributor must purchase in a month for it to count towards both offers. 100000 paise = 1,000 BV. A refund in the month reduces it, so a returned purchase does not keep a month qualifying.',
                'type' => 'int',
                'min' => 0,
                'max' => 100_000_000,
                'default' => '100000',
            ],
            'offers.cycle_months' => [
                'group' => 'compensation_plan',
                'owner' => 'developer',
                'feature' => PurchaseOffersFeature::class,
                'label' => 'Redeem-point cycle (months)',
                'description' => 'Consecutive qualifying months that complete a cycle and award points. KP specified six. A single month below the threshold resets the streak.',
                'type' => 'int',
                'min' => 1,
                'max' => 24,
                'default' => '6',
            ],
            'offers.cycle_rate_bp' => [
                'group' => 'compensation_plan',
                'owner' => 'developer',
                'feature' => PurchaseOffersFeature::class,
                'label' => 'Redeem points per cycle (basis points of BV)',
                'description' => 'Share of the cycle\'s BV awarded as points, at one point per rupee of BV. 2000 bp = 20%.',
                'impact' => 'Changes what future cycles award. Cycles already granted are unaffected — the points are already in the ledger.',
                'type' => 'int',
                'min' => 0,
                'max' => 10000,
                'default' => '2000',
            ],
            'offers.full_year_bonus_rate_bp' => [
                'group' => 'compensation_plan',
                'owner' => 'developer',
                'feature' => PurchaseOffersFeature::class,
                'label' => 'Full-year bonus (basis points of BV)',
                'description' => 'Extra share of the whole year\'s BV awarded on completing two consecutive cycles. 1000 bp = 10%, added on top of the second cycle\'s award.',
                'type' => 'int',
                'min' => 0,
                'max' => 10000,
                'default' => '1000',
            ],
            'offers.half_price_rate_bp' => [
                'group' => 'compensation_plan',
                'owner' => 'developer',
                'feature' => PurchaseOffersFeature::class,
                'label' => 'Monthly offer price (basis points of DP)',
                'description' => 'What the announced product costs a qualifying distributor, as a share of its distributor price. 5000 bp = 50%.',
                'type' => 'int',
                'min' => 0,
                'max' => 10000,
                'default' => '5000',
            ],
            'offers.half_price_validity_months' => [
                'group' => 'compensation_plan',
                'owner' => 'developer',
                'feature' => PurchaseOffersFeature::class,
                'label' => 'Half-price grant validity (months)',
                'description' => 'How long a half-price grant stays usable beyond the month it was earned in. The engine runs on the 2nd of the FOLLOWING month, so a grant never exists during the month it was earned — 1 is the minimum that leaves it usable at all. Letting it run indefinitely would turn a monthly promotion into a permanent discount.',
                'type' => 'int',
                'min' => 1,
                'max' => 12,
                'default' => '1',
            ],

            // ── Arete Development Centre applications ──────────────────────
            // Feature-gated: invisible and 404 on update while the application
            // flow is off. Admin-owned — operations sets the minimum premises
            // size the client asked for (decision #3, 2026-08-30).
            'adc.min_premises_sqft' => [
                'group' => 'compensation',
                'owner' => 'admin',
                'feature' => AreteCenterApplicationsFeature::class,
                'label' => 'Minimum premises size for an Arete Development Centre (sq ft)',
                'description' => 'The smallest premises a distributor may declare when applying to open an Arete Development Centre. The application form pre-fills its size field with this value and the server rejects anything smaller. Phase 1 of the published development ladder expects 400 sq ft; the default of 350 leaves room for a centre that is still being fitted out.',
                'impact' => 'Applies to applications submitted or resubmitted from now on. Existing applications and approved centres are not re-checked.',
                'type' => 'int',
                'min' => 1,
                'max' => 100000,
                'default' => '350',
            ],
            'adc.max_photo_kb' => [
                'group' => 'compensation',
                'owner' => 'admin',
                'feature' => AreteCenterApplicationsFeature::class,
                'label' => 'Maximum size of one premises photo on an Arete Development Centre application (KB)',
                'description' => 'Each of the five premises photos (inside, front, right, left, approach) must be a JPG or PNG no larger than this. The form shrinks larger phone photos in the browser before uploading, so a small cap is workable.',
                'impact' => 'Applies to photos uploaded from now on. Photos already on file are not re-checked.',
                'type' => 'int',
                'min' => 50,
                'max' => 10240,
                'default' => '500',
            ],

            // ── Termination (agreement §21 dormancy) ───────────────────────
            'termination.inactivity_sweep_enabled' => [
                'group' => 'termination',
                'label' => 'Automatic dormancy termination',
                'description' => 'When OFF the nightly sweep reports what it would do and writes nothing — no notices, no closures. Leave it OFF until the first cohort of dormant accounts has been reviewed by hand: there is no path back from a terminated account, so the first automated run is also the only chance to catch a sales-attribution bug before it closes someone who was in fact selling.',
                'impact' => 'Turning this ON allows the platform to close distributor accounts without a human in the loop. Review the dry-run output first (`php artisan distributors:inactivity-sweep --dry-run`).',
                'type' => 'bool',
                'default' => 'false',
            ],
            'termination.inactivity_months' => [
                'group' => 'termination',
                'owner' => 'developer',
                'label' => 'Dormancy window (months)',
                'description' => 'Continuous months without a sale before an account becomes liable to closure. Agreement §21 says twelve, counted from the effective date or the last sale, whichever is later.',
                'impact' => 'This is a term of the Direct Seller Agreement. Shortening it closes accounts earlier than distributors agreed to; any change needs a §16.2 thirty-day amendment notice.',
                'type' => 'int',
                'min' => 1,
                'max' => 60,
                'default' => '12',
            ],
            'termination.notice_days' => [
                'group' => 'termination',
                'owner' => 'developer',
                'label' => 'Written notice period (days)',
                'description' => 'Days between the §21 notice and closure. One sale inside this window withdraws the notice entirely.',
                'impact' => 'Agreement §21 promises seven days. Reducing it breaches the agreement directly.',
                'type' => 'int',
                'min' => 1,
                'max' => 90,
                'default' => '7',
            ],
            'termination.reregistration_wait_years.ranked' => [
                'group' => 'termination',
                'owner' => 'developer',
                'label' => 'Re-registration wait — ranked (years)',
                'description' => 'Years before a terminated distributor who reached a rank below the Diamond tiers may hold an account again. An unranked distributor carries no wait at all.',
                'type' => 'int',
                'min' => 0,
                'max' => 10,
                'default' => '1',
            ],
            'termination.reregistration_wait_years.diamond' => [
                'group' => 'termination',
                'owner' => 'developer',
                'label' => 'Re-registration wait — Diamond and above (years)',
                'description' => 'Years before a terminated distributor who reached the Diamond tiers may hold an account again.',
                'type' => 'int',
                'min' => 0,
                'max' => 10,
                'default' => '2',
            ],
            'termination.diamond_rank_threshold' => [
                'group' => 'termination',
                'owner' => 'developer',
                'label' => 'Rank at which the longer wait begins',
                'description' => 'Rank number from which the Diamond-tier re-registration wait applies. Default 5 = Diamond Partner. NOTE: §21 was drafted against an older ladder naming "Sales Master" and "Diamond Master", neither of which exists in the current nine ranks — this mapping is an interpretation and needs Product Owner confirmation before launch.',
                'type' => 'int',
                'min' => 1,
                'max' => 9,
                'default' => '5',
            ],

            // ── Grievance redressal ────────────────────────────────────────
            // Every value here is a promise printed at /p/grievance. Editing
            // one without amending that page leaves the software and the
            // published policy saying different things — which is exactly what
            // a regulator looks for. The SLA windows are therefore
            // developer-owned; the mailboxes and the escalation switch, which
            // operations genuinely needs to maintain, are not.
            'grievance.sla.acknowledgement_hours' => [
                'group' => 'grievance',
                'owner' => 'developer',
                'label' => 'Acknowledgement window (hours)',
                'description' => 'Hours within which every grievance must be acknowledged with its complaint number. Published as 48 hours at /p/grievance §2.',
                'impact' => 'Must match the published Grievance Redressal Policy. Raising it weakens a statutory-facing commitment; amend the policy page in the same change.',
                'type' => 'int',
                'min' => 1,
                'max' => 168,
                'default' => '48',
            ],
            'grievance.sla.first_response_working_days' => [
                'group' => 'grievance',
                'owner' => 'developer',
                'label' => 'First-response window (working days)',
                'description' => 'Working days within which a complainant must receive a first substantive response. Working days exclude Sunday only, matching the Monday-to-Saturday helpline. Published as 5 working days.',
                'impact' => 'Must match the published Grievance Redressal Policy §2.',
                'type' => 'int',
                'min' => 1,
                'max' => 30,
                'default' => '5',
            ],
            'grievance.sla.resolution_days' => [
                'group' => 'grievance',
                'owner' => 'developer',
                'label' => 'Resolution window (days)',
                'description' => 'Calendar days within which an ordinary grievance must be resolved. Published as 30 days.',
                'impact' => 'Must match the published Grievance Redressal Policy §2.',
                'type' => 'int',
                'min' => 1,
                'max' => 90,
                'default' => '30',
            ],
            'grievance.sla.third_party_resolution_days' => [
                'group' => 'grievance',
                'owner' => 'developer',
                'label' => 'Third-party resolution window (days)',
                'description' => 'Extended window where a bank, payment gateway, AUA/KUA partner or statutory authority must respond before the matter can be closed. Published as 60 days. Applied once per grievance and never shortens an existing due date.',
                'impact' => 'Must match the published Grievance Redressal Policy §2.',
                'type' => 'int',
                'min' => 1,
                'max' => 180,
                'default' => '60',
            ],
            'grievance.sla.status_update_interval_days' => [
                'group' => 'grievance',
                'owner' => 'developer',
                'label' => 'Progress-update interval (days)',
                'description' => 'How often a complainant must hear from us while a third-party extension runs. Published as every 15 days.',
                'impact' => 'Must match the published Grievance Redressal Policy §2.',
                'type' => 'int',
                'min' => 1,
                'max' => 60,
                'default' => '15',
            ],
            'grievance.retention_years' => [
                'group' => 'grievance',
                'owner' => 'developer',
                'label' => 'Record retention (years)',
                'description' => 'Years a closed grievance record is retained from final closure. DSR 2021 Rule 12 and the published policy §6.2 both say seven.',
                'impact' => 'DANGER: this is a statutory retention floor. Lowering it makes records purgeable earlier than the law allows.',
                'type' => 'int',
                'min' => 1,
                'max' => 25,
                'default' => '7',
            ],
            'grievance.escalation.auto' => [
                'group' => 'grievance',
                'label' => 'Automatic escalation',
                'description' => 'When ON, the hourly SLA sweep moves a grievance up the escalation ladder once it has sat at one step past its window, instead of waiting for the complainant to ask. Turning it OFF does not remove the complainant\'s right to escalate — it only stops us doing it for them.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'grievance.escalation.step_2_after_days' => [
                'group' => 'grievance',
                'label' => 'Escalate to Grievance Officer after (days)',
                'description' => 'Days a grievance may sit with customer care before escalating to the Grievance Officer. Published as 7 days at /p/grievance §4.',
                'type' => 'int',
                'min' => 1,
                'max' => 60,
                'default' => '7',
            ],
            'grievance.escalation.step_3_after_days' => [
                'group' => 'grievance',
                'label' => 'Escalate to Nodal Officer after (days)',
                'description' => 'Days a grievance may sit with the Grievance Officer before escalating to the Nodal Officer. Published as 15 days at /p/grievance §4.',
                'type' => 'int',
                'min' => 1,
                'max' => 90,
                'default' => '15',
            ],
            'grievance.attachment.max_mb' => [
                'group' => 'grievance',
                'label' => 'Maximum evidence file size (MB)',
                'description' => 'Largest single file a complainant may attach as evidence. Published as 10 MB at /p/grievance §3.1.',
                'type' => 'int',
                'min' => 1,
                'max' => 50,
                'default' => '10',
            ],
            'grievance.attachment.max_count' => [
                'group' => 'grievance',
                'label' => 'Maximum evidence files per submission',
                'description' => 'How many files a complainant may attach in one go. They can attach more later by replying to the grievance.',
                'type' => 'int',
                'min' => 1,
                'max' => 20,
                'default' => '5',
            ],
            'grievance.mailbox.customer_care' => [
                'group' => 'grievance',
                'label' => 'Customer care mailbox',
                'description' => 'Receives every new grievance at escalation step 1. Must be a real, monitored inbox before launch.',
                'type' => 'string',
                'format' => 'email',
                'max' => 255,
                'default' => 'care@arovolife.com',
            ],
            'grievance.mailbox.grievance_officer' => [
                'group' => 'grievance',
                'label' => 'Grievance Officer mailbox',
                'description' => 'Step 2 of the escalation matrix, and the first owner of every ethics and privacy complaint. Published at /p/grievance §1.',
                'type' => 'string',
                'format' => 'email',
                'max' => 255,
                'default' => 'grievance@arovolife.com',
            ],
            'grievance.mailbox.nodal_officer' => [
                'group' => 'grievance',
                'label' => 'Nodal Officer mailbox',
                'description' => 'Step 3 of the escalation matrix. Published at /p/grievance §1.',
                'type' => 'string',
                'format' => 'email',
                'max' => 255,
                'default' => 'nodal@arovolife.com',
            ],
            'grievance.mailbox.compliance_committee' => [
                'group' => 'grievance',
                'label' => 'Compliance Committee mailbox',
                'description' => 'Step 4, the last internal step. Beyond it the complainant approaches a statutory authority directly. Published at /p/grievance §1.',
                'type' => 'string',
                'format' => 'email',
                'max' => 255,
                'default' => 'compliance@arovolife.com',
            ],
            'grievance.mailbox.dpo' => [
                'group' => 'grievance',
                'label' => 'Data Protection Officer mailbox',
                'description' => 'Receives every Privacy & data grievance, whatever escalation step it sits at. Published as the DPO contact at /p/grievance §1 and in the Privacy Policy; DPDP Act 2023 §13(3) requires that published route to work.',
                'type' => 'string',
                'format' => 'email',
                'max' => 255,
                'default' => 'dpo@arovolife.com',
            ],

            // ── Placement (Genos) ──────────────────────────────────────────
            'placement.spillover.enabled' => [
                'group' => 'placement',
                'label' => 'Binary spillover',
                'description' => 'When ON, a joiner invited under a node whose slot is already full is auto-placed into the next open position below it (standard binary spillover, ADR-0007). When OFF, a full placement is rejected and the joiner is asked to use a different placement (ADR-0003). Default OFF. NOTE: enabling this changes how downlines grow and therefore future commission distribution — get PO + Compliance sign-off before turning it on in production.',
                'type' => 'bool',
                'default' => 'false',
            ],
            'placement.spillover.strategy' => [
                'group' => 'placement',
                'label' => 'Spillover fill strategy',
                'description' => 'How spillover fills the chosen leg (only applies when Binary spillover is ON). Breadth-balanced: shallowest open slot, fills level-by-level (keeps the leg even). Depth (outer edge): rides one deep edge down the chosen side. Weaker leg: places under the sub-leg with fewer members (balances by headcount). Default: Breadth-balanced. Like Binary spillover, this affects downline composition — changing it in production requires the same PO + Compliance sign-off.',
                'type' => 'enum',
                'default' => 'breadth_balanced',
                'options' => [
                    ['value' => 'breadth_balanced', 'label' => 'Breadth-balanced (shallowest open slot)', 'note' => 'Fills the chosen leg level-by-level — each new joiner takes the shallowest open position. Keeps the leg even and bushy.'],
                    ['value' => 'depth_outer', 'label' => 'Depth — outer edge (one deep leg)', 'note' => 'Rides one long edge straight down the chosen side, building a single deep leg (depth over width).'],
                    ['value' => 'weaker_leg', 'label' => 'Weaker leg (fewer members)', 'note' => 'Places under whichever sub-leg currently has fewer members, balancing the leg by headcount.'],
                ],
            ],

            'genealogy.downline_stats_visible' => [
                'group' => 'placement',
                'owner' => 'developer',
                'label' => 'Show downline rank and personal BV on tree cards',
                'description' => 'When ON, every Genos / Direct-referral card and the Details popup show that distributor\'s Highest Rank, Current Rank and accumulated Personal BV to their sponsor, their upline and admins (client decision 2026-08-30, risk register R-65). When OFF those rows are filled only on the viewer\'s own card and read "—" everywhere else. Personal-purchase title and withdrawal income are never shown to others regardless of this switch.',
                'impact' => 'Turning this ON discloses one distributor\'s purchase volume and rank to other distributors. Do not enable until the amended Privacy Policy §4/§7 and DSA §8.5 have been published and the 30-day DPDP §5 / DSA §16.2 notice has elapsed.',
                'type' => 'bool',
                'default' => 'false',
            ],

            // ── Commerce — storefront ──────────────────────────────────────
            'commerce.storefront.enabled' => [
                'group' => 'commerce',
                'label' => 'Public storefront',
                'description' => 'Show the public product storefront to visitors. Turn this off to take the catalogue down without removing the app.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'commerce.checkout.enabled' => [
                'group' => 'commerce',
                'label' => 'Storefront checkout',
                'description' => 'Allow customers to complete checkout on the storefront. Turn this off to keep the catalogue browsable but pause new orders.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'commerce.guest_checkout.enabled' => [
                'group' => 'commerce',
                'label' => 'Guest checkout',
                'description' => 'Allow customers to check out without creating an account. Turn off to require sign-in before placing an order.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'commerce.shipping.india_mainland_only' => [
                'group' => 'commerce',
                'label' => 'Mainland India shipping only',
                'description' => 'Restrict orders to mainland Indian addresses. Turn off to accept orders for the Andaman & Nicobar islands, Lakshadweep, etc.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'commerce.orders.daily_cap_per_customer' => [
                'group' => 'commerce',
                'label' => 'Daily order limit per customer',
                'description' => 'Maximum number of orders a single customer can place in 24 hours. Anti-fraud guard; raise carefully.',
                'type' => 'int',
                'min' => 1,
                'max' => 100,
                'default' => '5',
            ],
            'commerce.shipping.fee_rupees' => [
                'group' => 'commerce',
                'label' => 'Shipping fee (₹)',
                'description' => 'Flat shipping charge (in whole rupees) applied to orders below the free-shipping threshold.',
                'type' => 'int',
                'min' => 0,
                'max' => 100000,
                'default' => '60',
            ],
            'commerce.shipping.free_threshold_rupees' => [
                'group' => 'commerce',
                'label' => 'Free-shipping threshold (₹)',
                'description' => 'Cart value (in whole rupees) at or above which shipping is free. Default ₹4000.',
                'type' => 'int',
                'min' => 0,
                'max' => 10000000,
                'default' => '4000',
            ],

            // ── Commerce — attribution ─────────────────────────────────────
            'commerce.attribution.window_days' => [
                'group' => 'attribution',
                'label' => 'Referral attribution window (days)',
                'description' => 'How many days a referral link remains attached to a visitor for commission attribution. Default 30.',
                'type' => 'int',
                'min' => 1,
                'max' => 365,
                'default' => '30',
            ],
            'commerce.attribution.logged_in_overrides_ref' => [
                'group' => 'attribution',
                'label' => 'Logged-in distributor beats referral cookie',
                'description' => 'When a logged-in distributor checks out, their own ADN is attributed even if a different referral cookie is set.',
                'type' => 'bool',
                'default' => 'true',
            ],

            // ── Cooling-off ────────────────────────────────────────────────
            'commerce.cooling_off.days' => [
                'group' => 'cooling_off',
                'label' => 'Cooling-off period (days)',
                'description' => 'How long after delivery a customer can cancel for a full refund. Statutory floor is 30 days; the law does not permit a shorter window.',
                'type' => 'int',
                // 30 is the statutory minimum (DSR 2021 / T&C §4). UI cannot
                // accept values below 30; the controller revalidates.
                'min' => 30,
                'max' => 365,
                'default' => '30',
            ],

            // ── Self-purchase ──────────────────────────────────────────────
            'commerce.self_purchase.earns_bv' => [
                'group' => 'self_purchase',
                'label' => 'Self-purchase earns BV',
                'description' => "When ON, a distributor's own product purchases count toward their Business Volume (BV).",
                'type' => 'bool',
                'default' => 'true',
            ],
            'commerce.self_purchase.earns_retail_margin' => [
                'group' => 'self_purchase',
                'label' => 'Self-purchase earns retail margin',
                'description' => "When ON, a distributor's own product purchases earn the retail margin component. Default OFF — self-purchase typically only contributes BV, not retail margin.",
                'type' => 'bool',
                'default' => 'false',
            ],

            // ── Compensation switches (read-only in UI) ────────────────────
            'compensation.accrual.enabled' => [
                'group' => 'compensation',
                'label' => 'Compensation: accrual engine',
                'description' => 'Master switch for accruing commissions on product sales. Phase 4+ only; do not enable from this UI.',
                'type' => 'bool',
                'default' => 'false',
                'read_only' => true,
                'read_only_reason' => 'Compensation accrual is gated by phase-rollout review (see CLAUDE.md hard rule 2). Toggle this via a controlled migration or admin tinker session only.',
            ],
            'compensation.unlock.enabled' => [
                'group' => 'compensation',
                'label' => 'Compensation: unlock engine',
                'description' => 'Master switch for unlocking matured commissions for payout. Phase 4+ only.',
                'type' => 'bool',
                'default' => 'false',
                'read_only' => true,
                'read_only_reason' => 'Compensation unlock is gated by phase-rollout review (see CLAUDE.md hard rule 2).',
            ],
            'compensation.payout.enabled' => [
                'group' => 'compensation',
                'label' => 'Compensation: payout engine',
                'description' => 'Master switch for actually paying out matured commissions. Phase 4+ only.',
                'type' => 'bool',
                'default' => 'false',
                'read_only' => true,
                'read_only_reason' => 'Compensation payout is gated by phase-rollout review (see CLAUDE.md hard rule 2).',
            ],

            // ── Compliance / operations ────────────────────────────────────
            'compliance.crawler.enabled' => [
                'group' => 'advanced',
                'label' => 'Compliance crawler',
                'description' => 'When ON, the automated crawler scans distributor public posts for compliance violations. Default OFF — manual review for Phase 2.',
                'type' => 'bool',
                'default' => 'false',
            ],

            // ── Notifications ──────────────────────────────────────────────
            'notifications.email_on_status_change' => [
                'group' => 'notifications',
                'label' => 'Email on every order status change',
                'description' => 'When ON, the buyer is emailed each time their order moves status (paid, shipped, delivered, …). The order-received email is always sent regardless of this toggle.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'notifications.admin_order_email' => [
                'group' => 'notifications',
                'label' => 'Admin new-order alert email',
                'description' => 'Mailbox that receives a "new order placed" alert for every order. Leave blank to turn admin alerts off. Set this to a real monitored inbox before launch (placeholder default).',
                'type' => 'string',
                'format' => 'email',
                'max' => 255,
                'default' => 'orders@arovolife.com',
            ],

            // ── Payout configuration ───────────────────────────────────────
            'payout.min_threshold_paise' => [
                'group' => 'payout',
                'label' => 'Payout: minimum payout threshold (paise)',
                'description' => 'Wallet balances below this amount roll over to the next payout batch. 10000 = ₹100 (KP-confirmed minimum). Must be a positive integer.',
                'type' => 'int',
                'min' => 1,
                'max' => 10000000,
                'default' => '10000',
            ],
            'payout.neft_min_bv_paise' => [
                'group' => 'payout',
                'label' => 'NEFT eligibility — minimum personal BV (paise)',
                'description' => 'Lifetime personal BV a distributor must reach for wallet credits to transfer via NEFT. Below this threshold credits are held as web-only balance. Default 300,000 paise = 3,000 BV (Retailer title).',
                'impact' => 'Raising this delays NEFT payouts for more distributors; lowering it unlocks NEFT for more distributors earlier. Takes effect from the next payout batch run.',
                'type' => 'int',
                'min' => 1,
                'max' => 100_000_000,
                'default' => '300000',
            ],

            // ── Compensation plan — rates, caps & periods ──────────────────
            // KP-confirmed parameters. Rates are stored as integer basis points
            // (5% = 500) because this registry has no float type;
            // CompensationPlanSettingsService divides by 10,000. The GSB slab
            // ladder, rank tiers and Fortune tables are edited on the dedicated
            // /admin/compensation/plan-settings page, not here.
            'comp.admin_charge.rate_bp' => [
                'group' => 'compensation_plan',
                'label' => 'Admin charge rate (basis points)',
                'description' => 'Admin charge applied to all seven bonus streams (GSB, Mentorship, Rank, Growth Booster, Fortune, ADC, Lifetime Awards) — per the applies-to toggles below. 300 = 3%. Stored in basis points (100 bp = 1%).',
                'impact' => 'Raising this reduces every distributor\'s net bonus. Takes effect from the next engine run.',
                'type' => 'int',
                'min' => 0,
                'max' => 10000,
                'default' => '300',
            ],
            'comp.admin_charge.weekly_cap_paise' => [
                'group' => 'compensation_plan',
                'label' => 'Admin charge cap — weekly batch (paise)',
                'description' => 'Maximum admin charge per distributor in each weekly payout batch (Group A: GSB + Mentorship). 2500000 = ₹25,000 (KP 2026-06-30 Round-5).',
                'impact' => 'Raising this lets the weekly batch deduct more admin charge before capping. Takes effect from the next weekly payout run.',
                'type' => 'int',
                'min' => 0,
                'max' => 1_000_000_000,
                'default' => '2500000',
            ],
            'comp.admin_charge.monthly_cap_paise' => [
                'group' => 'compensation_plan',
                'label' => 'Admin charge cap — monthly batch (paise)',
                'description' => 'Maximum admin charge per distributor per bonus group in each monthly payout batch (Groups B/C/D: GBB, Rank, Fortune, Awards, ADC — each group capped independently). 2500000 = ₹25,000 (KP 2026-06-30 Round-5).',
                'impact' => 'Raising this lets each monthly bonus group deduct more admin charge before capping. Takes effect from the next monthly payout run.',
                'type' => 'int',
                'min' => 0,
                'max' => 1_000_000_000,
                'default' => '2500000',
            ],
            'comp.monthly_income_cap_paise' => [
                'group' => 'compensation_plan',
                'label' => 'Monthly income cap (paise)',
                'description' => 'Per-distributor ₹50 lakh/month income cap (KP Round-4). In the monthly payout batch, Rank-bonus income above this is trimmed to the cap. 500000000 = ₹50,00,000.',
                'impact' => 'Lowering this trims high earners\' monthly rank income sooner. Takes effect from the next monthly payout run.',
                'type' => 'int',
                'min' => 0,
                'max' => 100_000_000_000,
                'default' => '500000000',
            ],
            // Admin-charge scope — KP Q&A 2026-06-27: applies to all 7 bonuses.
            // Each toggle exempts one stream when turned OFF.
            'comp.admin_charge.applies_to_gsb' => [
                'group' => 'compensation_plan',
                'feature' => GenosSalesBonusFeature::class,
                'label' => 'Admin charge: apply to Genos Sales Bonus',
                'description' => 'When ON, the admin charge is deducted from GSB payouts. KP-confirmed ON.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'comp.admin_charge.applies_to_mb' => [
                'group' => 'compensation_plan',
                'feature' => MentorshipBonusFeature::class,
                'label' => 'Admin charge: apply to Mentorship Bonus',
                'description' => 'When ON, the admin charge is deducted from Mentorship Bonus payouts. KP-confirmed ON.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'comp.admin_charge.applies_to_rank' => [
                'group' => 'compensation_plan',
                'feature' => RankBonusFeature::class,
                'label' => 'Admin charge: apply to Rank Bonus',
                'description' => 'When ON, the admin charge is deducted from Rank Bonus payouts. KP-confirmed ON.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'comp.admin_charge.applies_to_gbb' => [
                'group' => 'compensation_plan',
                'feature' => GrowthBoosterBonusFeature::class,
                'label' => 'Admin charge: apply to Growth Booster Bonus',
                'description' => 'When ON, the admin charge is deducted from Growth Booster Bonus payouts. KP-confirmed ON.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'comp.admin_charge.applies_to_fortune' => [
                'group' => 'compensation_plan',
                'feature' => FortuneBonusFeature::class,
                'label' => 'Admin charge: apply to Fortune Bonus',
                'description' => 'When ON, the admin charge is deducted from Fortune Bonus payouts. KP-confirmed ON.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'comp.admin_charge.applies_to_adc' => [
                'group' => 'compensation_plan',
                'feature' => AreteDevelopmentCenterBonusFeature::class,
                'label' => 'Admin charge: apply to Arete Development Center Bonus',
                'description' => 'When ON, the admin charge is deducted from ADC payouts. KP-confirmed ON (2026-06-27; previously exempt).',
                'type' => 'bool',
                'default' => 'true',
            ],
            'comp.admin_charge.applies_to_awards' => [
                'group' => 'compensation_plan',
                'feature' => LifetimeAwardsFeature::class,
                'label' => 'Admin charge: apply to Lifetime Awards & Rewards',
                'description' => 'When ON, the admin charge is deducted from Lifetime Awards. Default OFF: KP confirmed (2026-06-27 Round-2 Q6) that non-cash awards (gifts/goods) carry NO admin charge or TDS — only awards released as cash/cheque are charged. The Lifetime Awards payout engine ships in a later phase; turn this ON only if a cash award component is paid.',
                'type' => 'bool',
                'default' => 'false',
            ],
            'comp.tds.rate_bp' => [
                'group' => 'compensation_plan',
                'label' => 'TDS rate (basis points)',
                'description' => 'Tax deducted at source on bonuses. 500 = 5%. Verify the current Income Tax rate before changing.',
                'type' => 'int',
                'min' => 0,
                'max' => 10000,
                'default' => '500',
            ],
            'comp.gsb.pool_rate_bp' => [
                'group' => 'compensation_plan',
                'feature' => GenosSalesBonusFeature::class,
                'label' => 'GSB daily pool rate (basis points)',
                'description' => 'Share of the day\'s company-wide BV funding the GSB pool. Slabs 1–2 are paid fixed from it first; the remainder sets the pro-rated slab 3–7 score value (capped at the fixed score value). 4500 = 45%.',
                'impact' => 'Changes the daily variable score value for GSB slabs 3–7. Takes effect from the next daily cut-off.',
                'type' => 'int',
                'min' => 0,
                'max' => 10000,
                'default' => '4500',
            ],
            'comp.gsb.min_bv_paise' => [
                'group' => 'compensation_plan',
                'feature' => GenosSalesBonusFeature::class,
                'label' => 'Personal-BV minimum for income (BV paise)',
                'description' => 'Lifetime personal purchase BV a distributor must reach before Genos BV accrues to them at all. 60000 = 600 BV (KP 2026-07 §14). Below this, downline BV is not added to their account; from 600 BV income accrues but stays web-only until the 3,000 BV Retailer title releases it to bank.',
                'impact' => 'Changes who earns and who dilutes the GSB and MSB daily pools — sub-minimum distributors are excluded from the denominator. Takes effect from the next daily cut-off.',
                'type' => 'int',
                'min' => 0,
                'max' => 100_000_000_000,
                'default' => '60000',
            ],
            'comp.gsb.topup_golive_date' => [
                'group' => 'compensation_plan',
                'feature' => GenosSalesBonusFeature::class,
                'label' => 'Personal-BV top-up go-live date',
                'description' => 'Accruals dated before this are excluded from the conditional personal-BV top-up pool — the pre-2026-07-21 engine already credited them daily, so counting them again would double-credit. Pinned to the deploy date by GsbSlabsSeeder.',
                'impact' => 'DANGER: moving this date backwards pulls accruals into the top-up pool that the pre-2026-07-21 engine already credited daily, double-crediting them. Moving it forwards silently drops pending top-ups. Change it only alongside a reviewed data migration.',
                'type' => 'string',
                'format' => 'date',
                'default' => '1970-01-01',
            ],
            'comp.gsb.power_cf_cap_paise' => [
                'group' => 'compensation_plan',
                'feature' => GenosSalesBonusFeature::class,
                'label' => 'GSB power-side carry-forward cap (BV paise)',
                'description' => 'Maximum BV carried forward on the stronger Genos side after a bonus. 45000000 = 4,50,000 BV. Excess is flushed.',
                'type' => 'int',
                'min' => 0,
                'max' => 100_000_000_000,
                'default' => '45000000',
            ],
            'comp.msb.pool_rate_bp' => [
                'group' => 'compensation_plan',
                'feature' => MentorshipBonusFeature::class,
                'label' => 'MSB daily pool rate (basis points)',
                'description' => 'Share of the day\'s company-wide BV funding the Mentorship Bonus pool. The pool is divided by the day\'s total MSB score points to give one point value for every earner, floored to whole rupees. 300 = 3%.',
                'impact' => 'Changes what every Mentorship Bonus earner is paid. Takes effect from the next daily cut-off.',
                'type' => 'int',
                'min' => 0,
                'max' => 10000,
                'default' => '300',
            ],
            'comp.gbb.pool_rate_bp' => [
                'group' => 'compensation_plan',
                'feature' => GrowthBoosterBonusFeature::class,
                'label' => 'Growth Booster pool rate (basis points)',
                'description' => 'Share of monthly company turnover funding the Growth Booster Bonus pool. 500 = 5%.',
                'type' => 'int',
                'min' => 0,
                'max' => 10000,
                'default' => '500',
            ],
            'comp.gbb.agp_cap' => [
                'group' => 'compensation_plan',
                'feature' => GrowthBoosterBonusFeature::class,
                'label' => 'Growth Booster AGP cap per distributor',
                'description' => 'Maximum Arovolife Growth Points a single distributor can earn in a month. Default 120.',
                'type' => 'int',
                'min' => 1,
                'max' => 100000,
                'default' => '120',
            ],
            'comp.rank.envelope_bp' => [
                'group' => 'compensation_plan',
                'feature' => RankBonusFeature::class,
                'label' => 'Rank Bonus envelope (basis points)',
                'description' => 'Share of monthly company BV set aside for all nine Rank Bonus pools together. 2000 = 20%. Each rank\'s percentage is a share of THIS envelope, not of BV directly — the nine rank percentages sum to 100% of the envelope (20% of BV). Example: 10,00,000 BV → 2,00,000 envelope → Rank 1 (7%) = ₹14,000.',
                'impact' => 'Rescales every rank pool. Takes effect from the next monthly Rank Bonus run; already-credited months are untouched.',
                'type' => 'int',
                'min' => 0,
                'max' => 10000,
                'default' => '2000',
            ],
            'comp.rank.pay_highest_rank_only' => [
                'group' => 'compensation_plan',
                'feature' => RankBonusFeature::class,
                'label' => 'Rank pools: pay highest rank only',
                'description' => 'ON (exclusive): a distributor who cleared several ranks in a month is paid only their highest — the plan-text reading ("reaching Rank 2 cancels the Rank-1 benefit"). OFF (cumulative): they are paid from every rank pool whose bar they cleared. Also changes each pool\'s denominator, so it re-prices every achiever in the affected pools.',
                'impact' => 'Changes who is paid from which rank pool and how each pool divides. Takes effect from the next monthly Rank Bonus run; already-credited months are untouched. Awaiting the product owner\'s exclusive-vs-cumulative ruling — change only on that ruling.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'comp.rank.aogo_points' => [
                'group' => 'compensation_plan',
                'feature' => RankBonusFeature::class,
                'label' => 'AO-GO points per grant',
                'description' => 'Points a degraded ex-rank-holder earns in the Rank-1 pool under the Achieve Once – Get Once offer. Default 5 (Rank-1 achievers earn 10 RAP).',
                'impact' => 'Changes every AO-GO grantee\'s share of the Rank-1 pool. Takes effect from the next monthly Rank Bonus run.',
                'type' => 'int',
                'min' => 0,
                'max' => 1000,
                'default' => '5',
            ],
            'comp.rank.aogo_lifetime_max' => [
                'group' => 'compensation_plan',
                'feature' => RankBonusFeature::class,
                'label' => 'AO-GO lifetime grants per distributor',
                'description' => 'Maximum number of times one distributor can use the Achieve Once – Get Once offer, ever. Default 3.',
                'impact' => 'Applies from the next monthly Rank Bonus run; already-used grants keep counting.',
                'type' => 'int',
                'min' => 0,
                'max' => 100,
                'default' => '3',
            ],
            'comp.adc.rate_bp' => [
                'group' => 'compensation_plan',
                'feature' => AreteDevelopmentCenterBonusFeature::class,
                'label' => 'Arete Development Center bonus rate (basis points)',
                'description' => 'Rate on BV served by a center. 300 = 3%. ADC is exempt from the admin charge.',
                'type' => 'int',
                'min' => 0,
                'max' => 10000,
                'default' => '300',
            ],
            'comp.adc.cap_paise' => [
                'group' => 'compensation_plan',
                'feature' => AreteDevelopmentCenterBonusFeature::class,
                'label' => 'Arete Development Center monthly cap (paise)',
                'description' => 'Maximum ADC bonus per center per month. 10000000 = ₹1,00,000.',
                'type' => 'int',
                'min' => 0,
                'max' => 10_000_000_000,
                'default' => '10000000',
            ],
            'comp.repurchase.rate_bp' => [
                'group' => 'compensation_plan',
                'feature' => RepurchaseEngineFeature::class,
                'label' => 'Repurchase wallet rate (basis points)',
                'description' => 'Share of prior-month GSB + MB + GBB + Fortune + Rank bonuses moved to the repurchase wallet. 1000 = 10%.',
                'type' => 'int',
                'min' => 0,
                'max' => 10000,
                'default' => '1000',
            ],
            'comp.repurchase.cap_paise' => [
                'group' => 'compensation_plan',
                'feature' => RepurchaseEngineFeature::class,
                'label' => 'Repurchase wallet cap (paise)',
                'description' => 'Maximum repurchase-wallet deduction per payout. 1000000 = ₹10,000.',
                'type' => 'int',
                'min' => 0,
                'max' => 1_000_000_000,
                'default' => '1000000',
            ],
            'comp.repurchase.grace_days' => [
                'group' => 'compensation_plan',
                'feature' => RepurchaseEngineFeature::class,
                'label' => 'Repurchase grace period (days)',
                'description' => 'Days after the repurchase due date during which income is calculated but held before suspension. 0 = immediate suspension on missed due date.',
                'type' => 'int',
                'min' => 0,
                'max' => 90,
                'default' => '0',
            ],
            'comp.repurchase.non_ranked_bv_paise' => [
                'group' => 'compensation_plan',
                'feature' => RepurchaseEngineFeature::class,
                'label' => 'Repurchase BV — non-ranked (paise)',
                'description' => 'Monthly repurchase BV a distributor with no rank must complete to stay income-eligible. 60000 = 600 BV. Per-rank values (R1 1,000 … R9 2,300) are edited on the Compensation → Plan settings page.',
                'type' => 'int',
                'min' => 0,
                'max' => 1_000_000_000,
                'default' => '60000',
            ],
            'comp.fortune.pool_rate_bp' => [
                'group' => 'compensation_plan',
                'feature' => FortuneBonusFeature::class,
                'label' => 'Fortune Bonus pool rate (basis points)',
                'description' => 'Share of the month\'s company-wide BV funding the Fortune Bonus pool. The pool distributes through the level cascade: ₹30 minimum per qualifier, then per-level point values with per-level caps (edited on Compensation → Plan settings → Fortune). 500 = 5%.',
                'impact' => 'Changes what every Fortune Bonus participant is paid. Takes effect from the next monthly run; months already frozen in fortune_monthly_pools are untouched.',
                'type' => 'int',
                'min' => 0,
                'max' => 10000,
                'default' => '500',
            ],
            'comp.fortune.min_commission_paise' => [
                'group' => 'compensation_plan',
                'feature' => FortuneBonusFeature::class,
                'label' => 'Fortune Bonus minimum commission (paise)',
                'description' => 'Guaranteed amount every Fortune Bonus qualifier receives, reserved off the pool before the level cascade distributes. Level caps INCLUDE this minimum. 3000 = ₹30 (KP 2026-08-09). If a month\'s pool cannot cover it, every qualifier gets the same pro-rated whole-rupee share instead.',
                'impact' => 'Changes the guaranteed floor of every Fortune Bonus participant and the pool left for point earnings. Takes effect from the next monthly run; frozen months are untouched.',
                'type' => 'int',
                'min' => 0,
                'max' => 1_000_000,
                'default' => '3000',
            ],
            'comp.fortune.exclude_rank_6' => [
                'group' => 'compensation_plan',
                'feature' => FortuneBonusFeature::class,
                'label' => 'Fortune Bonus: exclude Rank 6 (Blue Diamond)',
                'description' => 'When ON, Rank 6 distributors are ineligible for the Fortune Bonus. KP-confirmed ON.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'comp.fortune.exclude_rank_7' => [
                'group' => 'compensation_plan',
                'feature' => FortuneBonusFeature::class,
                'label' => 'Fortune Bonus: exclude Rank 7 (Royal Diamond)',
                'description' => 'When ON, Rank 7 distributors are ineligible for the Fortune Bonus. KP-confirmed ON.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'comp.fortune.exclude_rank_8' => [
                'group' => 'compensation_plan',
                'feature' => FortuneBonusFeature::class,
                'label' => 'Fortune Bonus: exclude Rank 8 (Crown Diamond)',
                'description' => 'When ON, Rank 8 distributors are ineligible for the Fortune Bonus. KP-confirmed ON.',
                'type' => 'bool',
                'default' => 'true',
            ],
            'comp.fortune.exclude_rank_9' => [
                'group' => 'compensation_plan',
                'feature' => FortuneBonusFeature::class,
                'label' => 'Fortune Bonus: exclude Rank 9 (Elite Diamond)',
                'description' => 'When ON, Rank 9 distributors are ineligible for the Fortune Bonus. KP-confirmed ON.',
                'type' => 'bool',
                'default' => 'true',
            ],

            // ── Payments ───────────────────────────────────────────────────
            'payments.gateway.razorpay.enabled' => [
                'group' => 'payments',
                'label' => 'Payments: Razorpay gateway',
                'description' => 'Route checkouts through Razorpay. Requires keys in the environment; do not enable without the finance team.',
                'type' => 'bool',
                'default' => 'false',
            ],
            'payments.gateway.stub.enabled' => [
                'group' => 'payments',
                'label' => 'Payments: stub gateway (dev)',
                'description' => 'Accept payments via the in-process stub. Useful for local development; never enable on production.',
                'type' => 'bool',
                'default' => 'true',
            ],
        ];
    }

    /**
     * Which role owns each settings GROUP by default.
     *
     * `admin` owns the day-to-day business levers; `developer` owns anything
     * that changes what the platform pays, who earns a sale, a statutory term,
     * or how the system itself behaves. Developer-owned keys are not rendered
     * at all for non-developer viewers (the stealth rule — an admin must not
     * learn that a higher role exists), and writes are refused server-side.
     *
     * @var array<string, string>
     */
    private const GROUP_OWNERS = [
        'registration' => 'admin',
        'commerce' => 'admin',
        // Operations maintains the officer mailboxes and the escalation switch;
        // the published SLA windows carry their own 'owner' => 'developer'.
        'grievance' => 'admin',
        // Operations owns the master switch; the §21 windows are developer-owned.
        'termination' => 'admin',
        'placement' => 'developer',
        'attribution' => 'developer',
        'cooling_off' => 'developer',
        'self_purchase' => 'developer',
        'compensation' => 'developer',
        'compensation_plan' => 'developer',
        'payments' => 'developer',
        'payout' => 'developer',
        'notifications' => 'developer',
        'advanced' => 'developer',
    ];

    /**
     * Role that may read and write the given setting key.
     *
     * Ownership is per KEY: a registry entry may carry its own `owner` field,
     * which wins over its group's default. Moving a single setting between
     * admin and developer is therefore a one-line registry edit — no group
     * reshuffle, no migration. Unknown keys default to the stricter role.
     */
    public static function ownerForKey(string $key): string
    {
        $meta = self::registry()[$key] ?? null;

        if ($meta === null) {
            return 'developer';
        }

        if (isset($meta['owner']) && is_string($meta['owner'])) {
            return $meta['owner'];
        }

        return self::GROUP_OWNERS[$meta['group']] ?? 'developer';
    }

    /**
     * Keys a business admin must be able to SEE even though they cannot edit
     * them: the statutory cooling-off term and the deduction/threshold values
     * that determine what reaches a distributor's bank account. Compliance
     * review depends on being able to verify these are set correctly —
     * visibility is the control here, not write access.
     *
     * @var list<string>
     */
    private const ADMIN_READABLE_KEYS = [
        'commerce.cooling_off.days',
        'comp.tds.rate_bp',
        'comp.admin_charge.rate_bp',
        'payout.min_threshold_paise',
        'payout.neft_min_bv_paise',
        'comp.gsb.min_bv_paise',
    ];

    /** May the given user WRITE settings owned by $owner? */
    private static function userOwns(?User $user, string $owner): bool
    {
        if ($user === null) {
            return false;
        }

        return $owner === 'admin' || $user->hasRole('developer');
    }

    /** May the given user SEE this key at all (editable or read-only)? */
    private static function userCanRead(?User $user, string $key): bool
    {
        return self::userOwns($user, self::ownerForKey($key))
            || in_array($key, self::ADMIN_READABLE_KEYS, true);
    }

    /**
     * Friendly labels for the section grouping.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function groups(): array
    {
        return [
            'registration' => [
                'label' => 'Registration & KYC',
                'description' => 'Rules that govern who can register and on what terms.',
            ],
            'security' => [
                'label' => 'Security & rate-limiting',
                'description' => 'Throttle limits and other security tunables. Developer-owned — changes here take effect within 5 minutes without a deploy.',
            ],
            'placement' => [
                'label' => 'Placement (Genos)',
                'description' => 'How new joiners are positioned in the binary placement tree.',
            ],
            'commerce' => [
                'label' => 'Commerce',
                'description' => 'Storefront, checkout, shipping and order-flow controls.',
            ],
            'termination' => [
                'label' => 'Termination (dormancy)',
                'description' => 'The agreement §21 rule that closes an account after twelve continuous months without a sale, the seven-day notice that must precede it, and the re-registration wait afterwards. The master switch ships OFF.',
            ],
            'grievance' => [
                'label' => 'Grievance redressal',
                'description' => 'SLA windows, escalation clocks and officer mailboxes for the complaint tracker. Every value here is printed at /p/grievance — change one and the policy page must be amended in the same breath.',
            ],
            'attribution' => [
                'label' => 'Referral attribution',
                'description' => 'How sales get tied to a sponsor for commission credit.',
            ],
            'cooling_off' => [
                'label' => 'Cooling-off',
                'description' => 'Statutory 30-day cancel-for-refund window. Cannot be set below 30.',
            ],
            'self_purchase' => [
                'label' => 'Self-purchase',
                'description' => 'How a distributor\'s own purchases contribute to their volume and earnings.',
            ],
            'compensation' => [
                'label' => 'Compensation engine',
                'description' => 'Master switches for the Phase 4+ commission engine. Read-only from this UI.',
            ],
            'compensation_plan' => [
                'label' => 'Compensation plan — rates, caps & periods',
                'description' => 'Tunable bonus rates, caps and periods (KP-confirmed). The GSB slab ladder, rank tiers and Fortune tables are edited on the Compensation → Plan settings page. Changes are audit-logged and take effect on the next engine run.',
            ],
            'payments' => [
                'label' => 'Payments',
                'description' => 'Payment methods offered at checkout and the gateways that process them.',
            ],
            'payout' => [
                'label' => 'Payout configuration',
                'description' => 'Rules for the weekly and monthly bank transfer batches.',
            ],
            'notifications' => [
                'label' => 'Notifications',
                'description' => 'Transactional emails (and, later, SMS) sent to buyers about their orders.',
            ],
            'advanced' => [
                'label' => 'Other / Advanced',
                'description' => 'Lower-traffic operations switches.',
            ],
        ];
    }

    public function index(Request $request): View
    {
        $viewer = $request->user();
        $rows = DB::table('settings')->orderBy('key')->get()->keyBy('key');

        // Drop keys this viewer may not see, server-side, so the response body
        // carries no trace of them. Keys on the admin-readable list survive
        // and render read-only below — statutory and deduction values must
        // stay verifiable by whoever reviews compliance. Keys of a disabled
        // feature disappear for every role.
        $registry = array_filter(
            self::registry(),
            fn (array $meta, string $key): bool => self::userCanRead($viewer, $key)
                && self::featureVisible($meta),
            ARRAY_FILTER_USE_BOTH,
        );

        // Build a grouped view-model. Each group only renders if it has at
        // least one matching setting, so removing a key from the registry
        // doesn't leave behind an empty section header.
        $grouped = [];
        foreach (self::groups() as $groupKey => $groupMeta) {
            $grouped[$groupKey] = [
                'meta' => $groupMeta,
                'items' => [],
            ];
        }

        foreach ($registry as $key => $meta) {
            $row = $rows[$key] ?? null;
            $rawValue = $row->value ?? ($meta['default'] ?? '');

            // Visible-but-not-mine renders through the existing read-only
            // path, with a reason that states the fact without naming who
            // does own it.
            if (! self::userOwns($viewer, self::ownerForKey($key))) {
                $meta['read_only'] = true;
                $meta['read_only_reason'] = 'Shown for reference. This value is not editable from this console; changes are made by the platform team and appear in the audit log.';
            }

            $grouped[$meta['group']]['items'][] = [
                'key' => $key,
                'meta' => $meta,
                'value' => $rawValue,
                'version' => (int) ($row->version ?? 0),
            ];
        }

        // Drop empty groups so the page doesn't show stale headers.
        $grouped = array_filter($grouped, fn (array $g): bool => $g['items'] !== []);

        return view('admin.settings.index', [
            // The legacy engineer table dumps every raw settings row, so it is
            // developer-only — it would otherwise leak the hidden keys. Rows
            // belonging to a disabled feature are dropped here too.
            'settings' => $viewer?->hasRole('developer')
                ? $rows->filter(fn (object $row, string $key): bool => self::featureVisible(self::registry()[$key] ?? []))
                : collect(),
            'grouped' => $grouped,         // for the friendly view
            'registry' => $registry,
        ]);
    }

    /**
     * Whether a registry entry's feature flag (if any) allows it to be shown
     * or written. Entries without a 'feature' field are always visible.
     *
     * @param  array{feature?: class-string}  $meta
     */
    private static function featureVisible(array $meta): bool
    {
        $feature = $meta['feature'] ?? null;

        return $feature === null || Feature::for(null)->active($feature);
    }

    /**
     * Generic per-setting update endpoint used by the friendly UI. Each
     * setting card posts {value: ...} here; we validate against the
     * registry entry, write the value + bump the version, and append an
     * audit-log row.
     */
    public function update(Request $request, string $key): RedirectResponse
    {
        $registry = self::registry();
        abort_unless(isset($registry[$key]), 404);

        // Stealth + authorization: a developer-owned key is a 404 for anyone
        // else — the same response an unknown key gets, so probing the
        // endpoint can't confirm that the key (or the role) exists. A key
        // whose feature flag is off gets the same 404.
        abort_unless(self::userOwns($request->user(), self::ownerForKey($key)), 404);
        abort_unless(self::featureVisible($registry[$key]), 404);

        $meta = $registry[$key];

        if (! empty($meta['read_only'])) {
            // Read-only settings must not be writable via the UI even if a
            // hand-crafted POST sneaks through. This protects compensation
            // switches per CLAUDE.md hard rule 2.
            abort(403, 'This setting is read-only from the admin UI.');
        }

        // JSON-typed settings have a dedicated handler (with custom
        // validation, e.g. state code regex + age-range bounds).
        if ($meta['type'] === 'json') {
            if ($key === 'compliance.state_age_minimums') {
                return $this->updateStateAgeMinimums($request);
            }
            abort(400, 'No handler for JSON-typed setting: '.$key);
        }

        $error = null;
        $value = $this->normalizeIncomingValue($request, $meta, $error);
        if ($error !== null) {
            return redirect()->route('admin.settings')
                ->withErrors(['value' => $error])
                ->with('saved_key', $key);
        }

        // Cross-table invariant: every capped Fortune level's per-member cap
        // INCLUDES the minimum commission, so the minimum can never exceed the
        // lowest active capped-level cap. The level form enforces the same
        // invariant from the other side (AdminPlanSettingsController).
        if ($key === 'comp.fortune.min_commission_paise') {
            $lowestCap = DB::table('fortune_bonus_levels')
                ->where('payout_mode', 'capped')
                ->where('is_active', true)
                ->whereNotNull('cap_paise')
                ->min('cap_paise');

            if ($lowestCap !== null && (int) $value > (int) $lowestCap) {
                return redirect()->route('admin.settings')
                    ->withErrors(['value' => 'The minimum commission cannot exceed the lowest capped Fortune level cap ('.$lowestCap.' paise) — every cap includes the minimum.'])
                    ->with('saved_key', $key);
            }
        }

        $this->persistSetting($key, $value, $request);

        return redirect()->route('admin.settings')
            ->with('status', "Updated: {$meta['label']}.")
            ->with('saved_key', $key);
    }

    /**
     * Normalize the incoming `value` field according to the registry entry.
     * Returns the canonical string to persist. Out-of-range / type errors
     * are reported via the by-ref $error so the caller can redirect back
     * with errors instead of throwing through abort().
     *
     * @param  array{type: string, options?: array<int, array{value: string, label: string}>, min?: int, max?: int, format?: string, label: string}  $meta
     */
    private function normalizeIncomingValue(Request $request, array $meta, ?string &$error = null): string
    {
        switch ($meta['type']) {
            case 'bool':
                // Accept HTML checkbox semantics ("on" / missing) AND
                // explicit "true"/"false" strings from the toggle button.
                $raw = $request->input('value');
                if ($raw === null) {
                    return 'false';
                }
                $rawStr = is_string($raw) ? strtolower($raw) : (string) $raw;
                $truthy = in_array($rawStr, ['1', 'true', 'on', 'yes'], true);

                return $truthy ? 'true' : 'false';

            case 'int':
                $raw = $request->input('value');
                if (! is_numeric($raw) || ((int) $raw) != $raw) { // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators
                    $error = "{$meta['label']} must be a whole number.";

                    return '';
                }
                $n = (int) $raw;
                $min = $meta['min'] ?? PHP_INT_MIN;
                $max = $meta['max'] ?? PHP_INT_MAX;
                if ($n < $min || $n > $max) {
                    $error = "{$meta['label']} must be between {$min} and {$max}.";

                    return '';
                }

                return (string) $n;

            case 'enum':
                $raw = $request->input('value');
                $allowed = array_map(fn ($o): string => $o['value'], $meta['options'] ?? []);
                if (! is_string($raw) || ! in_array($raw, $allowed, true)) {
                    $error = "{$meta['label']}: invalid choice.";

                    return '';
                }

                return $raw;

            case 'string':
                $raw = trim((string) $request->input('value', ''));
                // An empty value is allowed (e.g. clears/disables the setting).
                $format = $meta['format'] ?? null;
                if ($raw !== '' && $format === 'email' && ! filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                    $error = "{$meta['label']} must be a valid email address (or left blank).";

                    return '';
                }
                if ($raw !== '' && $format === 'date') {
                    // Reject anything Carbon would silently coerce ("next
                    // tuesday", "2026-02-31"): a plan boundary must round-trip
                    // to exactly the Y-m-d string the engines compare against.
                    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
                    if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
                        $error = "{$meta['label']} must be a calendar date in YYYY-MM-DD format.";

                        return '';
                    }
                }

                return mb_substr($raw, 0, (int) ($meta['max'] ?? 255));

            default:
                $error = 'Unsupported setting type: '.$meta['type'];

                return '';
        }
    }

    private function persistSetting(string $key, string $value, Request $request): void
    {
        // Read-update-or-insert. We previously used upsert(..., version =>
        // DB::raw('version + 1')), but the raw expression is non-portable
        // across drivers (MySQL evaluates `version + 1` against the
        // existing row in the UPDATE branch; SQLite's ON CONFLICT DO
        // UPDATE references the excluded.version literal and explodes).
        // A small fetch + branch is portable and the table is single-row
        // per key so there's no hot-path concern.
        $existing = DB::table('settings')->where('key', $key)->first();
        $old = $existing->value ?? null;

        if ($existing === null) {
            DB::table('settings')->insert([
                'key' => $key,
                'value' => $value,
                'version' => 1,
                'updated_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('settings')
                ->where('key', $key)
                ->update([
                    'value' => $value,
                    'version' => ((int) ($existing->version ?? 0)) + 1,
                    'updated_by' => auth()->id(),
                    'updated_at' => now(),
                ]);
        }

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'admin.settings.changed',
            'subject_type' => 'settings',
            'subject_id' => null,
            'details' => [
                'key' => $key,
                'before' => $old,
                'after' => $value,
            ],
            'ip' => $request->ip(),
        ]);

        // Compensation-plan scalars also emit the domain event (like the plan
        // table editors do) so listeners can bust caches / snapshot the version.
        // Dispatched after persistence + audit so a listener fault can't roll
        // back the admin's change.
        if (str_starts_with($key, 'comp.') || str_starts_with($key, 'payout.')) {
            $actorId = auth()->id();
            event(new CompensationPlanChanged(
                area: 'scalar',
                key: $key,
                actorId: $actorId !== null ? (int) $actorId : null,
            ));
        }

        // Security throttle settings are cached for 5 minutes in the named rate
        // limiter. Clear the cache immediately on update so the new limit takes
        // effect on the next request rather than waiting out the window.
        if (str_starts_with($key, 'security.registration_throttle')) {
            Cache::forget('settings.registration_throttle');
        }
    }

    public function updateStateAgeMinimums(Request $request): RedirectResponse
    {
        // The form posts a JSON string in 'state_age_minimums' (textarea).
        // We parse + revalidate every value as a sane integer (16..30) so a
        // typo can't accidentally allow children or block the entire country.
        //
        // Note: the new friendly UI posts to /admin/settings/{key} with
        // 'value', so we accept either field name to keep the legacy
        // endpoint working.
        $payload = $request->input('state_age_minimums', $request->input('value'));
        $validated = validator(
            ['state_age_minimums' => $payload],
            ['state_age_minimums' => ['required', 'string', 'max:2048']]
        )->validate();

        $decoded = json_decode($validated['state_age_minimums'], true);
        if (! is_array($decoded)) {
            return back()->withInput()->withErrors([
                'state_age_minimums' => 'Must be a valid JSON object mapping state codes to ages.',
            ]);
        }

        foreach ($decoded as $stateCode => $age) {
            if (! is_string($stateCode) || ! preg_match('/^[A-Z]{2}$/', $stateCode)) {
                return back()->withInput()->withErrors([
                    'state_age_minimums' => "Invalid state code: '{$stateCode}'. Use two-letter uppercase codes (e.g. MH).",
                ]);
            }
            if (! is_int($age) || $age < 16 || $age > 30) {
                return back()->withInput()->withErrors([
                    'state_age_minimums' => "Minimum age for {$stateCode} must be an integer between 16 and 30 (got: ".json_encode($age).').',
                ]);
            }
        }

        // Canonical key order so audit-log diffs and downstream string
        // comparisons don't see false changes when admins re-save the same
        // logical map.
        ksort($decoded);
        $canonical = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        if ($canonical === false) {
            return back()->withInput()->withErrors([
                'state_age_minimums' => 'Could not re-encode the JSON map.',
            ]);
        }

        $existing = DB::table('settings')->where('key', 'compliance.state_age_minimums')->first();
        $old = $existing->value ?? null;

        if ($existing === null) {
            DB::table('settings')->insert([
                'key' => 'compliance.state_age_minimums',
                'value' => $canonical,
                'version' => 1,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('settings')
                ->where('key', 'compliance.state_age_minimums')
                ->update([
                    'value' => $canonical,
                    'version' => ((int) ($existing->version ?? 0)) + 1,
                    'updated_by' => auth()->id(),
                    'updated_at' => now(),
                ]);
        }

        AuditLog::create([
            'actor_id' => auth()->id(),
            'action' => 'admin.settings.state_age_minimums.changed',
            'subject_type' => 'settings',
            'subject_id' => null,
            'details' => ['before' => $old, 'after' => $canonical],
            'ip' => $request->ip(),
        ]);

        return redirect()->route('admin.settings')
            ->with('status', 'State-age minimums updated. New registrations are validated against the new rule.')
            ->with('saved_key', 'compliance.state_age_minimums');
    }
}
