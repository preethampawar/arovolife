# Distributor UI changes — design spec (2026-09-28)

Status: **design approved 2026-09-28**, spec awaiting review. No code written yet.
Deploy rule: commits stay local. **Nothing goes to staging or production without the user's explicit approval for that deploy.**

Source: user's 30-item list of 2026-09-28. All paths below are relative to `app/` unless they start with `docs/`.

---

## 1. Decisions taken during design

| Item | Decision |
|---|---|
| 1 | Treated as a suspected bug. Reproduce first with a test. Code reading found no path that spends the sharer's wallet on a guest order (see §8.1). |
| 2 | Keep the registration step order. Step 9 only gets a new title (item 4). |
| 3 | **Compliance stop accepted by the user:** nominee Aadhaar stays **optional**. No change. |
| 11 | Show the date **and time**. Cycles stay whole-day for eligibility. No engine change. |
| 20/21 | Two cards per side, numbers only. "Carried-over" shows only business since the last match. It reads 0 right after a match. Neither side's card is removed. |
| 22 | Order count = paid **self-consumption** orders placed **today** (IST) by team members on that side. |
| 23 | Header colour `#008cc7` (`brand-600`). The logo blue `#00b6ef` fails white-text contrast. |
| 30 | Every mention of "arovolife" in rendered text uses a Century-Gothic-style face. Font is **URW Gothic** (free Avant Garde clone, open licence) until the user buys Century Gothic, then it's a one-file swap. |
| 14 | Left = blue, Right = green on **every** surface that colours a side, not only the dashboard section. The Genos tree canvas nodes are out of scope. |

## 2. Slices and order

Each slice is one branch-sized unit. Each ships with its own tests and a local browser check, in this order:

1. Copy and labels: 4, 5, 6, 8, 9, 15, 28, 29
2. Top bar and sidebar: 7, 10, 23, 26, 27, 30
3. Dashboard: 13, 14, 16, 17, 18
4. My Business: 19, 20, 21, 22
5. My Income: 24, 25
6. Registration: 2, 3. **No work.** Step 9's title is in slice 1 and nominee Aadhaar stays as it is.
7. Repurchase: 1, 11, 12. The `compliance-officer` subagent review is mandatory before merge, with a `Compliance-Review:` trailer.

---

## 3. Slice 1 — Copy and labels

| Item | Change | Files |
|---|---|---|
| 4 | Step 9 heading and `<title>` become "My Personal Details". The wizard sidebar label stays "Personal". | `resources/views/registration/step3-personal.blade.php` |
| 5 | Step 10 upload form: on submit, disable the submit button, show a spinner and the line "Uploading your documents. Please keep this page open." (`role="status"`, `aria-live="polite"`). Re-enable if the browser reports a validation failure. | `resources/views/registration/step7-documents.blade.php` |
| 6 | Move the existing "Total BV" row (distributor-only, only when BV > 0) directly under the "Order Summary" heading, above the item list. Keep its current markup and tooltip. | `resources/views/shop/checkout.blade.php` (heading ~l.408, BV block ~l.459) |
| 8 | For distributors, "My Dashboard" becomes "My Personal World": top-strip link, mobile menu, sidebar "Dashboard" item, dashboard page title/heading, any breadcrumb. Admin and customer-only labels are unchanged. | `resources/views/partials/public-topnav.blade.php`, `partials/distributor-sidenav.blade.php`, `resources/views/dashboard/index.blade.php`, `app/Modules/Identity/Http/Controllers/DashboardController.php` (if it sets a title) |
| 9 | "My Business" becomes "My Business World" everywhere a distributor sees it: top strip, mobile menu, sidebar, quick-action tile, KPI strip, page heading, income tabs, `IncomeNavLinks`. | Files from `grep -rln "My Business" resources app` (list captured 2026-09-28: `my-business.blade.php`, `income/_tabs`, `dashboard/_quick-actions`, `dashboard/_kpi-strip`, `partials/distributor-sidenav`, `partials/public-topnav`, `dashboard/_genos-balance`, `DashboardController`, `DistributorIdCardStats`, `IncomeNavLinks`, `MyBusinessController`, `GsbCutoffService`, `resources/help/compensation.md`) |
| 15 | "Left group" / "Right group" becomes "Left Genos" / "Right Genos" in all **user-facing** copy, distributor and admin. That covers views, emails, help Markdown, and DTO/service label strings that reach a screen. Internal identifiers, enum values, column names and code comments are unchanged. | 9 view/help files plus `IncomeOverviewService`, `GenosBvLedgerService`, `GenosLedgerDay`, `GsbSlabRow`, `GsbSlabProgress` (label strings only). Skip `ScaleSeedCommand`. Also the sidebar position chip "← Left group" becomes "← Left Genos". |
| 28 | "Forgot your ADN?" becomes "Find my ADN". | `resources/views/auth/login.blade.php:60` |
| 29 | Remove the "Primary account holder" row (`#coupleRoleRow`) and its reveal script, and remove "Remember me". `LoginController` stays as is: a missing `primary` input already falls back to the primary holder, and `remember` is simply false. | `resources/views/auth/login.blade.php` |

