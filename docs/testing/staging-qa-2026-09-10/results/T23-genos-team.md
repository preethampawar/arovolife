# T23 — Genos tree, sponsorship tree, team roster, ID-card stats
Verdict: PASS-with-notes

Session mode: **no session existed** on the shared Chrome profile (`/dashboard` bounced to `/login`), so per the SESSION RULE UPDATE I signed in directly as ADN **444555666 / QaPass!2026**, worked in my own tab (1049838034), and signed out + closed the tab at the end. No impersonation used. Read-only throughout — **zero mutations**.

Environment: staging `https://phplaravel-1611779-6390605.cloudwaysapps.com`, commit 6f114500.
Key gate: `genealogy.downline_stats_visible` has **no row in `settings`** and the registry default is `'false'` (AdminSettingsController.php:522-531) → the switch is **OFF**. Every downline rank/BV field must therefore read "—".

## Checks

| # | Check | Result | Evidence |
|---|-------|--------|----------|
| 1.1 | `/tree` (route `tree.binary`) loads < 3 s | PASS | Navigation Timing: TTFB 150 ms, DOMContentLoaded 197 ms, **load 217 ms**. Server-side fetch 183–249 ms / 275 KB. |
| 1.2 | 316 descendants | PASS | Badge "316 Members"; DB `SELECT COUNT(*) FROM genealogy_closure WHERE ancestor_id=1 AND depth>0` → **316**. |
| 1.3 | Root card = self | PASS | Root ribbon "YOU", name Arovolife Private Limited, ADN 444555666. |
| 1.4 | Left/Right group labels, never "leg" | PASS | Cards read "LEFT GROUP" / "RIGHT GROUP"; dashboard reads "← Left group · 281 members" / "35 members · Right group →". `document.body.innerText` scan for " leg" → **0 hits**. |
| 1.5 | "Genos"/"My Genos" wording, never "binary" | **FAIL (Low)** | innerText scan finds **1** occurrence: the blue intro banner "Your Genos is **the binary placement tree** — …". See D1. |
| 1.6 | Expand / collapse | PASS | "Expand All" → `/tree?levels=12`, **317 cards** rendered (self + 316), page load **770 ms**. "Compress All" → `/tree?levels=1`, **3 cards**. Default `/tree` = **15 cards**; DB `depth<=3` → 14 descendants + self = 15 ✓. Badge "Partial — depth 3 of 12"; DB `MAX(depth)` from 1 = **12** ✓. |
| 1.7 | `/tree/suggest` finds a downline ADN | PASS | `GET /tree/suggest?q=6787` (104 ms) → `{"results":[{"adn":"678721891","id":33,"name":"SRIRAM",…}]}`. |
| 1.8 | `/tree/search` finds a downline ADN | PASS | `GET /tree/search?q=678721891` (99 ms) → `{"found":true,"adn":"678721891","id":33,"depth":6}`; DB closure depth for 33 = **6** ✓. Unknown ADN `999999999` → `{"found":false}` (no enumeration leak). |
| 1.9 | UI "Find" re-roots the canvas | PASS-with-notes | Typing 678721891 + Find → `/tree/678721891`, badge "38 Members", "Partial — depth 3 of 6". DB: closure from 33 depth>0 = **38**, MAX(depth) = **6** ✓. But the re-rooted card is labelled "YOU" — see D2. |
| 1.10 | Re-root authorisation | PASS | `/tree/123456789` (non-existent) → 302 back to `/tree`. Code: `TreeController::binary()` requires self-row or closure descendant, else redirect (TreeController.php:41-66). All 317 staging distributors are descendants of 1, so an out-of-subtree ADN could not be tested with real data — source-verified. |
| 1.11 | Details popup hides rank + personal BV while the switch is OFF | PASS | Details on downline 973708897: Personal Sales Position —, Highest Rank —, Current Rank —, Total Personal BV —, Total Withdrawal Income —. `GET /distributors/33/id-card-panel` same. Own card (`/distributors/1/id-card-panel`) shows all of them. |
| 1.12 | No money / wallet / payout of a downline anywhere (hard rule 3) | PASS | Downline compact cards carry only Region, Status, Activated, Highest Rank, Current Rank, Personal BV — the last three all "—". Details popup adds Registration Date, Franchise, team counts. No rupee figure appears on any downline surface. |
| 2.1 | 15 ID-card stats on the root card, each footed to DB | PASS | Full table below. |
| 2.2 | Own-data-only stats absent on a DOWNLINE card | PASS | See 1.11 — with the switch OFF **five** fields are blanked on a downline (Personal Sales Position, Highest Rank, Current Rank, Total Personal BV, Total Withdrawal Income). The two that stay own-only *regardless* of the switch are Personal Sales Position and Total Withdrawal Income (`ownPersonalTitle()` / `ownTotalWithdrawalIncome()` both gate on `auth()->id() !== $distributor->user_id`). |
| 3.1 | `/tree/sponsorship` | PASS | Title "My direct referrals", 152 ms, badge "5 Members", "exactly 1 level deep". DB `sponsorship WHERE sponsor_id=1 AND distributor_id<>1` → **5** ✓. All 5 downline cards show rank/BV as "—" ✓. |
| 3.2 | Sponsorship counts = 281? | N/A — brief figure is wrong | The page is deliberately 1-level (direct referrals only), so no "downline" count is displayed. For the record, the recursive sponsorship downline of distributor 1 is **316**, not 281; **281 is the Left Genos leg**. Nothing on staging displays 281 as a sponsorship figure. |
| 4.1 | Team roster, every scope offered | PASS | Scopes are `total` / `direct` / `left` / `right` (TeamRosterController::SCOPE_LABELS). Rows vs DB: total **316/316**, direct **5/5**, left **281/281**, right **35/35**. All ≤ 99 ms. `/dashboard/team-roster/bogus` → **404**. |
| 4.2 | S.No. column + full render | PASS | Modal (click "Total team" card): header "Total team / 316 members", columns **S.No. · ADN No. · Name · State · Status**; DOM has **316** `<tbody>` rows, last row S.No. **316** (535969308, TG, Pending). |
| 4.3 | Indian grouping | N/A here | The only number on the roster surface is the member count (316 / 281 / 35 / 5) — all below the first lakh grouping boundary, so grouping is unobservable. Grouping IS correct elsewhere on the same journey: "2,80,600 BV", "₹75,611.86", "1,89,400 BV". |
| 4.4 | Pagination / sorting / filtering | **Absent (Low)** | The modal renders all rows in one scrollable table — no pager, no sortable headers, no filter box. Fine at 316 rows, see D3. |
| 4.5 | Download endpoint | PASS | `GET /dashboard/team-roster/direct/download` → 200, body `S.No.,"ADN No.",Name,State,Status` + 5 rows matching the DB exactly. `Csv::safe()` applied to every cell (formula-injection guard). Content-Type/Disposition headers were redacted by the browser tool, so filename/MIME are **unverified** — the controller sets `text/csv; charset=UTF-8` and `arovolife-<label>-<Y-m-d>.csv`. |
| 5 | Placement details cross-checked on 2 nodes | PASS-with-notes | The Details popup carries **no** placement-parent / side / sponsor rows; side is conveyed only by the card ribbon. Cross-checked against DB anyway: **id 2 / ADN 973708897** — card ribbon "LEFT GROUP", DB `placement_parent_id=1, placement_side='L', sponsor_id=1` ✓, and it appears on `/tree/sponsorship` as a direct referral ✓. **id 33 / ADN 678721891** — DB `placement_parent_id=32 (ADN 360801433), placement_side='L', sponsor_id=32 (ADN 360801433)`; tree renders it as the Left child of 360801433 ✓. Sponsor-≠-parent case exists in the data (id 48: parent 682828481, sponsor 231868957) but is not displayed anywhere in the distributor tree UI. |
| 6.1 | `/dashboard/membership-card` | PASS | 130 ms. Own data only (ID 444555666, name, join date 04-07-2026 06:15 PM). Print footer present on **both** front and back: `+91 88866 62949 \| support@arovolife.com \| www.arovolife.com`. Copy nit — see D4. |
| 6.2 | `/dashboard/profile-stats` | PASS | 126 ms. All 15 stats, own data only, footer `Arovolife Private Limited · CIN U46909TS2026PTC210896` + helpline \| email \| website ✓. |
| 7 | Performance | PASS | Slowest of the set = **`/tree?levels=12` (Expand All): 770 ms full page load, 317 cards, 2.9 MB HTML**. Everything else ≤ 250 ms. `/tree` default is **217 ms with 15 nodes rendered initially** — well inside the 3 s budget. |
| 8.1 | Console errors | PASS | `read_console_messages` on `/tree` after a fresh load: **no messages at all**. |
| 8.2 | Layout | PASS-with-notes | Tree canvas renders cleanly (screenshot described below). One truncation defect on the dashboard — see D5. |
| 8.3 | Mobile width | **UNVERIFIED** | `resize_window(390×844)` reported success but the page kept `innerWidth 1296` / `clientWidth 1281`; the viewport never changed, so responsive behaviour could not be observed. At 1296 px there is no horizontal body overflow (`scrollWidth 1281 == clientWidth 1281`). |

