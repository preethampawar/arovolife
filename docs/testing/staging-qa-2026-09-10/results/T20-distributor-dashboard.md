# T20 — Distributor journey: login → dashboard → income snapshot → repurchase traffic light → My Business

Verdict: **PASS-with-notes** (1 Medium numeric defect, 3 Low/UX notes, 1 check blocked by empty data)

Environment: staging `https://phplaravel-1611779-6390605.cloudwaysapps.com`, commit `6f114500`.
Account: ADN **444555666** (distributor id 1, user 2). Browser: Chrome, viewport 1308×828, single session.
DB evidence: read-only MySQL over SSH (`ahdhesuhty`). No staging row was written by this task.

## Checks

| # | Check | Result | Evidence |
|---|-------|--------|----------|
| 1.1 | Wrong password once → generic error | PASS | `444555666` + `WrongPass!0000` → page text: **"These credentials do not match our records."** No hint that the ADN exists, no field-level "wrong password". |
| 1.2 | Correct login lands on the distributor dashboard | PASS | `444555666` / `QaPass!2026` → 302 to `/dashboard`, title "Dashboard — arovolife", greeting "Welcome, Arovolife Private Limited", ADN 444555666 shown. |
| 1.3 | Session persists across navigation | PASS | 28 distinct nav/sidebar/profile-menu paths fetched in-page with the session cookie — **all HTTP 200, none redirected to /login** (list in check 5). |
| 1.4 | No console errors | PASS | `read_console_messages` with tracking armed before a fresh `/dashboard` load returned **"No console messages found"** (no errors, no warnings). |
| 1.5 | Each page < 3 s | PASS | Navigation Timing: `/dashboard` load **963 ms** (first), **541 ms** (repeat); `/my-business` **285 ms** (TTFB 150 ms). In-page fetches of all 28 routes: 121–293 ms each. |
| 2.1 | Personal BV | PASS | UI `2,80,600 BV` (ID card + PERSONAL BV tile + My Business) = `bv_ledger_entries` 28,060,000 paise. |
| 2.2 | Genos BV left/right — today | PASS | UI `0 BV / 0 BV`, "Today, before cut-off". `group_bv_daily` has **no row for 2026-09-10** (latest is 2026-09-06). Correct. |
| 2.3 | Genos BV carried into tonight's cut-off | PASS | UI Left `1,89,400 BV`, Right `0 BV`. `gsb_carryforward` id 5: `power_side_bv_paise=18,940,000`, `power_side=L`, `slab1_weaker_bv_paise=0`. |
| 2.4 | Team counts | PASS | UI Total 316 / Left 281 / Right 35 / Direct 5. `genealogy_closure` ancestor 1 depth>0 = **316**; subtree of placement child id 2 (L) = **281**, id 3 (R) = **35**. `sponsor_id=1` returns 6 rows but one is distributor 1 itself (self-sponsored root) → **5** genuine direct referrals. |
| 2.5 | My Team "By status" | PASS | UI Active 32 / Pending 284 / Blocked 0 / Terminated 0. DB join on `users.status` over the 316 descendants: `active 32`, `pending 284`. |
| 2.6 | Wallet balance | PASS | UI `₹75,611.86` (dashboard tile, snapshot card, My Business) = sum of the 18 `wallet_ledger_entries` rows excluding the repurchase sub-wallet = **7,561,186 paise**. Arithmetic in the table below. |
| 2.7 | Income snapshot — Lifetime | PASS | UI `₹78,053.84` = GSB 1,225,600 + MSB 5,363,400 + Rank 1,216,384 = **7,805,384 paise**. |
| 2.8 | Income snapshot — **This month** | **FAIL** | UI `₹80,495.82` in the "THIS MONTH" tile *and* in the per-bonus table's TOTAL row, while the six bonus rows above it sum to `₹78,053.84`. **This month > Lifetime** on an account whose entire history is September. See Defect D1. |
| 2.9 | Per-bonus rows | PASS | GSB `₹12,256.00` = 1,225,600; Mentorship `₹53,634.00` = 5,363,400; Rank `₹12,163.84` = 1,216,384; GBB/Fortune/ADC `₹0.00` — no such rows exist for d1. |
| 2.10 | Repurchase wallet balance | PASS | UI `₹272.98` = `repurchase_deduction` 244,198 − `repurchase_wallet_used` 216,900 = **27,298 paise**. |
| 2.11 | Traffic light state (bae0a189) | PASS | Thresholds in `RepurchaseWalletStatus`: `cleared` (≤ ₹0, grey), `red` ≤ 10 days, `amber` ≤ 20 days, `green` otherwise. UI shows the **green "On track"** pill. Today 2026-09-10, deadline 30 Sep → `diffInDays + 1 = 21`; 21 > 20 → green. Correct. |
| 2.12 | "nearer ₹0 date" reminder (63fb0e41) | PASS | UI detail line: **"Bring this to ₹0 by 30 Sep — 21 days left"**. `repurchase_cycles` id 1 has `due_date = 2026-10-03`; the class caps the deadline at the calendar month end when the window runs past it, so 30 Sep is the nearer of the two ₹0 dates. Correct per the shipped rule. |
| 2.13 | Indian grouping + two-decimal rupees | PASS | `2,80,600 BV`, `1,89,400 BV`, `₹75,611.86`, `₹80,495.82`, `₹53,634.00`, `₹12,163.84` — lakh grouping throughout, money always two decimals, BV never given decimals. No `Number::format` (western) grouping seen. |
| 3.1 | Payout-week copy present and correct | PASS | Verbatim (help tip, "Next weekly" tile): **"Weekly income for each Wednesday-to-Tuesday earning week is paid on the following Tuesday (03:00 IST), provided your balance meets the minimum payout."** Verbatim (help tip, "Next monthly"): **"Monthly bonuses transfer to your bank on the 8th of each month, provided your balance meets the minimum payout."** Visible tile text: "NEXT WEEKLY — Tue, 15 Sep — Covers earnings through 08 Sep" and "NEXT MONTHLY — Thu, 08 Oct — Payout date". |
| 3.2 | Dates are real | PASS | `DAYNAME('2026-09-15')` = **Tuesday**; `DAYNAME('2026-10-08')` = **Thursday**. Wed 02 Sep–Tue 08 Sep window paid Tue 15 Sep — matches the deployed rule. |
| 3.3 | No future-earnings implication | PASS | Every clause is conditional on money already held ("provided your balance meets the minimum payout", "This is the balance sitting in your wallet right now, not a forecast", "Every number here is a record of what has already happened on your account"). No projected amount, no "you will earn". Hard rule 3 respected. |
| 3.4 | F17 / R-75 cross-reference | **Answered: yes, visible** | The Wednesday→Tuesday + 8th cadence **is already visible to logged-in distributors on staging** — not only in the unpublished `compensation` content page F17 is about, but in the dashboard income-snapshot tooltips, the My Business "Next payout" tooltip, and the "Tue, 15 Sep / Thu, 08 Oct" tiles. R-75's notice gate therefore cannot be satisfied by leaving the policy page unpublished alone; the authenticated surfaces carry the same cadence today. Flagged for the orchestrator. |
| 4.1 | My Business — every section renders | PASS | Page note, tab strip (12 tabs incl. "Awards & Rewards (Coming soon)"), 2 hero cards, 4 carry cards, 4 team/today cards, and the carry-over/carry-forward Note all render. The conditional forfeit banner correctly does **not** render (d1 has no forfeited day). |
| 4.2 | My Business — two-column layout | PASS | Sticky sidebar column + content column at 1308 px; cards in a clean 4-across grid, no overlap, no truncation, `scrollWidth == clientWidth` (1308) so no horizontal overflow. |
| 4.3 | "My Team" card | PASS (on the dashboard) | `my-business.blade.php` has no My Team card by design — the card lives on `/dashboard` and foots exactly (check 2.4/2.5). |
| 4.4 | Sidebar reachable on a short viewport (208c3889) | PASS-with-note | `resize_window` reported success but the rendered viewport stayed 1308×828 (window is maximised; `innerHeight` unchanged after two attempts) — **could not physically shrink to 700 px**. Verified instead against the live column: `colH = 775 px`, `rem = 14.4`. The shipped `fit()` already fired at 828 px (`style.top = 38.6px`, matching the formula). Evaluating the same formula at `innerHeight = 700`: `available = 584.8`, `overflow = 190.2`, `stickyTop = −89 px`, pinned bottom = **686 px ≤ 700** → every entry reachable. Empirical confirmation at 828 px: scrolled to `scrollY = 308` (max), the last entries incl. "FAQ" are on screen and only the top 4 ("Dashboard", "My Business", "My Income", "Announcements") sit above the fold — reachable by scrolling back up, none permanently clipped. |
| 5.1 | My Orders beside My Business (e44a1a62) | PASS | Utility bar order: `My Dashboard | My Business | My Orders` — `/dashboard`, `/my-business`, `/orders`, all 200. |
| 5.2 | Announcements link + unread badge | PASS-with-note | `/announcements` present in the sidebar (200). Unread badge correctly absent: the `announcements` table is **empty** on staging and `announcement_reads` for user 2 = 0, so `unreadCountFor` = 0. |
| 5.3 | Messages link | PASS | Sidebar "Messages" and the top-nav bell both → `/messages`, 200. Page reads "No messages yet." |
| 5.4 | Help | Note | There is **no "Help" entry** in the navbar or the profile menu. The distributor-facing help surface is the sidebar **FAQ** (`/faq`, 200); `resources/help/*.md` is admin-only. See D4. |
| 5.5 | Profile menu | PASS | 12 items — My Dashboard, My Business, My offers, My Arete Centre, Arete Centres, My Requests, My Orders & Sales, My BV Ledger, My Addresses, Edit profile, Change password, Sign out. All hrefs 200. |
| 5.6 | Every link resolves (no 404) | PASS | 28/28 = HTTP 200: `/dashboard /my-business /my/offers /my/arete-centre /arete-centres /my/requests /orders /bv-ledger /addresses /profile /profile/password / /about-us /p/management-team /p/business-opportunities /p/success-story /news /shop /blogs /contact-us /seminars /messages /income /announcements /tree /tree/sponsorship /my/grievances /faq`. |
| 6.1 | No other distributor's earnings anywhere | PASS | Dashboard and My Business show only own money (wallet, per-bonus credits, repurchase wallet). Downline appears only as **counts** (316/281/35/5, status split) and registration counts — never a rupee. "Total Withdrawal Income" renders as `—` (own-only field, no payouts yet). |
| 6.2 | `genealogy.downline_stats_visible` respected | PASS | No `genealogy.*` row exists in `settings` on staging → the registry default applies: `'default' => 'false'` (`AdminSettingsController.php:522`, owner `developer`). The setting is therefore **OFF**. The dashboard shows rank/personal BV only on the viewer's own ID card, which is own-data and unaffected by the switch; no downline rank or BV is rendered on either page. |
| 6.3 | No projections | PASS | See 3.3. Copy is uniformly retrospective. |
| 7 | Notifications bell / list, mark-read | **BLOCKED (no data)** | Bell renders in the top nav (`partials/_notification-bell.blade.php`, combined messages + announcements badge). Staging has **0 announcements and 0 messages for user 2**, so no item can be rendered and mark-read cannot be exercised. Re-test after T25/T35 seed content. See D2 for a design note found while reading the partial. |
| 8 | Screenshots + visual defects | PASS-with-notes | Two taken (paths below). No overlap, no truncation, no misaligned columns. Styles are **not** stale: `app-D6RlvjiQ.css` 171 kB and `app-inTXxUUR.js` 40 kB both load, gradients/pills/brand colours all paint. See D3/D5 for cosmetic notes. |