Consequences to record:
- **Item 29, joint accounts:** an ADN sign-in always resolves to the primary holder. A spouse on a joint account signs in with their own email or mobile. Couple registration is currently disabled, so no live account is affected.
- **Item 29, sessions:** without Remember me, sessions end at the normal session lifetime.
- **Item 15, terminology:** update the terminology memory (`terminology_genos_bv_group.md`): the user-facing leg name is now "Genos" ("Left Genos"), not "group". Update `resources/help/glossary.md` in the same commit.

Tests: feature tests assert the new strings render and the old strings are absent, for dashboard, My Business, login and checkout (BV row appears before the first cart line). One login test covers a couple ADN signing in as primary with no `primary` field.

---

## 4. Slice 2 — Top bar and sidebar

### 4.1 Header colour (item 23)
- The utility strip and main nav change from `bg-brand-700` to `bg-brand-600` (`#008cc7`). Separator text `text-brand-400` becomes `text-brand-200`.
- Hover states `hover:bg-brand-800` become `hover:bg-brand-700`. Borders `border-brand-600` become `border-brand-500`.
- File: `resources/views/partials/public-topnav.blade.php`.
- Check that the dark-theme remap (`html.dark`, see the theme architecture memory) still gives the header a dark surface. Never use `dark:` variants.

### 4.2 Sign out (item 7)
- For signed-in **distributors**, "Sign out" sits in the same place as the guest "Sign In" pill: the far right of the main nav bar, after the cart. It uses the same pill shape as "Sign In" (`px-4 py-2 rounded-full bg-white text-xs font-semibold shadow-sm`), with `<x-lucide-log-out>` and the label in `text-red-600`. It is a POST form to `route('logout')`. Signing in and signing out therefore happen at one spot, top right. Nothing is added to the utility strip.
- The white pill keeps the red readable on the blue bar.
- Mobile menu: its existing "Sign out" button turns red (`text-red-100 hover:bg-red-600`) with the icon.
- Admins and customer-only accounts keep the current dropdown, sign-out included.

### 4.3 Profile dropdown removed for distributors (item 10)
- In the utility strip, distributors no longer get the `data-profile-menu` block. Show the initials badge and name as a plain, non-interactive label, followed by the existing links.
- Every dropdown entry already exists in the sidebar, so nothing is lost.
- The dropdown JS must no-op when the trigger is absent. It already guards on the element; verify.
- Sidebar: add a **"My Profile"** group, placed after "Shopping" and before "My Account", with:
  - Edit Profile → `profile.show` (icon `user-pen`, prefix `profile.`, excluding `profile.password.*`)
  - Change Password → `profile.password.show` (icon `lock`, prefix `profile.password.`)
  - My Addresses → `addresses.index` (moved here from "Shopping")
- Remove the old "My Profile" item from "My Account".
- The active-state logic must not light up Edit Profile on the password page. Give Edit Profile an explicit `exclude` prefix, or match `profile.show` exactly.
- Update the header comment in `partials/distributor-sidenav.blade.php`. It says phones keep the top-nav profile menu, which is no longer true: phones use the drawer.