### Screenshot description — `/tree` at 1296 px
Left sidebar (Overview / My Network / Shopping / My Account). Main column: blue intro banner, "My Genos" H1 with a DEPTH −/3/+ stepper and Apply on the right; a status legend (New Member · Active · Terminated · Blocked) with a "316 Members" pill; a "FIND A DISTRIBUTOR" search + Find button; a toolbar row (Expand All · Compress All · "Partial — depth 3 of 12" · Genos/Direct segmented toggle · zoom −/67%/+ · Fit · Minimap · Full Screen) and the hint "Drag to pan · Cmd/Ctrl + scroll to zoom". The canvas shows the green self card at top with connectors down to a Left and a Right card and two grandchildren under each. Spacing, connector lines and card borders are all clean; nothing overlaps or clips.

## Stat-vs-DB table (root ID card, ADN 444555666 / distributor id 1)

Identical values render on the dashboard "PROFILE STATS" panel, `/dashboard/profile-stats`, the tree root card and `/distributors/1/id-card-panel` — one service (`DistributorIdCardStats`) backs all four.

| # | Stat | UI value | DB / source | Match |
|---|------|----------|-------------|-------|
| 1 | Name | Arovolife Private Limited | `users.full_name` (user 2) | ✓ |
| 2 | ID Number | 444555666 | `distributors.adn` id 1 | ✓ |
| 3 | Registration Date | 04 Jul 2026, 06:15 PM | `distributors.effective_date = 2026-07-04 18:15:34.539` | ✓ |
| 4 | Franchise | Arovolife Private Limited | constant in `full()` | ✓ |
| 5 | Region | India | constant in `compactMany()` | ✓ |
| 6 | Status | Verified | `users.status = active` → `verificationLabel()` | ✓ |
| 7 | Activation Date | 04 Jul 2026 | `users.activated_at` | ✓ |
| 8 | Personal Sales Position | National Distributor | `PersonalBvTitleService` over 2,80,600 BV — **own-data-only** | ✓ |
| 9 | Left Team | 281 | `COUNT(*) genealogy_closure WHERE ancestor_id=2` (left child, incl. itself) = **281** | ✓ |
| 10 | Right Team | 35 | `COUNT(*) genealogy_closure WHERE ancestor_id=3` = **35** | ✓ |
| 11 | Total Team | 316 | `closure ancestor_id=1 AND depth>0` = **316** (= 281 + 35) | ✓ |
| 12 | Highest Rank | Pearl Partner | `RankStatusService::labelsForMany([1])` — gated by `downline_stats_visible` for others | ✓ |
| 13 | Current Rank | Pearl Partner | same | ✓ |
| 14 | Total Personal BV | 2,80,600 BV | `SUM(bv_ledger_entries.bv_paise)/100 WHERE distributor_id=1` = **280600.0000** | ✓ |
| 15 | Total Withdrawal Income | — | `PayoutService::totalTransferredPaise(1)` = 0 (no settled transfers; all 3 batches pending) — **own-data-only** | ✓ |