## Numbers table — UI vs DB

All money in paise in the DB; `wallet_ledger_entries` for `distributor_id = 1` (18 rows, ids 1–40).

| Figure | UI | DB source | DB value | Match |
|---|---|---|---|---|
| Personal BV (lifetime) | `2,80,600 BV` | `BvLedgerService::totalPersonalBvPaise(1)` | 28,060,000 | ✅ |
| Wallet balance | `₹75,611.86` | 800000−80000+1843200+1843200+400000−40000+774000+903000+1216384−121638+25600−2560 | 7,561,186 | ✅ |
| Repurchase wallet | `₹272.98` | 244,198 (`repurchase_deduction`) − 216,900 (`repurchase_wallet_used`) | 27,298 | ✅ |
| Genos Sales Bonus — month / lifetime | `₹12,256.00` / `₹12,256.00` | `gsb_credit` ×3 (800000+400000+25600) | 1,225,600 | ✅ |
| Mentorship Bonus | `₹53,634.00` / `₹53,634.00` | `mb_credit` ×4 (1843200+1843200+774000+903000) | 5,363,400 | ✅ |
| Rank Bonus | `₹12,163.84` / `₹12,163.84` | `rank_credit` ×1 | 1,216,384 | ✅ |
| Growth Booster / Fortune / ADC | `₹0.00` | no rows | 0 | ✅ |
| **Income snapshot — Lifetime** | `₹78,053.84` | 1,225,600 + 5,363,400 + 1,216,384 | 7,805,384 | ✅ |
| **Income snapshot — This month** | `₹80,495.82` | bonus credits 7,805,384 (**+244,198 `repurchase_deduction`** wrongly included) | 7,805,384 expected | ❌ **D1** |
| Left carry forward | `1,89,400` | `gsb_cutoff_results` 2026-09-06 (slab 3, credited) `power_cf_after_paise`, side `L` | 18,940,000 | ✅ |
| Right carry forward | `0` | same row, `slab1_weaker_cf_after_paise` | 0 | ✅ |
| Carried-over Left Genos BV (Power side) | `1,89,400` | `gsb_carryforward` id 5 `power_side_bv_paise`, `power_side=L` | 18,940,000 | ✅ |
| Carried-over Right Genos BV (Weaker side) | `0` | `gsb_carryforward` id 5 `slab1_weaker_bv_paise` | 0 | ✅ |
| Today Left / Right Genos BV | `0` / `0` | no `group_bv_daily` row for 2026-09-10 | — | ✅ |
| Total team / Left / Right / Direct | `316 / 281 / 35 / 5` | closure depth>0; subtrees of ids 2 and 3; `sponsor_id=1` minus the self row | 316 / 281 / 35 / 5 | ✅ |
| By status | `Active 32 · Pending 284 · Blocked 0 · Terminated 0` | `users.status` over the 316 descendants | 32 / 284 / 0 / 0 | ✅ |
| Next weekly payout | `Tue, 15 Sep` — covers through `08 Sep` | `DAYNAME('2026-09-15') = Tuesday`; Wed 02 Sep–Tue 08 Sep window | — | ✅ |
| Next monthly payout | `Thu, 08 Oct` | `DAYNAME('2026-10-08') = Thursday` | — | ✅ |
| Repurchase deadline / days | `30 Sep` / `21 days` / green | `repurchase_cycles` id 1 `due_date 2026-10-03`, capped to month end; `diffInDays(10 Sep → 30 Sep)+1 = 21`; 21 > AMBER_WITHIN_DAYS (20) → green | — | ✅ |