### 4.4 Sidebar scrolling and hover (items 26, 27)
- Desktop column (`#distributorSidenavSticky`): `lg:sticky lg:top-28 lg:max-h-[calc(100vh-8rem)] lg:overflow-y-auto`. Add a thin, always-visible scrollbar via a `.nav-scroll` utility in `resources/css/app.css`: `scrollbar-width: thin; scrollbar-color: var(--color-brand-300) transparent;`, plus WebKit rules at 6px.
- Delete the sticky-offset fitting script, which the inner scroll area replaces.
- The mobile drawer already scrolls. Add `.nav-scroll` to it.
- Hover on a non-active item: `hover:bg-brand-100 hover:text-brand-800` instead of `hover:bg-white/80`. The active state stays as is. File: `partials/_distributor-sidenav-groups.blade.php`.

### 4.5 Brand font on "arovolife" (item 30)
- **Font:** self-host URW Gothic Book and Demi as woff2 under `resources/fonts/` with `@font-face` (`font-display: swap`) in `resources/css/app.css`. Add the theme token `--font-brand: 'URW Gothic', 'Century Gothic', 'Avant Garde', sans-serif;` and a `.font-brand` utility.
  - Record the licence file next to the fonts.
  - If the user supplies licensed Century Gothic woff2 files, replace the two files and the family name. Nothing else changes.
- **Blade:** a `<x-brand-name />` component renders `<span class="font-brand">arovolife</span>`. Replace literal "arovolife" in **rendered text** across `resources/views`, including HTML emails.
  - Never inside attributes (`title`, `alt`, `placeholder`, `aria-*`, `value`), URLs, email addresses, `<title>`, `<meta>`, or `<script>`.
  - The header/footer wordmark counts as rendered text.
- **Markdown** (help pages, content pages from the DB): add a CommonMark text-node renderer extension to the existing Markdown pipeline. It wraps the word in rendered text nodes only, never in link hrefs, code spans or code blocks. Locate the pipeline with `grep -rn "CommonMark\|Str::markdown\|MarkdownConverter" app`.
- **Excluded,** because they can't carry a font: SMS, plain-text email parts, CSV/Excel exports, browser tab titles, PDF files generated server-side (if any).
- **Case:** keep the lowercase rule from the brand-casing memory.
- **Guard test:** a test renders the dashboard, login page and one help page, then asserts every visible "arovolife" sits inside `.font-brand` and no attribute contains a `<span`.

Tests: nav tests for distributors check there's no `data-profile-menu`, there's a red sign-out form, and the "My Profile" group has three links. Admin keeps the dropdown. Run `php -l` on the compiled views (see the Blade lint memory).

---

## 5. Slice 3 — Dashboard

### 5.1 Quick actions (item 13) — `resources/views/dashboard/_quick-actions.blade.php`
- Each tile gets a unique colour family.
- Card strength goes up from `-50` to `-100` background with a `-300` border. Hover moves to `-200`. The icon stays solid `-500`.

| Tile | Family |
|---|---|
| Shop | sunrise |
| My Orders | amber |
| My Genos | brand |
| My Referrals | leaf |
| My Business World | indigo |
| Income | emerald |
| Wallet | **teal** (was brand, duplicating My Genos) |
| My Offers | pink |
| Arete Centres | violet |
| My Requests | slate |
| Cooling-off | red |

- Add `teal` to `$quickTones`, with literal class strings so Tailwind keeps them.
- Confirm that `teal`, and any family used at `-100`/`-300`, is covered by the `html.dark` remap. Extend the remap if not.

### 5.2 Left blue / Right green (item 14)
- Left = `sky` (bar gradient `from-sky-400 to-sky-600`, text `sky-700`, chip `sky-50/200`).
- Right = `emerald` (bar gradient `from-emerald-400 to-emerald-600`, text `emerald-700`, chip `emerald-50/200`). This replaces every `indigo` Right cue.
- Surfaces:
  - `dashboard/_genos-balance.blade.php`
  - the sidebar position chip in `partials/_distributor-sidenav-groups.blade.php`
  - `dashboard/_my-team.blade.php`
  - My Business Left/Right cards (slice 4)
  - Rank Bonus bars (slice 5)
  - `income/genos-bv`, `income/genos-ledger`
