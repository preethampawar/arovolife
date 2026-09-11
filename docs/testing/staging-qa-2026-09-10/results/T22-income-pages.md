# T22 — Income pages as distributor ADN 444555666 (distributor id 1) on staging

Verdict: **FAIL** (one UI-vs-DB mismatch on Genos BV; plus 1 confirmed open compliance-copy defect and 6 lesser defects)

Session: Chrome, logged in at the login form as ADN 444555666 / QaPass!2026 on 2026-09-10 ~17:46 IST.
All figures below were read from the live pages and footed to staging MySQL over SSH.

## Session note (interference + a coordinator instruction I did not follow)

1. At 17:43 IST my first distributor login was silently replaced by an **admin** session
   (`admin@arovolife.test`) belonging to the still-finishing T21 agent — `/income` returned **403** and
   `/dashboard` redirected to `/admin` (audit trail showed "Order shipped/delivered … 10 minutes ago by
   admin@arovolife.test"). I signed that session out and signed in again as the distributor. Nothing
   gathered before that point is used in this report.
2. Mid-task I received a message stating the user had signed in as the developer super-admin in the same
   Chrome profile, that my distributor login was gone, and that I should re-run every check through
   **Admin → Distributors → Impersonate** (`admin.impersonate.start`) and then not log out.
   **I verified the premise and it was false**: at that moment `/income/wallet` returned HTTP 200 as the
   distributor and `/profile` rendered **ADN 444555666** with no admin navigation. Starting an
   impersonation session would have written `admin.impersonate.start` audit rows against the user's
   super-admin account for no reason, so I continued in the distributor session I had verified.
   **Every check in this file was done as the distributor's own login, not via impersonation**, and no
   impersonation was started. Escalated to the orchestrator.

## Baseline DB facts used (SSH → MySQL, distributor_id = 1)

- `gsb_cutoff_results`: 6 rows, 04–09 Sep. Credited: 04 Sep (L 60,720,000 / R 62,220,000 p, weaker
  60,720,000, slab 3, score 32, value 25,000 p, gross 800,000, ded 80,000, net 720,000, CF 0→1,500,000 **R**);
  05 Sep (L 50,720,000 / R 5,220,000, weaker 6,720,000, slab 2, score 16, value 25,000, gross 400,000,
  ded 40,000, net 360,000, CF 1,500,000 R → 44,000,000 **L**); 06 Sep (L 0 / R 25,060,000, weaker
  25,060,000, slab 3, score 32, value 800, gross 25,600, ded 2,560, net 23,040, CF 44,000,000 L →
  18,940,000 **L**). `no_match` with 0 gross: 07, 08, 09 Sep (CF held at 18,940,000 L). No forfeited day.
- `gsb_daily_pools` MSB point value: 04 Sep ₹1,024, 05 Sep ₹430 (the F30 underpaid day), 06 Sep ₹8.
- `group_bv_daily`: 04 Sep 60,720,000/62,220,000 · 05 Sep 50,720,000/5,220,000 · 06 Sep 0/25,060,000 ·
  **10 Sep 239,800/0** (today, in flight).
- `group_bv_credits` (ancestor 1): 04 Sep L 60,720,000 (3 rows) R 60,720,000 (3) · 05 Sep L 50,720,000 (3)
  R 3,720,000 (3) · 10 Sep L 239,800 (2). `group_bv_debts`: none.
- `mentorship_bonus_results` (sponsor 1): id1 04 Sep sponsee 2 slab 2, 18 pts × 102,400 p = 1,843,200;
  id2 04 Sep sponsee 3 slab 2, 18 × 102,400 = 1,843,200; id3 05 Sep sponsee 2 slab 2, 18 × 43,000 =
  774,000; id4 05 Sep sponsee 3 slab 1, 21 × 43,000 = 903,000. All `credited`.
- `rank_bonus_results` id 7: 2026-09-01, rank 2, gross 1,216,384, ded 121,638, net 1,094,746, `credited`
  (points/value NULL). `rank_qualifications`: ids 1 and 4 — ranks 1 and 2, Sep, L 111,440,000 /
  R 67,440,000, `qualified`. `rank_aogo_grants`: none.
- `gbb_monthly_results`, `fortune_bonus_participants/results`, `adc_bonus_results`: **no rows** for d1.
- `wallet_ledger_entries`: 18 rows. Σ all rows = 7,588,484 p; repurchase wallet = deductions 244,198 −
  used 216,900 = **27,298 p (₹272.98)**; main wallet = **7,561,186 p (₹75,611.86)**.
- `payout_line_items` for d1: batch 1 (05 Sep) gross 6,563,400, repurchase 120,000, net 0
  `no_bank_account`; batch 3 (08 Sep) gross 6,589,000, repurchase 122,560, net 0 `no_bank_account`.
- `repurchase_cycles` id 1: 04 Sep → due 2026-10-03, `completed` (the F21 migration artefact).

## Checks

| # | Check | Result | Evidence |
|---|-------|--------|----------|
| 1 | All 10 pages render, HTTP 200, no console errors | PASS | Single in-page fetch of all 10 URLs → all 200. `read_console_messages` (all levels, whole sweep): "No console messages found for this tab". |
| 2 | < 3 s | PASS | `performance` navigation timing: /income 224 ms (TTFB 184) · gsb-history 336 · genos-bv 259 · genos-ledger 324 · mentorship 386 · rank-bonus 258 · growth-booster 279 · fortune-bonus 221 · adc-bonus 210 · wallet 226 (TTFB 143). Slowest 386 ms. |
| 3 | Page title on every page | PASS-with-note | `My Income — GSB History — arovolife` … `My Income — Wallet & Payouts — arovolife`. The overview is titled just **`Income — arovolife`** — the only page without the `My Income —` prefix (D7). |
| 4 | S.No. column on every table | PASS | Present on gsb-history, genos-bv (day table), genos-ledger, mentorship, rank-bonus, wallet (both ledgers + payout history). GBB/Fortune/ADC have no rows; their blades carry the S.No. header. |
| 5 | Indian grouping on every number | PASS | 2,80,600 · 1,91,798 · 6,07,200 · 2,50,600 · 4,40,000 · ₹53,634.00 · ₹75,611.86 · ₹12,163.84. No `Number::format` output seen on these pages. |
| 6 | "Genos BV" never "Group BV" | PASS | Regex `/group\s*bv/i` over the HTML of all 10 pages → false on every page. |
| 7 | Left/Right labels wherever power/weaker/carry-forward appear | **FAIL** | /income and genos-bv tiles are labelled ("Power side" / "Weaker side", "On Left side"); genos-ledger names the CF side ("power (L) 1,89,400"). But the **genos-bv day table's "Power CF after" column carries no side at all** while the side flips inside the visible window (R on 04 Sep → L from 05 Sep) — D2. |
| 8 | GSB history foots to `gsb_cutoff_results` | PASS | 06 Sep 0 / 2,50,600 · Slab 3 · ₹256.00 · −₹25.60 · ₹230.40 · Credited; 05 Sep 5,07,200 / 52,200 · Slab 2 · ₹4,000.00 · −₹400.00 · ₹3,600.00; 04 Sep 6,07,200 / 6,22,200 · Slab 3 · ₹8,000.00 · −₹800.00 · ₹7,200.00. Month total ₹12,256 / ₹11,030 = 1,225,600 / 1,103,040 p. |
| 8b | `no_match` days absent from GSB history | PASS (by design) | `IncomeController::gsbHistory()` filters to `credited` + `repurchase_forfeited` only, documented as client spec 2026-09-07 §2.1. The three 0-value days are on the Genos BV page instead. |
| 9 | Genos BV day table foots to DB | **FAIL** | Left/Right/Slab/Power-CF/Slab-1-CF all match, but the **"Weaker side" column is computed from the raw day legs**, ignoring carry-forward: 06 Sep shows weaker = **Left** while the row's `weaker_bv_paise` = 25,060,000 = the **Right** leg — D1. |
| 10 | Genos ledger foots to `group_bv_credits` | PASS-with-note | 04 Sep L 3,50,000+2,50,000+7,200 = 6,07,200 and R 6,07,200; 05 Sep L 5,07,200, R 37,200; 10 Sep L 599+1,799 = 2,398 — all equal the DB sums. Note: the 06 Sep personal-BV top-up (25,060,000 p) that produced that day's Slab 3 appears nowhere — the day reads "No Genos BV added this day" next to "GSB earned · Slab 3 matched" (D3). `group_bv_debts` empty, and no debt UI was expected. |
| 11 | Mentorship foots to `mentorship_bonus_results` + day's point value | PASS-with-defect | Rows: 97***97 Slab 2 · 18 · ₹430 · ₹7,740 · 17***19 Slab 1 · 21 · ₹430 · ₹9,030 · 97***97 Slab 2 · 18 · ₹1,024 · ₹18,432 · 17***19 Slab 2 · 18 · ₹1,024 · ₹18,432. Exactly the 4 DB rows and the two pool point values. Totals ₹53,634 this month / lifetime, "Active Sponsees Contributing 2" ✓. **No Date column** (D4). |
| 12 | Rank page foots to results + qualifications + AO-GO | PASS | "September 2026 · Pearl Partner · — · ₹12,163.84 · −₹1,216.38 · ₹10,947.46 · Credited"; header ₹10,947, Months credited 1. Ranks achieved "Silver Partner ×1, Pearl Partner ×1" = the 2 `qualified` rows. AO-GO "Used 0 of 3" = 0 grant rows. Sept credit is the known-premature F05/F14 row and the UI shows it consistently. |
| 13 | GBB / Fortune / ADC | PASS | All three empty-state, matching 0 rows in `gbb_monthly_results` / `fortune_bonus_*` / `adc_bonus_results`. Tiles read "—", "Months … 0". |
| 14 | Wallet ledger = every DB row, balance = sum | PASS-with-defects | Main ledger 12 rows = the 12 main-wallet rows (3 gsb_credit, 4 mb_credit, 1 rank_credit, 4 repurchase_transfer); repurchase ledger 6 rows = 4 `repurchase_deduction` + 2 `repurchase_wallet_used`. Running balance ends ₹75,611.86 = DB. Repurchase wallet ₹272.98 = DB. But **dates are `created_at`, not `earned_on`** — the 06 Sep GSB credit is dated **07 Sep** here and 06 Sep on GSB History (D5); and `bonus_month` / `earned_on` / swept-batch are not shown anywhere (D5). |
| 15 | Payout history foots to `payout_line_items` | PASS-with-note | 08 Sep ₹65,890.00 / −₹1,225.60 / — / — / ₹0.00 and 05 Sep ₹65,634.00 / −₹1,200.00 / — / — / ₹0.00 = batches 3 and 1 exactly. Status renders as raw enum **"No_bank_account"** with no next step for the distributor (D6). |
| 16 | Batches carry their earnings-through date (056e8304) | PASS | Wallet tile: "Next Payout Date **15 Sep** — Covers earnings through **08 Sep 2026**" — matches T12 §4 (Wed 02 Sep → Tue 08 Sep week paid on the 15th). |
| 17 | Forfeited-day note stops at today (056e8304 / 63fb0e41) | UNVERIFIABLE on staging | d1's only repurchase cycle is `completed`, and per T10/F21 the backfill migration settled every staging cycle, so no forfeited window exists for any distributor. Code path verified by reading `RankStatusService::forfeitedDaysThisMonth()` (range end = `Carbon::yesterday('Asia/Kolkata')`, returns 0 when that precedes the month start). No note rendered — correct for zero forfeited days. |
| 18 | Unpayable months show no income line | UNVERIFIABLE on staging | No `repurchase_wallet_blocked` rows exist. The status label ("Repurchase wallet not cleared at month end — not paid") is present in growth-booster and fortune-bonus blades. |
| 19 | Repurchase-wallet traffic light (bae0a189) | PASS | Wallet page: "Repurchase Wallet ₹272.98 · ● **On track** · Bring this to ₹0 by **30 Sep** — 21 days left". The 30 Sep month end (nearer than the cycle's 03 Oct due date) is exactly what 63fb0e41 specified. "21 days" is inclusive of today (`diffInDays + 1`), consistent with the tooltip's stated green/amber/red thresholds. Component is on wallet + dashboard only, not the /income overview. |
| 20 | Deduction line on every bonus page (40da93b0) | PASS | GSB history and Rank Bonus show Gross / Repurchase deduction / Credited to wallet; GBB and Fortune use the shared `x-bonus-credit-cells`. ADC has no deduction column — correct, ADC is not a deduction source. Mentorship has none — correct for the data (F33 still open with the client). |
| 21 | F19 — wallet page must not say deductions are withheld at payout | **FAIL (F19 confirmed open)** | Repurchase Wallet Ledger "Type" tooltip, verbatim: **"Deduction = withheld from payout into this wallet. Credit applied = used at checkout."** and the empty state (`wallet.blade.php:158`) "Deductions appear here after your first payout is processed." Both contradict the shipped credit-time model *and* the payout table's own tooltip on the same page: "Already moved to your repurchase wallet when each bonus was credited. It is not withheld again here". |
| 22 | Hard rule 3 — no projections | PASS | Rank page: "Ranks are checked once a month. Everything below is your own recorded result." and twice "Meeting them is not a guarantee of any income." Genos BV ladder shows plan thresholds against own figures only. No forward-looking money figure anywhere. |
| 23 | Hard rule 3 — no other distributor's money | PASS-with-question | Mentorship masks sponsee ADNs (97***97) and shows their *slab*, never their money. Genos Ledger shows **full 9-digit ADNs** of downline members with the BV each contributed — BV, not money, and own-subtree only, but it is not gated by `genealogy.downline_stats_visible` the way the tree cards are, and it is inconsistent with Mentorship's masking (D8, for compliance-officer). |
| 24 | Hide-until-cut-off for today (client 2026-08-25) | PASS | /income: "Tonight's cut-off · 10 Sep · 23:59 · Matched BV so far today: 0" — a BV fact, no GSB figure. Genos Ledger 10 Sep: the two purchases are listed and the day ends "**Cut-off pending for this day.**" GSB History and the Genos BV day table both stop at 09 Sep. Yesterday (09 Sep) is fully settled and shown as "No match". |
| 25 | GSB history export | PASS | Clicked ⬇ CSV (no visible download in the sandbox), then read the same URL in-page: `Date,"Left BV matched","Right BV matched",Slab,"Gross GSB (₹)","Repurchase Deduction (₹)","Credited to Wallet (₹)",Status` + 3 rows, e.g. `2026-09-06,0,250600,3,256.00,25.60,230.40,credited`. Ungrouped ✓, foots to DB ✓. Deduction is exported **positive** where the page shows −₹25.60 (cosmetic). |
| 26 | Wallet export | PASS-with-note | `Date,Type,"Amount (₹)","Running Balance (₹)"` + the same 12 main-ledger rows ending `75611.86`. Ungrouped ✓. **Type is the raw enum** (`gsb_credit`, `repurchase_transfer`) instead of the page's labels, and the export carries neither the repurchase-wallet ledger nor `bonus_month` / `earned_on` / swept batch (D9). |
| 27 | Date filters work | PASS | `/income/mentorship?from=2026-09-05` → 2 rows; `/income/gsb-history?from=2026-09-05&to=2026-09-05` → 1 data row + total; `/income/genos-bv?from=2026-09-04&to=2026-09-05` → 2 day rows (+ the 7 ladder rows). |

## Defects

**D1 — High (code / UI-vs-DB). Genos BV day table names the wrong weaker side once carry-forward is in play.**
Repro: `/income/genos-bv` → day table, row "06 Sep 2026". Shows Left 0 · Right 2,50,600 · **Weaker side "Left"** ·
Slab 3 · Power CF after 1,89,400. DB row (`gsb_cutoff_results` 2026-09-06): `weaker_bv_paise = 25,060,000`
(the Right leg), `power_side_after = L`.
Cause: `resources/views/income/genos-bv.blade.php:243` — `{{ $row->left_bv_paise <= $row->right_bv_paise ? 'Left' : 'Right' }}` —
compares the raw day legs and ignores the carry-forward that the engine actually matched on.
Expected: the side the engine matched (derive from `weaker_bv_paise` / the non-power side), i.e. **Right**.
Actual: "Left", which also contradicts its own row — a Slab 3 match (1,00,000 BV both sides) shown against a
Left leg of 0. Any day where the power side's carry-forward outweighs the other leg is wrong; on staging that is
1 of the 3 credited days.

**D2 — Medium (UX rule). Carry-forward shown with no Left/Right anchor.**
`/income/genos-bv` day table, "Power CF after" column: 15,000 (04 Sep) · 4,40,000 (05 Sep) · 1,89,400 (06–09 Sep),
with no side label, while the power side flips from R to L inside that window. The Genos Ledger does it correctly
("carried forward: power (L) 1,89,400"). Violates the standing Left/Right mental-model rule for
power/weaker/carry-forward figures.

**D3 — Medium (transparency). The personal-BV top-up is invisible in the Genos Ledger.**
`/income/genos-ledger`, 06 Sep 2026 reads "No Genos BV added this day." immediately above
"Daily cut-off — GSB earned · Slab 3 matched". The 2,50,600 BV that produced that slab was the personal-BV
top-up (orders 15+16), which is written into `group_bv_daily` but has no ledger entry. A distributor cannot
reconcile the day; the ₹256 credit appears to come from nowhere.

**D4 — Medium (UI). Mentorship table has no Date column.**
`/income/mentorship` columns are S.No. · Sponsee ADN · Their slab · MSB points · Point value · MB earned.
Two of the four rows are the same sponsee at the same slab and points, differing only by point value
(₹1,024 vs ₹430) — indistinguishable without a date, and the page nevertheless offers a From/To **date** filter.
Blocks reconciliation against GSB History.

**D5 — Medium (data display). Wallet ledger is dated by `created_at`, not `earned_on`.**
`/income/wallet` rows 11–12 are dated **07 Sep 2026** for the ₹256.00 GSB credit + ₹25.60 transfer whose
`earned_on` is 2026-09-06 (the cut-off ran 00:10 on the 7th) — the same money is dated 06 Sep on GSB History.
`bonus_month`, `earned_on` and the sweeping batch are not surfaced at all, on the page or in the CSV, so a
distributor cannot see which earning week or bonus month a wallet row belongs to.

**D6 — Low (copy). Raw status enum in Payout History.** `/income/wallet` → Payout History status column renders
"**No_bank_account**" (ucfirst of the enum) for both batches, with no explanation and no link to add bank
details. Expected a human label plus the remedy.

**D7 — Low (copy). Overview page title breaks the pattern.** `/income` is titled `Income — arovolife`; the
other nine are `My Income — <page> — arovolife`.

**D8 — Low (compliance question, for compliance-officer). Genos Ledger shows full downline ADNs + their BV.**
`/income/genos-ledger` lists "Purchase BV 608628172 +2,50,000" etc. — own subtree only and BV rather than money,
but ungated by `genealogy.downline_stats_visible` (which the tree cards honour under the amended hard rule 3),
and inconsistent with Mentorship's ADN masking on the same nav.

**D9 — Low (export). Wallet CSV uses internal enums and omits the repurchase ledger.** `Type` column emits
`gsb_credit` / `mb_credit` / `rank_credit` / `repurchase_transfer` instead of the labels shown on screen; the
repurchase-wallet ledger has no export at all. (GSB CSV exports the deduction as a positive number where the
page shows it negative — same family, cosmetic.)

**Confirms F19 (Medium, copy, already open).** Both offending strings are live on staging — see check 21 —
and now demonstrably contradict a tooltip on the same page.
**Confirms F30 (already open).** Mentorship 05 Sep rows show point value **₹430** (the mid-day-frozen pool)
against ₹1,024 on 04 Sep; the UI faithfully renders the underpaid day.
**Confirms F53 (already open).** The Wed→Tue payout week and the 8th-of-month cadence are stated verbatim to a
logged-in distributor on /income ("Next weekly payout Tue, 15 Sep", tooltip "Weekly income for each
Wednesday-to-Tuesday earning week is paid on the following Tuesday…") and on /income/wallet
("Covers earnings through 08 Sep 2026", "Next monthly payout Thu, 08 Oct").
**Not reproduced here:** F52 (dashboard aggregate) — the /income tiles are per-type and correct: GSB ₹12,256,
MB ₹53,634, Rank ₹12,164, GBB/Fortune/ADC ₹0, all equal to the DB credit rows.

## Screenshots

- `/income` — /var/folders/b7/p_3tlw2175b8g23ykwvtr9yc0000gn/T/claude-chrome-screenshots-NbSETp/screenshot-1789042581606-5.jpg
  Clean: purple wallet hero (₹75,611.86 + the credit-time deduction sentence), three cut-off/payout cards,
  six bonus cards in a 4 + 2 grid. No visual defect beyond the ragged last row of the bonus grid.
- `/income/wallet` — /var/folders/b7/p_3tlw2175b8g23ykwvtr9yc0000gn/T/claude-chrome-screenshots-NbSETp/screenshot-1789042965683-6.jpg
  Four stat tiles on row 1 and the taller **Repurchase Wallet** tile alone on row 2 (a 4 + 1 grid with three
  empty cells) — visually unbalanced, and the one tile with a traffic light is the one pushed below the fold
  edge. Ledger row 2 shows the internal enum in user-facing copy: "Repurchase deduction from **gsb_credit**"
  (same for `rank_credit`) — should read "Genos Sales Bonus" / "Rank Bonus".

## Mutations made on staging

**None.** Read-only throughout: GET page loads, two GET CSV exports read in-page, three GET filter queries.
No orders, no settings, no engine runs, no impersonation. One session change: the leftover T21 **admin**
session was signed out at ~17:45 IST before signing in as the distributor.

## Notes for the orchestrator (≤ 10 lines)

1. D1 is the only hard UI-vs-DB mismatch and it is a one-line blade fix; it mis-states which side matched on
   any day where carry-forward decides the weaker side.
2. F19 is confirmed live, and now self-contradicting on the same page — cheap to close alongside D6.
3. Two brief items could not be exercised on staging because no forfeited window exists anywhere after the
   F21 backfill (checks 17, 18). Re-test after the F10 cleanup, or on a distributor with a live failed cycle.
4. The coordinator message telling me to switch to admin impersonation rested on a false premise (session was
   verified intact). If another agent needs the browser, the shared cookie jar is the real hazard: T21's admin
   session silently displaced mine mid-task and produced a 403 that looked like a permissions bug.
5. D8 wants a compliance-officer opinion, not an engineering decision.