## Defects

### D1 — Medium (code / distributor-facing money) — "This month" income does not foot and exceeds "Lifetime"

- **Where:** `/dashboard` income snapshot — the "THIS MONTH" stat tile, the TOTAL row of the per-bonus table, and the "Wallet credits, last 6 months" bar chart. All three read `$creditsByMonth`.
- **Repro:** log in as ADN 444555666 → dashboard → scroll to "Income snapshot".
- **Expected:** "This month" = the sum of the six bonus rows for September = `₹78,053.84`, and never greater than "Lifetime".
- **Actual:** `₹80,495.82` in the tile and in the TOTAL row, sitting directly above six rows that sum to `₹78,053.84`, and directly beside a LIFETIME tile of `₹78,053.84`. **This month is ₹2,441.98 larger than lifetime** on an account whose entire history is this month.
- **Root cause:** `WalletService::creditTotalsByMonth()` (`app/Modules/Compensation/Services/WalletService.php:83-86`) selects **every** ledger row with `amount_paise > 0`, with no `type` filter. That sweeps in the `repurchase_deduction` rows — which are not income but the slice of each gross bonus routed into the repurchase sub-wallet, already counted inside `gsb_credit` / `mb_credit` / `rank_credit`. For d1: 80,000 + 40,000 + 121,638 + 2,560 = **244,198 paise = ₹2,441.98**, exactly the gap. `manual_credit` and positive `reversal` rows would leak in the same way.
- **Impact:** the headline "credited this month" figure a distributor sees is overstated by the repurchase deduction, and contradicts both the table beneath it and the lifetime tile on the same card. The six-month chart is inflated by the same amount.
- **Suggested fix:** restrict `creditTotalsByMonth()` to the six bonus credit types `IncomeOverviewService::bonusSummary()` uses (or exclude `repurchase_deduction` / `repurchase_transfer` explicitly), so the tile, the TOTAL row and the chart all foot to the rows. A regression test should assert `thisMonth ≤ lifetime` for a distributor with a repurchase deduction.

