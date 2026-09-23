# Policy pages, Home/About merge, admin Genos star, Fortune Bonus dashboard card — Implementation Plan

Date: 2026-09-23 · Branch: `feat/policy-pages-home-fb-card` (from `main`)
Paths below are relative to `arovolife-code/app/` unless they start with `docs/`.

## Goal

1. The nine client policy documents in `~/Downloads/arovolife-policies/` are live as separate public content pages, in the client's wording. Shipping has its own page, and the production seeder creates all of them from the Markdown source instead of placeholders.
2. The Home (`landing/index`) and About (`landing/about`) pages include the content of `~/Downloads/Home &About pages Content.docx`, merged into the existing sections. Nothing existing is removed; the About "Compliance & trust" section is not touched.
3. Staff viewing `/admin/tree` always see the red/yellow/green purchase star on every card. The distributor tree still follows `genealogy.purchase_mark_visible`.
4. The distributor dashboard has one Fortune Bonus card showing, for this month, qualified / not yet qualified with a checklist of gates, and for last month the matrix level, points, status and net amount credited.

## Non-goals

- No change to the compensation engines' behaviour. `enrollEligible()` gets a mechanical refactor only, with identical output.
- No reconciliation of the client documents with platform behaviour. The conflicts are listed in the appendix for the client.
- The `compensation` page stays held (R-75). It is not touched.
- Distributors do not get the star on downline cards unless the setting is ON (compliance stop, hard rule 3 / R-65).
- The `99%` / "fairest" / "never a better decade" claims already on the About page are not removed (instruction: "do not remove the existing information"). They are flagged in the appendix.
- No Fortune Bonus ₹ projection, no "you will earn" copy.

## Architecture decisions