- Grep each touched file for `indigo` used as the Right colour.
- Put the two palettes in one place, a small `GenosSideColors` support class or Blade partial returning the class strings, so later surfaces reuse them. Class strings stay literal.

### 5.3 Income snapshot cards (item 16) — `dashboard/_income-snapshot.blade.php`
Replace `border-gray-200 bg-gray-50` per card:

| Card | Tint |
|---|---|
| This month | `bg-gradient-to-br from-emerald-50 to-white border-emerald-200` |
| Lifetime | `from-brand-50 to-white border-brand-200` |
| Next weekly | `from-amber-50 to-white border-amber-200` |
| Next monthly | `from-violet-50 to-white border-violet-200` |
| Repurchase alert | `from-amber-50 border-amber-300` by default. Use `from-red-50 border-red-300` in the same urgency state where the cycle ring turns red (`RepurchaseCycleCard::urgencyTier() === 'late'` or suspended). Reuse the tier, don't recompute it. |

Card label text moves from `text-gray-600` to the family's `-800`.

### 5.4 Bonus table (item 17)
- Use the Profile Stats look for the per-bonus table container: `rounded-2xl border border-brand-200 bg-gradient-to-br from-brand-50 via-white to-leaf-50/60`, plus the 1.5px top accent bar `from-brand-500 via-leaf-500 to-sunrise-500`.
- The header and Total rows move from `bg-gray-50` to `bg-brand-50/70`. Row hover becomes `hover:bg-white/70`.

### 5.5 Fortune Bonus status (item 18) — `dashboard/_fortune-bonus.blade.php` ~l.33–43
- Not qualified: `bg-red-50 border-red-200 text-red-800`, icon `circle-x`, text "Not qualified yet".
- Qualified: unchanged green.

Tests: render tests for each partial, asserting the new class hooks. Use a `data-tone` attribute on each card so tests don't depend on class strings. Local browser check in light and dark.

---

## 6. Slice 4 — My Business (`resources/views/my-business.blade.php`, `MyBusinessController`)

### 6.1 Next payout (item 19)
The card at ~l.116 gets `bg-gradient-to-br from-emerald-500 to-green-700` with white text. Keep the existing text classes (`text-white/80`).

### 6.2 Carry cards (items 20, 21)
- Four cards in one row: Left carry forward, Carried-over Left Genos BV, Carried-over Right Genos BV, Right carry forward.
- Numbers only: remove the sub-lines under each value ("Remaining after your last slab match…", "+ N BV slab-1 weaker carry over", "No new business on this side since…"). The label and `<x-help-tip>` stay.
- Tint like the quick-action cards: Left cards `from-sky-50 to-white border-sky-200`, Right cards `from-emerald-50 to-white border-emerald-200`.
- **Carried-over value:**
  - The display value becomes `max(0, carriedBv − carryForwardBv)` for that side, i.e. business since the last match.
  - Before the first match `carryForwardBv` is 0, so the value is unchanged. Immediately after a match it reads 0.
  - The subtraction happens in the controller or a view model, not in Blade.
  - Delete the F54 `*UnchangedSinceMatch` variables, which become redundant.
- **Help tips:**
  - Carried-over: "Business added on this side since your last slab match, as it stood after the last 23:59 cut-off. Together with your carry forward, it counts toward your next slab match."
  - Carry forward: unchanged.
- **The slab-1 weaker carry over figure** is no longer shown as a sub-line. Move it into the Carried-over help tip text so it is not lost. Check with the compliance officer that nothing else relied on it.
- **Engine data is not touched.** This is display only.

### 6.3 Team cards with today's orders (item 22)
- Left card, left-aligned: `32 members → 5 orders today`. Right card, fully right-aligned: `7 orders today ← 15 members`.
  - Members use the current team-count figure.
  - The arrows are real glyphs (`arrow-right` / `arrow-left` Lucide icons), with an `aria-label` such as "32 members, 5 orders today".
- **Definition:** count orders where
  - `orders.self_consumption = true`,
  - status is paid or later and not cancelled/refunded,
  - `paid_at` falls within today in Asia/Kolkata,
  - and the buyer distributor is in that side's subtree.