### D2 — Low (UX) — the notification bell always lands on Messages, even when the whole badge is announcements

`partials/_notification-bell.blade.php` sums unread messages **and** unread announcements into one badge, but `href` is `route('messages.index')` whenever messaging is on. A distributor with 3 unread announcements and 0 messages sees "3", clicks the bell, lands on an empty inbox, and the badge stays at 3. The docblock explains the single-badge decision; the single *destination* is what does not follow from it. Suggest routing to whichever channel holds the unread items when only one does, or splitting the badge. Could not be reproduced live (0 of both on staging) — found by reading the partial while investigating check 7.

### D3 — Low (UX) — four carry cards, two identical pairs

My Business shows "Left carry forward 1,89,400" and "Carried-over Left Genos BV 1,89,400" side by side, and "Right carry forward 0" / "Carried-over Right Genos BV 0". Both pairs are **correct** (no BV has moved since the 06 Sep match, so carry over == carry forward), but a distributor reading the Note directly below — which is at pains to say the two are different things — sees the same number twice with no explanation of why. Consider a one-line "no new business since your last match" hint when they coincide.

### D4 — Info — no "Help" entry for distributors

Neither the top navbar nor the profile menu has a Help link. The only help surface is the sidebar **FAQ** (`/faq`). If a Help entry is expected in the navbar, it is missing; if FAQ is the intended surface, the task list's "Help" item is satisfied by it.