| Decision | Alternatives | Why |
|---|---|---|
| Client docs become the `.md` source under `database/seeders/content/`, rendered by the existing `ContentPageSeeder` | Admin UI paste; new table | Single source of truth already exists, and consent hashing (`ConsentDocuments`) reads the published body. |
| Wording is verbatim. The only fixes: collapse double spaces, spell the brand `arovolife` (legal name stays `Arovolife Private Limited`), fix heading typos (`Police`→`Policy`, `Agreenent`→`Agreement`, `Processor`→`Procedures`, `Steat`→`State`), and rebuild tables as Markdown tables | Editorial cleanup | It is legal text; changing wording is the client's job. |
| **Privacy page = client text + the existing DPDP notice appended as "Part B"** | Client text only | The client doc drops sections the platform depends on: §4.5b (message moderation disclosure, which Messaging requires before reports are ON), §5 retention (messages 24 months, KYC scans 8 years per R-31), Aadhaar handling (hard rule 8), and the notice R-65 relies on. Appending keeps "Privacy Notice §4.5b / §5" references valid. **Compliance stop on the client-only version (DPDP Act 2023 §5, hard rule 8).** |
| Shipping moves out of `returns.md` §6 into a new `shipping` page, with the facts unchanged | Keep in returns | User choice; the new returns doc has no shipping. |
| `returns` is removed from `HELD_SLUGS` | Keep held | User choice "publish now"; the client's new text is the F35 reconciliation. |
| New slugs: `shipping`, `disclaimer`, `social-media`, `policies` | — | `policies` = "Policies & Procedures — Website Terms of Use" (the doc's §21). The Disclaimer cites its "No Warranties" and "Information Disclaimer" clauses. |
| `ProductionSeeder` delegates the statutory pages to a new `ContentPageSeeder::createMissing()` | Keep placeholders | "Seed it for production too." Create-if-missing keeps the ProductionSeeder invariant (never mutate existing rows). |
| Staff star: `DistributorIdCardStats::visibleIds()` returns every canvas id in `mark` when the viewer `isStaff()` | Tie to the setting | User choice. Staff already see personal BV on the admin distributor page, so nothing new is disclosed. |
| Fortune qualification reuses the engine's own builders with an optional `?int $distributorId` filter, and one shared `evaluateGates()` | Re-implement the gates in a dashboard service | Single source of truth: the card can never disagree with the engine. |

## Permission matrix

| Capability | Guest | Distributor (own data) | Distributor (downline data) | Staff (developer/admin/admin-operations/admin-finance/admin-compliance) |
|---|---|---|---|---|
| View `/content/{terms,ethics,privacy,grievance,returns,shipping,disclaimer,social-media,policies}` | ✅ | ✅ | — | ✅ |
| View Home / About | ✅ | ✅ | — | ✅ |
| Purchase star on `/admin/tree` cards | ❌ 302 login | ❌ 403 (existing `role:` middleware) | ❌ | ✅ every card, setting ignored |
| Purchase star on `/tree` downline cards | ❌ | own card ✅ | only when `genealogy.purchase_mark_visible` = ON (unchanged) | n/a |
| Fortune Bonus dashboard card | ❌ 302 login | ✅ own only, and only when `FortuneBonusFeature` is active (zero trace when OFF) | ❌ never | ❌ (staff have no distributor block) |
| `php artisan content:publish <slugs>` | CLI only (ops) | — | — | — |

Layers: route middleware is unchanged for all routes. Service: `visibleIds()` decides the star by the viewer's role, never by the view. The FB card is built only from `auth()->user()->distributor->id` inside `DashboardController`. UI: `@if($fortuneCard)`.

## File changes

| # | Path | New/Mod | Summary |
|---|---|---|---|
| 1 | `database/seeders/content/terms.md` | Mod | Replace with the DSA / Terms & Conditions doc |
| 2 | `database/seeders/content/ethics.md` | Mod | Replace with the Code of Ethics and Principles doc |
| 3 | `database/seeders/content/privacy.md` | Mod | Part A = client Privacy Policy; Part B = the current file's body, unchanged |
| 4 | `database/seeders/content/grievance.md` | Mod | Replace with the Grievance Redressal Policy doc |
| 5 | `database/seeders/content/returns.md` | Mod | Replace with the Product Return / Warranty & Guarantee doc |
| 6 | `database/seeders/content/shipping.md` | New | Current `returns.md` §6 (6.1–6.4), verbatim |
| 7 | `database/seeders/content/disclaimer.md` | New | Disclaimer doc |
| 8 | `database/seeders/content/social-media.md` | New | Social Media Policy doc |
| 9 | `database/seeders/content/policies.md` | New | "Polices and Processor" doc (§21 Website Terms of Use) |
| 10 | `database/seeders/ContentPageSeeder.php` | Mod | PAGES meta; `HELD_SLUGS = ['compensation']`; `createMissing()`; docblocks |
| 11 | `database/seeders/ProductionSeeder.php` | Mod | Statutory pages via `createMissing()`; placeholders only for the nav pages |
| 12 | `resources/views/landing/index.blade.php` | Mod | Footer Legal links + doc content merge (Slice 3) |
| 13 | `resources/views/landing/about.blade.php` | Mod | Footer links + doc content merge (Slice 3) |
| 14 | `resources/views/layouts/shop.blade.php` | Mod | Footer: Shipping, Disclaimer links |
| 15 | `tests/Modules/Content/ReturnsPolicyPageTest.php` | Mod | F35-01 now asserts published; F35-02 shipping assertions move to #16 |
| 16 | `tests/Modules/Content/PolicyPagesTest.php` | New | Every slug has a source, all but `compensation` publish, ProductionSeeder create-if-missing, privacy keeps §4.5b |
| 17 | `app/Modules/Identity/Services/DistributorIdCardStats.php` | Mod | `visibleIds()`: staff → mark = all ids |
| 18 | `tests/Modules/Identity/DistributorIdCardStatsTest.php` | Mod | Two tests (staff sees, distributor still gated) |
| 19 | `app/Modules/Compensation/Services/DTOs/FortuneQualification.php` | New | Live-month gate verdict |
| 20 | `app/Modules/Compensation/Services/DTOs/FortuneDashboardCard.php` | New | This month + last month |
| 21 | `app/Modules/Compensation/Services/FortuneBonusService.php` | Mod | Builders get `?int $distributorId`; `evaluateGates()`; `dashboardCardFor()` |
| 22 | `app/Modules/Identity/Http/Controllers/DashboardController.php` | Mod | Build `$fortuneCard` |
| 23 | `resources/views/dashboard/_fortune-bonus.blade.php` | New | The card |
| 24 | `resources/views/dashboard/index.blade.php` | Mod | Include after `_income-snapshot` |
| 25 | `tests/Modules/Compensation/FortuneBonusQualificationTest.php` | New | Gate verdicts, last month, engine parity |
| 26 | `tests/Feature/DashboardFortuneCardTest.php` | New | Card shown when flag ON, zero trace when OFF |
| 27 | `tests/Browser/policy-pages.spec.js` | New | Playwright |
| 28 | `tests/Browser/home-about-content.spec.js` | New | Playwright |
| 29 | `tests/Browser/admin-genos-star.spec.js` | New | Playwright |
| 30 | `tests/Browser/dashboard-fortune-card.spec.js` | New | Playwright |
| 31 | `docs/compliance/policy-docs-conflicts-2026-09-23.md` | New | Appendix A as a standalone doc for the client |
| 32 | `../CLAUDE.md` (repo root `arovolife-code/CLAUDE.md`) | Mod | Hard rule 3 amendment: one sentence on the staff star |
| 33 | `resources/help/distributor-communications.md` | Mod | "Privacy Notice §4.5b" → "Privacy Policy Part B §4.5b" |

### Source documents (Slice 1a/1b)

Plain-text conversions are already in the session scratchpad. Regenerate them with:
`textutil -convert txt -output /tmp/x.txt "<docx>"` (macOS). For tables, `textutil -convert html` gives the cell structure.

| Slug | Source docx (in `~/Downloads/arovolife-policies/`) | Page title | meta_description |
|---|---|---|---|
| terms | `Direct seller Agreenent  and TERMS & CONDITIONS.docx` | Direct Seller Agreement & Terms and Conditions | The agreement between Arovolife Private Limited and its Direct Sellers — eligibility, free joining, the 30-day cooling-off period, duties, buy-back, sales-channel restrictions, grievance redressal and termination. |
| ethics | `Code of Ethics and Principles.docx` | Code of Ethics and Principles | The principles every arovolife Direct Seller follows — fair recruitment, honest product and earnings representation, customer duties and the company's obligations. |
| privacy | `Privacy Policy.docx` + current `privacy.md` | Privacy Policy | How arovolife collects, uses, stores, shares and protects personal data, including the DPDP Act 2023 notice, KYC and Aadhaar handling, and retention periods. |
| grievance | `Grievance redressal Police.docx` | Grievance Redressal Policy | How to register a complaint with arovolife, the Grievance Redressal Committee and the timelines for acknowledgement, root-cause analysis and redressal. |
| returns | `Product Return:Warranty & Guaranty  Police .docx` | Product Return, Warranty & Guarantee Policy | When and how products can be returned to arovolife, the buy-back conditions, refund timelines and the impact on BV. |
| shipping | current `returns.md` §6 | Shipping & Delivery | Where arovolife delivers, shipping charges, dispatch and delivery times, and who pays for return shipping. |
| disclaimer | `Disclaimer.docx` | Disclaimer | Health, product-information and availability disclaimers for the arovolife website. |
| social-media | `Social Media Policy.docx` | Social Media Policy | How arovolife Direct Sellers may use social media to talk about arovolife products and the business, with examples of good practice and breaches. |
| policies | `Polices and Processor.docx` | Policies & Procedures — Website Terms of Use | The terms governing use of the arovolife website — eligibility, registration, intellectual property, payments, cookies, liability and governing law. |

Conversion rules (every file):
- No leading `<!-- … -->` banner.
- `##` for top-level numbered clauses (`## 4. Joining & Cooling Off Period`), `###` for sub-clauses and lettered heads. Keep the document's own numbering; Markdown numbered lists must not renumber (write `4\.` or use headings).
- Bullets `•` → `-`. Roman/lettered items stay as written (`- a. …`).
- Tables (terms §8, returns §4, grievance §4, returns "Steps involved" contact table) become Markdown tables. Where a merged cell spans rows, repeat its value in each row. Example for terms §8:

```md
| Category | Condition of product | Period | Invoice | Payment |
|---|---|---|---|---|
| During cooling-off period | Saleable | 30 days | Yes | Direct Seller Price |
| General buy-back / repurchase; upon termination of agreement | Saleable | 30 days | No | Direct Seller Price less GST (GST will be deducted) |
| Received in damaged condition | Saleable | 10 days | Yes | Direct Seller Price |
| Received in damaged condition | Non-saleable | 10 days | No | Direct Seller Price less GST (GST will be deducted) |
| Not completely satisfied with product quality | Saleable | 30 days | Yes | Direct Seller Price |
| Not completely satisfied with product quality | Non-saleable | 30 days | No | Direct Seller Price less GST (GST will be deducted) |
```
  If the docx cell layout differs from this reading, follow the docx (use the `-convert html` output) and note it in the report.
- terms: drop the trailing signature block (`Declaration … Name / Signatures / Date / Place`) and replace it with the one sentence `By accepting this Agreement electronically during registration, the Direct Seller confirms they have read and accept these Terms and Conditions.` Acceptance is electronic, and a blank signature form on a web page is meaningless. The `(web address)` placeholder in definition c) becomes `www.arovolife.com`.
- privacy: `# Part A — Privacy Policy` (client text; its `22.` prefix dropped), then `---`, then `# Part B — Privacy Notice under the Digital Personal Data Protection Act, 2023` followed by the **current** `privacy.md` body verbatim, minus its banner comment. Section numbers inside Part B do not change.
- policies: drop the `21.` prefix and keep the sub-clauses A–U.
- shipping: short intro `This page explains where and how arovolife delivers orders. For returns, refunds and buy-back see the [Product Return, Warranty & Guarantee Policy](/content/returns).` then the current `returns.md` `## 6. Shipping` block with headings renumbered `## 1. Where we deliver`, `## 2. Shipping charges`, `## 3. Dispatch and delivery`, `## 4. Return shipping`. Text unchanged.
- Contact figures stay exactly as in the docs (`+91 88866 62949`, `support@arovolife.com`, the Sangareddy address).
- Everything must render through `Str::markdown()`, so no raw HTML.