- **Must** go through `TeamStatsService::scopedQuery()` (`app/Modules/Identity/Services/TeamStatsService.php`), per the team-stats single-source-of-truth rule.
  - Add one method, e.g. `ordersTodayBySide(Distributor $root): array{L:int,R:int}`: one grouped query, closure-table join, no per-member loop.
- **Compliance:** an aggregate count about the user's own subtree, no money, no individual data, so no projection. Note it in the compliance review.

Tests:
- A unit test for `ordersTodayBySide`: orders yesterday are excluded, cancelled orders are excluded, customer (non-self) orders are excluded, the right side is counted separately, and the IST day boundary is respected.
- A view test for the right-aligned mirror layout.
- Carried-over reads 0 right after a match fixture.

---

## 7. Slice 5 — My Income (`app/Modules/Compensation/Http/Controllers/IncomeController.php`)

### 7.1 Default filter dates (item 24)
- Daily bonuses, `gsbHistory` (GSB) and `mentorship` (MSB): when the request has **no filter keys at all**, default `from = to = yesterday` (IST).
- Monthly bonuses, `growthBooster`, `rankBonus`, `fortuneBonus`, `adcBonus` (month inputs, `Y-m`): default `from = to = previous month`.
- **Clearing must still show everything.** The filter form submits a hidden `f=1`. Defaults apply only when `f` is absent, so an explicitly cleared form (empty from/to with `f=1`) shows all rows. The filter bar's "Clear" link goes to `?f=1`.
- `genosBv` and other pages are unchanged.
- The defaults must feed both the query and the input values shown in `<x-filter-bar>`.

### 7.2 Rank Bonus page (item 25) — `resources/views/income/rank-bonus.blade.php`
- The "My Rank Status" "Left Genos BV this month" bar uses the Left blue palette (§5.2). The Right bar uses green.
- "Rank Bonus credited to wallet (page)" and "Months credited" (~l.135–141) become quick-action-style tiles: `rounded-2xl border` with a `-100` tint and a solid `-500` icon chip. Credited uses emerald with the `wallet` icon. Months uses brand with the `calendar-check` icon.

Tests:
- Defaults: no query gives yesterday. `f=1` with blanks gives all rows. An explicit range is respected.
- Monthly defaults to the previous month, including across the January boundary.

---

## 8. Slice 7 — Repurchase (compliance review mandatory)

### 8.1 Item 1 — sharer's wallet on a customer's shared-cart order
Reported scenario: KP shares a shared cart / Easy Purchase link. KP's repurchase wallet holds ₹5,000 and the cart holds ₹4,000. A customer opens it and checks out. Expected: the customer pays ₹4,000 and KP's wallet stays at ₹5,000.

Code reading (2026-09-28):
- `CheckoutService::place()` applies credit only for `$buyerDistributorId`.
- `CheckoutController::place()` passes `Auth::user()?->distributor?->id`.
- `CheckoutController::show()` computes the displayed balance from `$request->user()?->distributor`.
- A guest with a shared-cart pass has no distributor, so they get no credit.
- A signed-in distributor gets their **own** wallet.
- Likely cause: the test ran while signed in as KP.

Steps:
1. Write a feature test for the exact scenario: guest, `SharedCart` pass for KP, KP wallet ₹5,000, cart ₹4,000. Assert the checkout page shows no "Repurchase Credit" line, placing the order gives `total_paise` = ₹4,000 plus fees, and KP's wallet balance is unchanged.
2. Add a second test: a **different** signed-in distributor opens KP's link. Only the buyer's own wallet may apply, and KP's never.
3. If both pass, stop and ask the user for exact reproduction steps: browser, who was signed in, and the URL. If one fails, fix the root cause with `superpowers:systematic-debugging`.

### 8.2 Item 11 — cycle date and time (`dashboard/_repurchase-cycle.blade.php`, `RepurchaseCycleCard`)
- Add `?Carbon $startedAt` to the DTO.
  - For a first cycle, or one opened on the day of a reactivation: the `paid_at` of the order whose self-purchase BV reached the qualifying threshold. Source: the first-reach walk in `BvLedgerService` (~l.204–211), which already orders entries by `effective_at`. Use that entry's `effective_at`.
  - For a cycle that rolls straight on from the previous one: the start date at 00:00.
  - Resolve it once in `RepurchaseCycleService` where the card is built. No per-render query.