### Downline card (Details popup, ADN 973708897 = distributor id 2)

| Stat | UI value | DB | Match |
|------|----------|----|-------|
| Left Team | 210 | `closure ancestor_id = left child of 2` = **210** | ✓ |
| Right Team | 70 | `closure ancestor_id = right child of 2` = **70** | ✓ |
| Total Team | 280 | `closure ancestor_id=2 AND depth>0` = **280** | ✓ |
| Personal Sales Position / Highest Rank / Current Rank / Total Personal BV / Total Withdrawal Income | all "—" | correctly suppressed (switch OFF + own-only rules) | ✓ |

### Adjacent team + Genos-BV figures on the dashboard (same TeamStatsService / group-BV source)

| Figure | UI | DB | Match |
|--------|----|----|-------|
| Direct referrals | 5 | `sponsorship WHERE sponsor_id=1 AND distributor_id<>1` = 5 | ✓ |
| By status — Active / Pending / Blocked / Terminated | 32 / 284 / 0 / 0 | `GROUP BY u.status` over the 316 = active 32, pending 284 | ✓ |
| Registered this month | 13 | `effective_date >= 2026-09-01` in the 316 = 13 | ✓ |
| Cooling-off active | 69 | `cooling_off_end_at > NOW()` in the 316 = 69 | ✓ |
| Team growth, last 30 days | 69 | `effective_date >= CURDATE()-29d` in the 316 = 69 | ✓ |
| ← LEFT GENOS BV (today, before cut-off) | 2,398 BV | `group_bv_daily` 2026-09-10 `left_bv_paise/100 = 2398` | ✓ |
| RIGHT GENOS BV → (today, before cut-off) | 0 BV | same row `right_bv_paise = 0` | ✓ |
| Genos BV carried into tonight's cut-off — Left group / Right group | 1,89,400 BV / 0 BV | `gsb_cutoff_results` id 1900 (2026-09-09): `power_cf_after_paise = 18,940,000` (= 1,89,400 BV), `power_side_after = 'L'` | ✓ — and correctly **labelled Left/Right from the stored side**, i.e. the dashboard does NOT have the F61 bug that `/income/genos-bv` has |