### #10 `ContentPageSeeder.php`

- `PAGES`: update title/meta for terms, ethics, privacy, grievance and returns per the table above, and append entries for `shipping`, `disclaimer`, `social-media`, `policies` (in that order).
- `HELD_SLUGS = ['compensation'];` Rewrite that docblock: keep the R-75 paragraph and delete the `returns` paragraph, replacing it with `returns was held for QA F35 until 2026-09-23, when the client supplied the Product Return, Warranty & Guarantee Policy.`
- Class docblock: "Seeds the six public content pages" → "Seeds the ten public content pages", listing the slugs.
- New method, placed after `seedAsDraft()`:

```php
    /**
     * Create every page that does not exist yet — published, except the held
     * slugs, which are created as drafts — and never touch one that does.
     *
     * The production path (ProductionSeeder): a fresh environment gets the
     * real documents rather than placeholders, and a re-run can never
     * overwrite a page somebody has since edited or published by name.
     *
     * @return int the number of pages created
     */
    public function createMissing(): int
    {
        $created = $this->seedAsDraft(self::HELD_SLUGS);
        $now = now();

        foreach (self::PAGES as $meta) {
            if (in_array($meta['slug'], self::HELD_SLUGS, true)
                || ContentPage::query()->where('slug', $meta['slug'])->exists()) {
                continue;
            }

            ContentPage::create([
                'slug' => $meta['slug'],
                'title' => $meta['title'],
                'meta_description' => $meta['meta_description'],
                'body' => $this->renderBody($meta['slug']),
                'status' => ContentPage::STATUS_PUBLISHED,
                'published_at' => $now,
            ]);

            $created++;
        }

        return $created;
    }
```

### #11 `ProductionSeeder.php` → `seedContentPages()`

- Remove `terms`, `privacy`, `ethics`, `grievance` from `$pages`. The three nav placeholders stay.
- First line of the method: `$created = (new ContentPageSeeder)->createMissing();` then `$this->command?->info("Policy pages created from markdown: {$created}.");`. Add `use Database\Seeders\ContentPageSeeder;` only if the namespace differs; it is the same namespace, so no import.
- Method docblock → "Statutory policy pages come from `database/seeders/content/*.md` via ContentPageSeeder::createMissing(); nav pages still get placeholder copy. Once a slug exists this seeder leaves it alone forever."
- Class docblock invariant 4 → "Policy pages are seeded from the Markdown sources (the client's documents, 2026-09-23). The About-menu nav pages are placeholders — edit them through the admin Content Pages UI; this seeder will not overwrite either on subsequent runs."

### #12–#14 footers