- The dates line becomes: `Started 7 Jul 2026, 2:32 PM · Ends 6 Aug 2026, 11:59 PM · 30-day window`.
  - The window length still comes from `daysTotal − 1`, never a hardcoded 30.
  - Times are in Asia/Kolkata, `g:i A`.
  - Below `sm`, start and end go on separate lines.
- Drop "last day counts": the 11:59 PM end time says it.
- Eligibility logic is unchanged. Whole days, no engine change.
- The admin tab `admin/compensation/distributors/_tab-repurchase.blade.php` is **out of scope**.

### 8.3 Item 12 — repurchase popup and green check
**Where:** `resources/views/shop/confirmation.blade.php`, for **paid** orders only. COD and unpaid online orders never show it: BV accrues at `markPaid` (`OrderStateMachine` ~l.90).

**Trigger:** only when this order changed the repurchase standing. Compute server-side in the confirmation action. Build the card with the current state, which already includes this order's BV, and subtract the order's own self-purchase BV to get the "before" figure.

| Case | Condition | Popup copy |
|---|---|---|
| Restored | Card state is suspended, BV now ≥ required with this order, wallet balance now 0 | "Your repurchase requirement is met. Your bonus eligibility is restored from today, {d M Y}." |
| BV met, cycle running | State active, BV was below required before this order and is at or above it now | "Your repurchase BV for this cycle is met. Keep your repurchase wallet at ₹0 on {end d M Y} to complete the cycle." |
| Anything else | — | No popup |

**Popup:**
- Use the existing `confirm-modal` component or the platform modal pattern: green `circle-check` icon and a single "OK" button.
- Show it once per order: key it by order id in session, not localStorage, so a refresh doesn't repeat it.
- Accessible: focus trap, Esc closes.

**Green check in the dashboard Repurchase cycle card:**
- When BV is met in an active or restored cycle, add a line with `circle-check` in green: "Repurchase eligibility met. Today's business counts toward your bonuses."
- No rupee figures, no forward-looking amounts.

**"Restored from today" claim:**
- It relies on `RepurchaseCycle::forfeitedWindow()` ending at `fulfilled_on − 1`, so today's business is eligible once the engine resolves the cycle.
- Verify with a test that runs the repurchase evaluation for the day and asserts the fulfilment date is today.
- If the engine would date it tomorrow, change the copy to "from tomorrow". Never let the popup overstate.

**Compliance review focus:**
- Hard rule 3: eligibility wording only, no income figures or projections.
- The popup must not appear for customers or guests. Rule it out with a test.
- Copy passes the `arovolife-ux-writing` skill.

Tests: each popup case appears for the right state and is absent otherwise, including COD, a customer buyer and the second visit. The dates line shows the time. The green check appears only in the two met states.

---

## 9. Cross-cutting

- **Test commands:** follow `docs/local-dev-environment.md`. Tests must hit `arovolife_test`, never the dev DB. Run `npm run build` after Tailwind class changes.
- **Help docs:** update the matching `resources/help/*.md` in the same commit as each behaviour or label change (items 8, 9, 15, 20–22, 24).
- **Icons:** Lucide only.
- **Numbers:** all go through `IndianNumber`.
- **Theme:** light/dark via the `html.dark` remap, never `dark:` variants.
- **Commits:** Conventional Commits, one per logical change. Slice 7 commits carry `Compliance-Review: compliance-officer`.
- **Verification per slice:** targeted tests, then Pint on dirty files and Larastan, then a local browser check at desktop and phone width.
- **Deploy:** local only. Staging or production deploys need the user's explicit approval each time.

## 10. Out of scope
- Registration step order (item 2) and nominee Aadhaar (item 3), both decided no-change.
- Cycles to the minute (item 11 alternative).
- The admin repurchase tab.
- Genos tree canvas node colours.
- Buying a Century Gothic licence.