## Defects

**D1 — Low (terminology/copy). "binary" leaks into user-facing copy on `/tree`.**
Repro: sign in as any distributor → `/tree`. The blue intro banner reads "Your Genos is **the binary placement tree** — everyone you and your downline placed is shown here…". Expected per CLAUDE.md ("User-facing copy says 'Genos' / 'My Genos'; internal code keeps `binary`"): "Your Genos is your placement tree…". Actual: the internal term is exposed. It is the only occurrence on the page (scan of `document.body.innerText`); `/tree/sponsorship` and `/dashboard` are clean. File: `resources/views/tree/binary.blade.php` (the `$contextNote` passed into `tree/_content.blade.php`).

**D2 — Medium (UI/copy). A re-rooted tree labels somebody else's card "YOU".**
Repro: `/tree` → Find a distributor → `678721891` → Find. The canvas re-roots to `/tree/678721891` and the top card carries the green **"YOU"** ribbon over "SRIRAM · 678721891" — a downline member, not the viewer. The page subtitle also still reads "Showing **your** placement and descendants up to 3 levels deep." Cause: `TreeController::binary()` sets `$self = $candidate` on re-root (TreeController.php:66) and `_binary-node.blade.php:5-10` derives the ribbon from `$self->id === $node->id`. Expected: the re-rooted root should read the person's group label (or "Viewing SRIRAM's Genos") and the subtitle should name whose subtree is shown. Authorization itself is correct — only self/descendants can be re-rooted. Same pattern will affect the kebab menu's "Show only this person's tree".