- `landing/index.blade.php` footer "Legal" column (around lines 675–687): keep the existing links and the `isSlugPublished('returns')` guard pattern. Change the returns label to `Returns &amp; warranty`. After it add, each guarded the same way by `\App\Modules\Content\Models\ContentPage::isSlugPublished('<slug>')`: `Shipping &amp; delivery` (shipping), `Disclaimer` (disclaimer), `Social media policy` (social-media), `Website terms of use` (policies). Update the comment above the returns link: it is published now, and the guard stays so a missing row never becomes a 404.
- `landing/about.blade.php` footer link row (around lines 412–416): after `Grievance Redressal` add `Returns & warranty`, `Shipping & delivery`, `Disclaimer`, guarded the same way, using the same `<a>` classes as their neighbours.
- `layouts/shop.blade.php` (lines 42–46): rename the returns label to `Returns &amp; warranty` and add `Shipping` and `Disclaimer` links in the same style, same guard.

### #15/#16 content tests

- `ReturnsPolicyPageTest` F35-01 → `it('F35-01: a blanket seed publishes the returns policy', …)` asserting status published. F35-02: replace its assertions with phrases from the new doc: `Buyback/Repurchase conditions`, `Within 7 days after receipt of material in warehouse`, `Direct Seller Price less GST`. F35-03: the footer shows the link when published and hides it when the row is a draft (flip the row to draft inside the test).
- `PolicyPagesTest` (Pest, same bootstrap as `ReturnsPolicyPageTest`):
  - POL-01: every `ContentPageSeeder::slugs()` entry has `database/seeders/content/<slug>.md`.
  - POL-02: after `ContentPageSeeder` runs, every slug except `compensation` is published and `GET /content/<slug>` returns 200 with the title.
  - POL-03: `ProductionSeeder` on an empty `content_pages` creates all nine policy pages published, with a body not containing `placeholder`.
  - POL-04: `ProductionSeeder` with an existing edited `terms` row leaves its body unchanged.
  - POL-05: the rendered privacy body contains `Part B` and `Moderation of reported messages` (§4 item 5b — the page never contained the literal string "4.5b").
  - POL-06: the shipping body contains `mainland India` and the returns body does not contain `## 6. Shipping`.

### #17 `DistributorIdCardStats::visibleIds()`

Replace the body after `$ownOnly` with:

```php
        $statsOn = $this->downlineStatsVisible();
        $markOn = $this->purchaseMarkVisible();
        // Staff see the purchase mark on every card they can open (the admin
        // Genos). The switch exists for the R-65 downline audience — a
        // distributor looking at other distributors — and staff already see
        // personal BV on the admin distributor page. Stats rows stay switched.
        $staff = $viewer->isStaff();

        if (! $statsOn && ! $markOn) {
            return ['stats' => $ownOnly, 'mark' => $staff ? $ids : $ownOnly];
        }

        $audience = $this->downlineAudience($ids, $ownOnly, $own === null ? null : (int) $own);

        return [
            'stats' => $statsOn ? $audience : $ownOnly,
            'mark' => $staff ? $ids : ($markOn ? $audience : $ownOnly),
        ];
```

Update the method docblock with one line: "Staff always get every id in `mark`." `$ids` is already `int[]`.

### #18 tests

In `DistributorIdCardStatsTest`, copy the setup of the existing purchase-mark test and add:
- `it('shows the purchase mark to staff on every card while the switch is OFF')`: act as the admin user; `compactMany([$a, $b])` returns a non-null `purchase_state` for both, and `highest_rank` is null.
- `it('keeps the purchase mark off downline cards for a distributor while the switch is OFF')`: the sponsor viewer gets null for the downline card and non-null for their own card.

### #19 `DTOs/FortuneQualification.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

/** This month's Fortune Bonus gate verdict for one distributor — facts, never money. */
final readonly class FortuneQualification
{
    public function __construct(
        public string $tier,              // FortuneBonusService::TIER_* or rank_1..rank_5
        public bool $rankIneligible,      // holds a rank in plan->fortuneIneligibleRanks()
        public bool $hasGsbIncome,        // at least one credited GSB cutoff this month
        public int $personalBvPaise,
        public int $bvRequiredPaise,
        public int $slabCount,
        public int $slabsRequired,
        public ?bool $holdsTitle,         // null when the tier does not require one
        public bool $qualified,           // every gate above passes
    ) {}

    public function tierLabel(): string
    {
        return match ($this->tier) {
            'new_joiner' => 'New joiner (first month)',
            'non_ranked' => 'No rank this month',
            default => 'Rank '.substr($this->tier, 5),
        };
    }
}
```

### #20 `DTOs/FortuneDashboardCard.php`

```php
final readonly class FortuneDashboardCard
{
    public function __construct(
        public \Carbon\CarbonImmutable $month,              // current IST month start
        public FortuneQualification $thisMonth,
        public ?\App\Modules\Compensation\Models\FortuneBonusResult $lastMonth,       // credited / skipped / repurchase_wallet_blocked
        public ?\App\Modules\Compensation\Models\FortuneBonusParticipant $lastMonthEntry, // enrolled, result not written yet
    ) {}
}
```
(Import the classes with `use` statements in the real file; strict_types and namespace as in #19.)

### #21 `FortuneBonusService.php`

1. Add a trailing `?int $distributorId = null` parameter to `buildFirstGsbDates`, `buildSlabCounts` (after `$slab`), `buildPersonalBvMap`, `buildLifetimeBvMap`, `buildNewJoinerIds`, `buildIneligibleRankIds` and `buildRankMap`. Each query gets `->when($distributorId !== null, fn ($q) => $q->where('distributor_id', $distributorId))`, except `buildNewJoinerIds`, which filters `where('id', $distributorId)` on `distributors`. Existing callers pass nothing, so behaviour is unchanged.
2. Extract the gate block of the `enrollEligible()` loop (from `$currentRank = …` to the `holdsATitle` check) into:

```php
    /**
     * The one statement of the Fortune Bonus entry gates, shared by the
     * month-end enrolment and the dashboard's live read.
     *
     * @return array{tier: string, slab_count: int, slabs_required: int, bv_required: int, holds_title: ?bool, passes: bool}
     */
    private function evaluateGates(int $rank, bool $isNewJoiner, int $slabCountAll, int $slab1Count, int $personalBvPaise, int $lifetimeBvPaise): array
    {
        $tier = $this->determineTier($rank, $isNewJoiner);
        $slabCount = $tier === self::TIER_NEW_JOINER ? $slab1Count : $slabCountAll;
        $gates = $this->plan->fortuneTier($tier);
        $holdsTitle = $tier === self::TIER_NON_RANKED ? $this->holdsATitle($lifetimeBvPaise) : null;

        return [
            'tier' => $tier,
            'slab_count' => $slabCount,
            'slabs_required' => $gates['slabs_required'],
            'bv_required' => $gates['bv_required_paise'],
            'holds_title' => $holdsTitle,
            'passes' => $personalBvPaise >= $gates['bv_required_paise']
                && $slabCount >= $gates['slabs_required']
                && $holdsTitle !== false,
        ];
    }