### D5 — Low (cosmetic) — hero-card palette differs between the two pages

The dashboard's "Wallet balance" hero uses the brand blue gradient (`from-brand-600 to-brand-800`); My Business's two hero cards use a violet/purple gradient. Same figure (`₹75,611.86`) in two different brand colours across two pages one click apart. Not a stale build — the Vite bundle loads correctly and everything else is on palette.

## Screenshots

- Dashboard (income snapshot, showing D1): `/var/folders/b7/p_3tlw2175b8g23ykwvtr9yc0000gn/T/claude-chrome-screenshots-NbSETp/screenshot-1789040180066-3.jpg`
- My Business (full page top): `/var/folders/b7/p_3tlw2175b8g23ykwvtr9yc0000gn/T/claude-chrome-screenshots-NbSETp/screenshot-1789040316045-4.jpg`

No overlap, no truncation, no misaligned columns, no washed-out styling in either. The My Business tab strip wraps to a second row at 1308 px — intended and legible.

## Mutations made on staging

**None.** Read-only session: one failed login attempt (no lockout triggered, no row written beyond Laravel's own session), page reads, in-page `fetch()` GETs of 28 routes, and read-only `SELECT`s over SSH. Logged out at the end; tab closed.

## Notes for the orchestrator

1. **D1 is the only hard number failure** and it is distributor-facing on the dashboard's headline income tile. One-line fix in `WalletService::creditTotalsByMonth()`; also affects the 6-month chart. Check whether `/income` (T22) shows the same figure.
2. **F17/R-75 is wider than the content page**: the Wed→Tue + 8th cadence is already live to logged-in distributors in dashboard and My Business tooltips. Leaving the `compensation` policy page unpublished does not keep the cadence unseen.
3. `genealogy.downline_stats_visible` has **no settings row** on staging → defaults OFF. T23 should confirm the tree cards read "—" for downline rank/BV.
4. Check 7 (notifications) is **blocked by empty data** — 0 announcements, 0 messages. Re-run after T25/T35.
5. Could not physically resize the Chrome window (stayed 1308×828 through two `resize_window` calls). The 1280×700 sidebar check was verified from the shipped `fit()` formula against the live 775 px column plus an empirical full-scroll test at 828 px. If a physical 700 px check is required, it needs a non-maximised window.
6. `repurchase_cycles` id 1 for d1 carries `fulfilled_on = 2026-10-03` — a **future** date — consistent with F21. The traffic light is unaffected (it caps at month end), but the cycle row is wrong.
7. All three QA passwords work through the real login form (T01 note 2 discharged for ADN 444555666).