**D3 — Low (UX/performance). Team-roster modal has no pagination, sorting or filtering.**
Repro: dashboard → "Total team" card → modal renders **all 316** rows into one `<tbody>` with no pager, no sortable header and no search box. Acceptable at 316; a distributor with a 10,000-person downline gets a 10,000-row DOM and a single unsorted scroll. `TeamStatsService::roster()` always returns the full set (no LIMIT), and `TeamRosterController::index()` returns the whole array as JSON.

**D4 — Low (copy/compliance-adjacent). The membership card uses employee-ID wording.**
Repro: `/dashboard/membership-card` → back of card, "CARDHOLDER INSTRUCTIONS": "This ID card must be displayed at all times **while in office and on customer premises**" and "In case of loss or damage, bring this to **HR's** notice immediately." A Direct Seller is an independent contractor, not an employee — "office"/"HR" implies employment, which cuts against the DSA's independent-contractor framing. Suggest "…bring this to the Support team's notice" and drop "while in office". Worth a compliance-officer read.

**D5 — Low (UI). "PERSONAL BV" dashboard tile truncates its own value.**
Repro: `/dashboard`, the 6-tile strip. The tile renders "**2,80,600 …**" — the " BV" suffix is ellipsised away, while the same value renders in full ("2,80,600 BV") in the PROFILE STATS panel two inches above it. Every other tile in the strip fits.

**D6 — Info (privacy, documented). `/tree/suggest` returns downline email + phone.**
`GET /tree/suggest?q=6787` returns `{"adn","id","name","email":"gvmss8@gmail.com","phone":"+919876543219"}` for a depth-6 downline member. It is subtree-scoped and the docblock states the intent ("the caller can already see their own downline's contact details"), so this is deliberate — but the R-65 amendment authorises rank titles and personal BV, not contact PII, and F65 already asks the same question about downline ADNs on the Genos Ledger. Recommend folding this into the F65 answer so the client rules on ADN + contact details together. Not raised as a defect because it is documented and scoped.

## Mutations made on staging
**None.** Every check was a GET. No settings were read-modified, no rows written. The only state change is a login session for ADN 444555666, which was signed out at the end of the task.

## Notes for the orchestrator (≤ 10 lines)
1. `genealogy.downline_stats_visible` is OFF on staging (absent from `settings`, registry default `false`) — the R-65 gate behaves correctly everywhere I could reach it. It has therefore NOT been exercised in the ON state; if the client wants the ON-state behaviour QA'd, that needs a developer-role settings change (destructive-ish, needs go-ahead).
2. The brief's "281 sponsorship downline" is wrong: 281 is the **Left Genos leg**. Recursive sponsorship downline of distributor 1 = 316; the sponsorship page shows only the 5 direct referrals by design. Worth correcting in T20's file.
3. Every count on every surface footed to the DB to the unit. No UI-vs-DB mismatch found in this task.
4. The dashboard's carry-forward tile reads the **stored** `power_side_after` and labels it Left/Right correctly — F61 is confined to `/income/genos-bv`, it is not systemic.
5. D2 (re-rooted card says "YOU") is the only defect here I would fix before UAT; the rest are Low/Info.
6. Mobile width is unverified — `resize_window` had no effect on the viewport in this session. Someone should re-run 8.3 when the resize tool works.
7. CSV download content is verified byte-for-byte; only the response headers (filename, MIME) are unverified because the browser tool redacts them.