```
   The loop calls it and `continue`s when `! $verdict['passes']`, then uses `$verdict['tier']`. Keep the loop's existing comments.
3. New public method, after `forfeitedGrossPaiseForMonth()`:

```php
    /**
     * The distributor's own Fortune Bonus standing for the dashboard: this
     * month's live gate verdict (through the same builders and gates the
     * engine uses) and last month's written outcome. Read-only, no money
     * projected — a matrix position is only assigned when the month closes.
     */
    public function dashboardCardFor(int $distributorId, ?Carbon $today = null): FortuneDashboardCard
```
   Steps: `$today ??= Carbon::now('Asia/Kolkata')`; `$monthStart`/`$monthEnd` strings for the current month; call each builder with `$distributorId` and read `[$distributorId] ?? 0` (or `isset` / `in_array`); pass the values to `evaluateGates()`; `$rankIneligible = in_array($distributorId, $this->buildIneligibleRankIds($monthStart, $distributorId), true)`; `$hasGsb = isset($firstGsbDates[$distributorId])`; `qualified = ! $rankIneligible && $hasGsb && $verdict['passes']`. Last month: `FortuneBonusResult::where('distributor_id', $id)->where('month_start', $prevStart)->whereIn('status', [CREDITED, SKIPPED, REPURCHASE_WALLET_BLOCKED])->first()`. When that is null, `FortuneBonusParticipant::where(...)->first()`. Return the DTO with `month: CarbonImmutable::parse($monthStart)`.

### #22 `DashboardController.php`

- Next to `$repurchaseCard = null;` in the initialisers (around line 89) add `$fortuneCard = null;`, and add the same line in the `catch (QueryException)` block.
- Inside the `try`, after the `$repurchaseCard = …` statement:

```php
                // Fortune Bonus card — own data only, zero trace while the
                // engine flag is off.
                $fortuneCard = Feature::for(null)->active(FortuneBonusFeature::class)
                    ? app(FortuneBonusService::class)->dashboardCardFor($distributorId)
                    : null;
```
- Add `'fortuneCard' => $fortuneCard,` to the view data array (next to `'bonusSummary'`). Import `App\Modules\Shared\Features\FortuneBonusFeature` and `App\Modules\Compensation\Services\FortuneBonusService`.

### #23 `_fortune-bonus.blade.php`

Card shell identical to `_income-snapshot` (`bg-white rounded-2xl border border-gray-200 shadow-sm p-6 mb-6`), Lucide icons only, no `dark:` variants, all numbers via `IndianNumber` / `@bv`, and a `<x-help-tip>` on each label (UI convention).
- Header: eyebrow `Fortune Bonus`, subtitle `Your standing this month and last month's result.`, link `Details →` to `route('income.fortune-bonus')`.
- Two columns (`grid grid-cols-1 lg:grid-cols-2 gap-6`):
  - **This month — {{ month 'F Y' }}**: badge `Qualified so far` (green, `lucide-badge-check`) or `Not qualified yet` (amber, `lucide-circle-dashed`). Tier line: `Your tier: {{ tierLabel() }}`. Checklist rows (`lucide-check` green / `lucide-x` gray):
    - `GSB income earned this month` (hasGsbIncome)
    - `Personal BV this month: X of Y BV` (BV = paise/100, `@bv`)
    - `GSB slabs this month: A of B`
    - `Personal-purchase title held` (only when `holdsTitle !== null`)
    - When `rankIneligible`: a single gray note `Your current rank is not part of the Fortune Bonus.` in place of the checklist
    - Footnote: `Qualification is confirmed when the month closes. Positions are first come, first served by the date of your first GSB income, and your repurchase wallet must be at ₹0 at month end.`
  - **Last month**: when `lastMonth`: `Level {{ matrix_level }}`, `{{ points }} points`, status chip (`credited` → green `Credited`; `skipped` → gray `No income`; `repurchase_wallet_blocked` → amber `Blocked: repurchase wallet not cleared`), and `Net credited ₹…` via `IndianNumber::rupees($lastMonth->net_paise)` only when credited. When only `lastMonthEntry`: `Level X, entered on {{ enrolled_at d M }}`, `Result pending`. When neither: `You did not take part last month.`
- Copy check: no future ₹, no "earn up to", no comparisons with others.

### #24 `dashboard/index.blade.php`

After the `@if($bonusSummary !== []) … @endif` block:
```blade
    @if($fortuneCard)
        @include('dashboard._fortune-bonus')
    @endif
```

### #25/#26 tests

- `FortuneBonusQualificationTest` (use the builders' fixtures from `FortuneBonusServiceTest`):
  - FQ-01: a new joiner with 3,000 BV and one slab-1 GSB credit → qualified.
  - FQ-02: the same distributor below the BV requirement → not qualified, `personalBvPaise` reported.
  - FQ-03: holding an ineligible rank → `rankIneligible`, not qualified.
  - FQ-04: non-ranked without a title → `holdsTitle === false`, not qualified.
  - FQ-05: last month's credited result is returned; another distributor's result never is.
  - FQ-06: parity — for a seeded month, the set of distributors `enrollEligible()` enrols equals the set for which `dashboardCardFor()->thisMonth->qualified` is true (matrix not full).
- `DashboardFortuneCardTest`: flag ON → the dashboard contains `Fortune Bonus` and `Your tier`; flag OFF → the response does not contain `Fortune Bonus`.

### #31 conflicts doc
Copy Appendix A below into its own file, headed `# Client policy documents — conflicts with the platform (2026-09-23)`, with one line: `Published as supplied at the user's instruction. Each item needs a client decision; nothing here has been changed in code.`

### #32 CLAUDE.md
In hard rule 3, after the R-65 sentence ending `…while the developer-owned setting genealogy.downline_stats_visible is ON after the DPDP §5 notice period has run.`, add:
`*Amended 2026-09-23 (user decision):* staff always see the purchase mark (red / yellow / green star) on every card of the admin Genos; the setting continues to gate it for distributors.`

### #33 help doc
In `resources/help/distributor-communications.md`, change `Privacy Notice §4.5b` → `Privacy Policy, Part B §4.5b` and `Privacy Notice §5` → `Privacy Policy, Part B §5` (every occurrence; §4.5b means §4 item 5b).

## Slice 3 — Home & About merge (detail for #12/#13 content)

Rules:
1. **Never delete an existing section, card or sentence**, except for the compliance fixes in rule 5. Where the doc and an existing item cover the same theme, keep the existing title and replace or extend its body with the doc wording. Where the doc has an item with no existing counterpart, add it to the same PHP array so it renders with the existing markup.
2. Brand spelling: `arovolife` in copy. Keep `Arovolife Private Limited` for the legal name. The doc's `AROVOLIFE` / `Arovolife` become `arovolife`.
3. The doc's `**bold**` lines are sub-headings: render them as the section's secondary line in the existing heading/intro style (`text-lg text-gray-700 font-semibold` under the `h2`). Do not add new visual components beyond grid cards that reuse an existing array's markup.
4. Existing hrefs, routes, CTAs and structure stay. The doc's button labels may replace labels, but not targets.
5. Mandatory compliance fixes (these override the doc and existing copy):
   - "not recruitment alone" (doc, Home Why card) and "never from recruiting alone" (About story, line ~109) → `never from recruitment` / `never from recruiting`. Hard rule 2.
   - Home How-to-register: the existing step list must still include orientation (hard rule 4). If the doc's five steps are used, insert the orientation/micro-quiz step and keep KYC and ADN, making six steps. The heading counts must match: change "Five quick steps" to the real count.
   - About hero stat `24h — Grievance SLA` → `2 days — Grievance acknowledgement` (the newly published Grievance policy says 2 working days).
   - No new income wording; "Performance-based rewards" may stay only as written in the doc.

Mapping — About (`landing/about.blade.php`):

| Doc block | Existing section (line) | Action |
|---|---|---|
| ABOUT AROVOLIFE + tagline + 3 paras + "Best in Class, Best for Life." | Hero (46–63) | Tagline under `h1`; the three doc paragraphs after the existing paragraph; brand line above the CTAs |
| Our story — bold line + "A Purposeful Beginning…" paragraph | Our story (95–110) | Keep the existing text; add the bold line as a sub-heading and the doc paragraph (split at sentence boundaries into 2–3 `<p>`) after it |
| OUR PHILOSOPHY — 2 bold lines, intro, 4 principles | Philosophy (120–142) | Sub-headings + intro under the `h2`; merge the 4 principles into the `$philosophy` array by theme (add the ones with no match) |
| OUR PRODUCTS — 2 bold lines, 2 paras | Products (153–159) | Add sub-heading and both paragraphs after the existing intro; category cards untouched |
| Compliance & trust | (191–211) | **Do not change** (doc: "don't change") |
| FOR ASPIRING DIRECT SELLERS — bold lines + para | (230–256) | Keep the `h2`; add sub-headings and the paragraph after the existing one |
| GROWTH PATHWAY — bold line + para + 3 steps | (270–289) | Sub-heading + paragraph; update the 3 step bodies with the doc wording where the titles match |
| OUR VALUES — bold lines + intro + 6 values | (303–324) | Sub-headings + intro; merge the 6 values into the values array by theme (title = doc heading, e.g. `Integrity — Do What Is Right.`) |
| WHY NOW, WHY AROVOLIFE — bold lines + 4 paras + 4 highlight cards | (336–361) | Keep the existing paragraphs; add the doc paragraphs after them; merge the 4 highlight cards into the stats array (stat = `2026` / `Products with purpose` …, label = card title, sub = card text), keeping existing stats |
| REGISTER WITH US — bold line, 2 paras, 2 path cards, closing line | (376–397) | Sub-heading + paragraphs; the two cards take the doc headline/body/bullets; hrefs stay; closing line under the cards |

Mapping — Home (`landing/index.blade.php`):

| Doc block | Existing section (line) | Action |
|---|---|---|
| "Start Your Direct Selling Journey…" hero block + note | Hero `$slides` array | Add as a **new first slide** (eyebrow `Start your journey`, title_plain/accent split from the heading, body = first doc paragraph, cta_primary = `Become an arovolife Direct Seller →` with the same href as the existing join CTA, cta_secondary = the existing secondary, note = the doc's "Registration is free…" sentence). Keep existing slides |
| WHY AROVOLIFE — sub-line, intro, 6 cards, Promise | Why (408–440) | Sub-line + intro under the `h2`; merge the 6 cards into the cards array by theme (4 existing + new, deduped); add "The arovolife Promise" as a centred paragraph under the grid. Adjust "Four promises" if the count changes |
| HOW TO REGISTER — sub-line, intro, steps, closing block | How it works (448–496) | Merge step bodies by theme into the existing steps array (see rule 5 on orientation); add the "Your Journey Begins with Clarity" paragraph and bullet line above the CTA |
| OUR PRODUCTS — intros, 6 categories, "What makes… different", philosophy, closing line | Products (503–598) | Update each category card body with the doc's headline + paragraph (Health Care, Skin & Beauty, Personal Care, Home Care, Agri Care, Lifestyle). Under the grid, add two compact lists ("What makes arovolife products different?", "Our product philosophy") using the check-list markup from the compliance section, then the closing line |
| OUR COMPLIANCE COMMITMENT — sub-line, 2 paras, 6 items, Promise, closing tagline | Compliance (609–637) | Keep the `h2` and the statute items; add the doc paragraphs; add the 6 commitments as cards using the existing statute-item markup (label `Commitment` instead of `Statute`); Promise + tagline above the "Read the Direct Seller Agreement" button |

## Slices

| Slice | Title | Files (#) | Depends on | Model |
|---|---|---|---|---|
| 1a | Policy markdown: terms, ethics, privacy, grievance, policies | 1, 2, 3, 4, 9 | — | Sonnet |
| 1b | Policy markdown: returns, shipping, disclaimer, social-media | 5, 6, 7, 8 | — | Sonnet |
| 2 | Seeders, footers, content tests, help doc | 10, 11, 12 (footer only), 13 (footer only), 14, 15, 16, 33 | 1a, 1b | Sonnet |
| 3 | Home & About content merge | 12, 13 (content) | 2 (same files; run after) | Opus |
| 4 | Admin Genos staff star | 17, 18 | — | Opus |
| 5 | Fortune Bonus dashboard card | 19–26 | — | Opus |
| 6 | Conflicts doc + CLAUDE.md amendment | 31, 32 | — | main session |
| 7 | Playwright specs | 27–30 | 2, 3, 4, 5 | Sonnet |
| 8 | Compliance-officer review of the diff (public copy, KYC/consent pages, dashboard) + fixes | — | all | compliance-officer agent → main |

Execution waves (at most 3 agents at a time): **W1** 1a, 1b, 4 · **W2** 2, 5 · **W3** 3 · **W4** 7, then 8.

## Test plan (Playwright, `tests/Browser/`, fixtures from `fixtures.js`)

| Spec | Scenario | Matrix row |
|---|---|---|
| policy-pages | Guest opens each of the 9 slugs → 200, `h1` = title | View policy pages |
| policy-pages | Home footer shows Shipping & delivery, Disclaimer, Social media policy and Website terms of use links, and each navigates | View policy pages |
| policy-pages | `/content/compensation` stays unavailable (held) | Non-goal guard |
| home-about-content | Home: the new first slide heading "Start Your Direct Selling Journey" is present; "The arovolife Promise" is visible; the registration steps include "Orientation" | Home |
| home-about-content | About: "Wellness with Purpose" visible; the existing "The law isn't a checklist for us" heading is unchanged; no text matches `/recruit(ing\|ment) alone/i` | About |
| admin-genos-star | `adminPage` → `/admin/tree` → at least one `[data-purchase-mark]` visible while the setting is OFF (read the setting via the admin settings page; skip with a message if the dev tree has no BV rows) | Staff star ✅ |
| admin-genos-star | `distributorPage` → `/tree` → no `[data-purchase-mark]` on non-own cards while the setting is OFF | Distributor gated |
| admin-genos-star | `distributorPage` → GET `/admin/tree` → 403 | Distributor ❌ |
| dashboard-fortune-card | `distributorPage` → `/dashboard` → `getByText('Fortune Bonus')` card is visible with "Your tier" when the flag is ON (skip when OFF) | FB card ✅ |
| dashboard-fortune-card | Guest → `/dashboard` redirects to `/login` | FB card ❌ guest |
| dashboard-fortune-card | `adminPage` → `/admin` has no Fortune Bonus card | FB card ❌ staff |

## Acceptance criteria

- [ ] All 9 policy slugs render at `/content/<slug>` with the client's wording. Privacy carries Part A and Part B, including §4.5b and §5.
- [ ] `returns` is published by a blanket seed; `compensation` is not.
- [ ] `db:seed --class=ProductionSeeder` on an empty DB creates the 9 policy pages with real bodies, and a re-run changes nothing.
- [ ] Home and About show the doc content; no existing section is lost (diff review: no removed `<section>`, no removed array items); About Compliance & trust is byte-identical.
- [ ] No copy anywhere says "recruitment alone"; the registration steps include orientation.
- [ ] Admin tree shows stars with the setting OFF; the distributor tree does not show downline stars with the setting OFF.
- [ ] The dashboard FB card renders with the flag ON and is absent with it OFF; FQ-06 parity passes; `FortuneBonusServiceTest` is unchanged and green.
- [ ] Commands (per `docs/local-dev-environment.md`, **always with the `arovolife_test` overrides, never a bare `php artisan test`**):
  - `vendor/bin/pint --dirty`
  - `vendor/bin/phpstan analyse` (level 7) on the touched PHP files
  - the Pest suites: `tests/Modules/Content`, `tests/Modules/Identity/DistributorIdCardStatsTest.php`, `tests/Modules/Compensation/FortuneBonus*`, `tests/Feature/DashboardFortuneCardTest.php`
  - `npm run build` (new Tailwind classes), then `php artisan view:cache` + `php -l` over the compiled views
  - `npx playwright test tests/Browser/{policy-pages,home-about-content,admin-genos-star,dashboard-fortune-card}.spec.js`
- [ ] Commit trailers: `Compliance-Review: compliance-officer` on every commit touching content pages, the dashboard card or the star.

## Deploy checklist

- **Local dev / staging (rows already exist):** `php artisan content:publish terms ethics privacy grievance returns shipping disclaimer social-media policies`. This overwrites those nine bodies (it is destructive for any hand edits), so confirm per environment before running. New joiners then accept the new terms/ethics/privacy text: `ConsentDocuments` re-hashes from the published body, and the version becomes the publish date.
- **Production (not stood up yet):** `db:seed --class=ProductionSeeder --force` creates them.
- `npm run build` + rsync `public/build` (staging Node is too old to build).
- Restart the queue and scheduler (standard).

## Appendix A — client documents vs platform (for the client)

1. **Refund method.** Returns §4/§7 promise refunds "by NEFT/RTGS". The platform refunds non-saleable returns as a buy-back voucher, and repurchase-wallet credit always returns to the wallet, never as cash (R-60).
2. **Cooling-off start.** The DSA, Ethics and Returns policy count 30 days from the agreement date. The platform opens a 30-day window per order from delivery, and the old returns page said so.
3. **Return process.** The returns doc says to download a form from "MY PROGRASSION → resources" and email it. The platform has an in-app return flow.
4. **Saleable definitions disagree.** DSA (j) says "used not more than 30%"; the Returns doc says non-saleable = "used less than 30%".
5. **Business entities.** DSA 1.2 ("duly incorporated") and the Policies doc (register as Proprietor/LLP/Partnership/Company, GST verification, sponsor guest credentials, spouse information) describe a registration the platform does not offer. DSA definition (g) says Person = individual, and registration is individual (couples on one ADN).
6. **Registration steps.** The Policies doc steps differ from the real wizard (orientation video + quiz, PAN/Aadhaar e-KYC, ADN issue).
7. **Other company's plan terms.** The Code of Ethics uses "Dealership", "Sales Partner", "Partnership Promotions", "Education Commission". None are arovolife plan terms.
8. **Clawback.** Ethics §"returned product" deducts commissions from future payments. The platform does not claw back (ADR-0010: group BV reversal, same-side debt consumed by future propagation).
9. **Privacy.** The client doc has no DPDP Act 2023 notice, data-principal rights, DPO contact, Aadhaar handling (hard rule 8), KYC-scan retention (R-31, 8 years), or message-moderation disclosure (§4.5b). These are kept as Part B; the client should merge them or confirm Part B.
10. **Grievance.** There is no escalation route to the National Consumer Helpline / consumer commissions / Data Protection Board (the old page had one). Timelines are acknowledgement in 2 working days, RCA in 14 days, redressal in 6 days. The committee names (L Rajender Pal, G Shanker, L Priyanka) partly close the named-officers launch blocker; the DPO and Nodal Officer are still unnamed.
11. **Amendment notice.** DSA §12 allows amendment by website notice with no 30-day period. Code and copy cite "DSA §6.2 30-day notice" (R-75, FB/GSB flags) and "§17 Reserved" (nominee). Those clause numbers no longer exist in the new DSA.
12. **Buy-back table.** DSA §8's table (one merged row covering cooling-off, general buy-back and termination) is ambiguous about invoice/no-invoice payment. The returns doc's table layout differs.
13. **Existing About claims** (not from the doc, kept per instruction): "99% customer satisfaction" is an unsubstantiated statistic for a 2026 company, "The fairest direct selling company in India" is an unsubstantiated superlative, and "never a better decade… we intend to lead it" is puffery. Mis-selling risk (DSR Rule 5); recommend removing.
14. **Typos kept in legal text**: "Contact" for Contract (DSA preamble), "accessed" for assessed (DSA §20), "FG.S.R." (DSA preamble).

## Outcome
**Shipped 2026-09-23** (branch `feat/policy-pages-home-fb-card`).

- Slices 1–7 landed. 9 policy pages (4 new: shipping, disclaimer, social-media, policies) published on dev via `content:publish`; `ProductionSeeder` creates them if missing (compensation stays held).
- Admin Genos: staff always see the purchase mark, scoped to `admin.tree.*` (R-106, CLAUDE.md hard rule 3 amended).
- Distributor dashboard: Fortune Bonus card (this month's gates + last month's recorded result), behind `FortuneBonusFeature`.
- Home / About merged from the client doc.

**Verification:** 288 Pest tests pass on `arovolife_test`; Pint + Larastan clean; `npm run build` OK; Playwright 24 passed / 3 skipped (dev-data dependent) / 0 failed.

**Deviations:**
- Content route is `/p/<slug>` and About is `/about-us` (plan said `/content/<slug>`, `/about`).
- Returns §4 table rewritten by hand (plan's example belonged to terms §8).
- Ethics published truncated at 18(iv), as the user chose.
- Privacy "4.5b" means Part B §4 item 5b.

**Compliance:** compliance-officer PASS with recommendations. R-105 accepted by the user but still open: the client must amend Terms §8 / Returns §4 before launch. Still open for launch:
- conflicts item 17 (About "24-hour" line);
- the terms "No Earning Guarantee" downline wording;
- "Ready in Minutes" / "Fully compliant" copy.

**Deploy (staging, not yet run):** `content:publish` of the 9 slugs (destructive, needs confirmation); rsync the Vite build; restart queue and scheduler.
