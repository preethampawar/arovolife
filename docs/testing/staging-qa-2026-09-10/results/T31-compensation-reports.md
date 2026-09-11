# T31 — Compensation reports, Input & Output reports, and exports foot to the database

Verdict: PASS-with-notes — every figure foots to the paise; 9 presentational defects (0 Critical, 1 High, 4 Medium, 4 Low)

Session mode: **No session existed** (staging was at `/login`). I signed in myself as
`admin@arovolife.test` (plain admin role, NOT the developer super-role). I will sign out at
the end. All work in my own MCP tab. Read-only: no engine triggered, no setting changed,
no row written.

SQL source: `sshpass … master_mvgumpkwtu@139.59.92.229` → `mysql -u ahdhesuhty … ahdhesuhty`
(read-only SELECTs only).

## Checks

| # | Report | Page figure | SQL figure | Foots? | Evidence |
|---|---|---|---|---|---|
| 1 | `daily-cutoffs` default (today 10 Sep) | "No cut-off data for 10 Sep 2026. GSB engine not yet active." | `gsb_cutoff_results` max(cutoff_date)=2026-09-09 | ✅ hide-until-cut-off works | today's cut-off runs 00:10 tomorrow |
| 2 | `daily-cutoffs?date=2026-09-09` row count | "Showing 1 to 50 of **317** results" | `count(*) where cutoff_date='2026-09-09'` = **317** | ✅ | statuses: 7 no_match + 310 below_600bv (page shows same) |
| 3 | `daily-cutoffs/2026-09-05` gross GSB | ₹4,000.00 + ₹4,000.00 + ₹2,000.00 = **₹10,000.00** | `sum(gross_gsb_paise)` = **1,000,000 p** = ₹10,000.00 | ✅ to the paise | 3 credited rows (ADN 444555666, 973708897, 177536419) |
| 4 | `daily-cutoffs/2026-09-05` credited to wallet | ₹3,600 + ₹3,600 + ₹1,800 = **₹9,000.00** | `sum(net_gsb_paise)` = **900,000 p** | ✅ | |
| 5 | `daily-cutoffs/2026-09-05` repurchase deduction | −₹400 −₹400 −₹200 = **₹1,000.00** | `sum(repurchase_deduction_paise)` = **100,000 p** | ✅ | 10% of gross, matches forfeit model |
| 6 | `daily-cutoffs/2026-09-05` row count | Showing 1 to 50 of **317** | 317 | ✅ | 3 credited / 4 no_match / 310 below_600bv, identical on page |
| 7 | `daily-cutoffs/export?date=2026-09-05` CSV | 1 header + **317** data rows | 317 | ✅ | header `ADN,Name,Left BV,Right BV,Slab,Gross GSB (Rs),Repurchase Deduction (Rs),Credited to Wallet (Rs),Status` |
| 8 | CSV column totals | Gross **10000**, Repurchase **1000**, Wallet **9000**, Left BV **786600**, Right BV **317200** | 1,000,000 p / 100,000 p / 900,000 p / 78,660,000 p / 31,720,000 p | ✅ all five | summed in-browser over the whole CSV |
| 9 | CSV number formatting | 0 lines match lakh grouping `\d,\d\d,\d\d\d`; values like `0.00`, `4000.00` | — | ✅ ungrouped as required | |
| 10 | CSV PII | Only ADN + Name; no PAN/Aadhaar/bank/email/phone columns | — | ✅ | |
| 11 | On-page lakh grouping | `5,07,200 BV`, `2,57,200 BV` (Indian grouping), money `₹4,000.00` | — | ✅ | BV shown in BV, not paise |
| 12 | `personal-bv-topups?date=2026-09-06` | 2 rows, `2,50,000 BV` + `600 BV` = **2,50,600 BV** | `sum(bv_paise) where date='2026-09-06'` = **25,060,000 p** = 250,600 BV | ✅ | side shown as "Right" (word, not L/R code) |
| 13 | `personal-bv-topups/export?date=2026-09-06` | 1 header + **2** rows; `250000`, `600` ungrouped | 2 rows | ✅ | header `Date,ADN,Name,Order ID,BV,Side,Type,Reversed At`; PII = ADN + Name only |
| 14 | `carry-forwards` power-side CF total | `1,89,400` + `85,600` + `85,600` (+4 × 0 BV) = **3,60,600 BV** | `sum(power_side_bv_paise)` = **36,060,000 p** = 360,600 BV | ✅ | 7 rows on page = 7 rows in `gsb_carryforward` |
| 15 | `carry-forwards` slab-1 weaker CF | all rows `0 BV` | `sum(slab1_weaker_bv_paise)` = **0** | ✅ | |
| 16 | `carry-forwards` power-side labelling | 5 × `L`, 2 × `R` | `group by power_side` → L=5, R=2 | ✅ | side named explicitly (single letter, see F-T31-03) |
| 17 | `genos-transactions` (BV Credits tab) | 24 rows, BV column sums to **34,93,596 BV** (hand-summed from the page) | `count(*)`=**24**, `sum(bv_paise)`=**349,359,600 p** = 3,493,596 BV | ✅ to the paise | |
| 18 | `genos-transactions` side split | L = 12 rows / **18,81,798 BV**; R = 12 rows / **16,11,798 BV** | `group by side`: L 12 / 188,179,800 p; R 12 / 161,179,800 p | ✅ | |
| 19 | `genos-transactions` BV Reversals tab | empty | `count(*) from group_bv_reversals` = **0** | ✅ | |
| 20 | `genos-transactions/export` | 1 header + **24** rows; BV total **3493596**, Debt Consumed **0** | 24 / 349,359,600 p / 0 | ✅ | header `SNo,ADN,Name,Title,Side,Order ID,Order Date,BV,Debt Consumed BV`; ungrouped; PII = ADN + Name + rank title only |
| 21 | `gsb-calculation` Sept grand total — Income | **₹26,256.00** | `sum(gross_gsb_paise)` Sept credited = **2,625,600 p** | ✅ | 7 credited rows |
| 22 | `gsb-calculation` grand total — Repurchase deduction | **−₹2,625.60** | `sum(repurchase_deduction_paise)` = **262,560 p** | ✅ | exactly 10% of gross |
| 23 | `gsb-calculation` grand total — Credited to wallet | **₹23,630.40** | `sum(net_gsb_paise)` = **2,363,040 p** | ✅ | also = `wallet_ledger_entries` gsb_credit 2,625,600 p gross less the 262,560 p repurchase_deduction rows |
| 24 | `gsb-calculation` grand total — Score | **136** | `sum(score)` = **136** | ✅ | |
| 25 | `gsb-calculation` per-day header 04/09 | Day BV `12,29,400 BV`, pool `₹5,53,230.00`, fixed `₹8,000.00`, score 32, value `₹250.00` cap `₹250.00` | `gsb_daily_pools` 2026-09-04: company_bv 122,940,000 p, pool 55,323,000 p, fixed 800,000 p, variable_total_score 32, value 25,000 p, cap 25,000 p | ✅ all six | |
| 26 | `gsb-calculation` per-day header 05/09 | Day BV `5,59,400 BV`, pool `₹2,51,730.00`, fixed `₹10,000.00`, score 0, **Computed 05 Sep 2026 14:03** | pool row 2: 55,940,000 / 25,173,000 / 1,000,000 / 0, created_at `2026-09-05 14:03:29` | ✅ (**F30 visible on-page**) | page shows the mid-day compute time but gives no warning — see F-T31-01 |
| 27 | `gsb-calculation` per-day header 06/09 | Day BV `600 BV`, pool `₹270.00`, score 32, value `₹8.00` (cap `₹250.00`) | pool row 3: 60,000 / 27,000 / 32 / 800 / 25,000 | ✅ | |
| 28 | `gsb-calculation/export` | 7 data rows + a `TOTAL` line reading `136, 26256.00, 2625.60, 23630.40` | matches SQL above | ✅ | header `SNo,ADN,Name,Title,Date,Slab,Score,Score Value (Rs),Income (Rs),Repurchase Deduction (Rs),Credited to Wallet (Rs),Status`; ungrouped; PII = ADN + Name + rank title |
| 29 | `gsb-input-output` 04/09 grand total / leftover | `₹16,000.00` / `−₹1,600.00` / `₹14,400.00`, leftover `₹5,37,230.00` | gross 1,600,000 p, ded 160,000 p, net 1,440,000 p, `leftover_paise` 53,723,000 | ✅ | fixed ₹8,000 + variable ₹8,000 splits match slab 2 / slab 3 rows |
| 30 | `gsb-input-output` 05/09 grand total / leftover | `₹10,000.00` / `−₹1,000.00` / `₹9,000.00`, leftover `₹2,41,730.00` | 1,000,000 / 100,000 / 900,000 / 24,173,000 | ✅ | |
| 31 | `gsb-input-output` 06/09 grand total / leftover | `₹256.00` / `−₹25.60` / `₹230.40`, leftover `₹14.00` | 25,600 / 2,560 / 23,040 / 1,400 | ✅ | |
| 32 | `gsb-input-output` achiever counts | 04/09 slab 2 = 2 achievers score 32, slab 3 = 1 achiever score 32; 05/09 slab 1 = 1 score 8, slab 2 = 2 score 32; 06/09 slab 3 = 1 score 32 | `gsb_cutoff_results` rows agree one-for-one | ✅ | |
| 33 | `gsb-input-output/export` | 11 data rows (6 DAY TOTAL + 5 slab rows); last line `1,1,2026-09-04,1229400.00,553230.00,…,16000.00,1600.00,14400.00,"leftover 537230.00"` | matches | ✅ | ungrouped; contains no distributor identifiers at all |
| 34 | `msb-calculation` Sept grand total | **75 points / ₹53,634.00** | `mentorship_bonus_results`: `sum(msb_points)`=**75**, `sum(mb_gross_paise)`=**5,363,400 p** | ✅ | also equals `wallet_ledger_entries` mb_credit total 5,363,400 p (4 rows) |
| 35 | `msb-calculation` per-day header 04/09 | pool `₹36,882.00`, points 36, value `₹1,024.00` | `msb_daily_pools` 2026-09-04: 3,688,200 p / 36 / 102,400 p | ✅ | |
| 36 | `msb-calculation` per-day header 05/09 | pool `₹16,782.00`, points 39, value `₹430.00`, **Computed 05 Sep 2026 14:03** | pool row 2: 1,678,200 p / 39 / 43,000 p, created_at 14:03:29 | ✅ | F30 |
| 37 | `msb-input-output` 04/09 income / leftover | `₹36,864.00` / `₹18.00` | `payout_paise` 3,686,400 / `leftover_paise` 1,800 | ✅ | 36 pts × ₹1,024 = ₹36,864 arithmetic shown on page |
| 38 | `msb-input-output` 05/09 income / leftover | `₹16,770.00` / `₹12.00` | 1,677,000 / 1,200 | ✅ | |
| 39 | `msb-input-output` 06/09 | pool `₹18.00`, 0 points, leftover `₹18.00`, plus an explanatory note that the day's point value is frozen at ₹0 | 1,800 / 0 / 1,800 | ✅ | good copy |
| 40 | **F33 — repurchase deduction on MSB** | Both MSB reports have **no repurchase-deduction column at all**; "Income" is the gross and equals what reaches the wallet | `mentorship_bonus_results` has no repurchase column; no `repurchase_deduction` ledger row references an MSB result | ✅ consistent with F33 (MSB is excluded as a deduction source) | the reports never *state* the exclusion — see F-T31-04 |
| 41 | `msb-calculation/export` | 4 rows + `TOTAL` line `75, 53634.00` | matches | ✅ | header `SNo,Sponsor ADN,Sponsor Name,Title,Date,Sponsee ADN,Sponsee Name,MSB Points,Value (Rs),Income (Rs),Status`; ungrouped; PII = ADN + Name only |
| 42 | `msb-input-output/export` | 8 data rows; last `1,1,2026-09-04,1229400.00,36882.00,,"DAY TOTAL",36,1024.00,36864.00` | matches | ✅ | ungrouped |
| 43 | `gbb-calculation?month=2026-09` | Month BV `17,88,800 BV`, pool (5%) `₹89,440.00`, Total AGP 0, "No GBB records found" | `gbb_monthly_pools` 2026-09-01: company_bv 178,880,000 p, pool 8,944,000 p, total_agp 0, payout 0; `gbb_monthly_results` **0 rows** | ✅ | worked example on the page recomputes 17,88,800 × 5% = ₹89,440 correctly |
| 44 | `gbb-input-output?month=2026-09` leftover | `₹89,440.00` | `leftover_paise` **8,944,000** | ✅ | page also names the 3 ADNs who earned AGP **after** the pool was frozen and states plainly they were not paid — excellent, honest copy |
| 45 | `gbb-calculation/export` + `gbb-input-output/export` | header-only (0 rows) / 1 `MONTH TOTAL` row `2026-09,1788800.00,89440.00,0,0.00,…,"leftover 89440.00"` | matches | ✅ | ungrouped |
| 46 | `rb-calculation?month=2026-09` rows | 3 Rank-1 rows + 1 Rank-2 row; grosses `₹5,005.00`, `₹10,010.00`, `₹10,010.00`, `₹12,163.84` | `rank_bonus_results` ids 6,5,4,7: 500,500 / 1,001,000 / 1,001,000 / 1,216,384 p | ✅ each row to the paise | net + repurchase per row also match (450,450 / 900,900 / 900,900 / 1,094,746) |
| 47 | `rb-calculation` Silver pool & point value | pool `₹25,043.20`, qualifiers 2, points 25, value `₹1,001.00` | `pool_paise` 2,504,320, qualifier_count 2, total_points 25, point_value_paise 100,100 | ✅ | on-page derivation `(2×10)+5 = 25`, `⌊₹1,001.72⌋ = ₹1,001` is correct |
| 48 | `rb-input-output?month=2026-09` month totals | Income `₹37,188.84`, deduction `−₹3,718.88`, credited `₹33,469.96`, leftover `₹18.20` | `sum(gross_paise)` **3,718,884 p**, `sum(repurchase_deduction_paise)` **371,888 p**, `sum(net_paise)` **3,346,996 p**; Silver leftover 2,504,320 − 2,002,000 − 500,500 = **1,820 p** | ✅ all four | also = `wallet_ledger_entries` rank_credit total 3,718,884 p |
| 49 | `rb-input-output` rank pool percentages | Silver 7%, Pearl 3.4% → pools `₹25,043.20` / `₹12,163.84` | `rank_tiers.pool_pct` 7.00 / 3.40; envelope 178,880,000 × 20% = 35,776,000 p | ✅ | ranks 3–9 correctly marked `*` "estimated … nothing was frozen" |
| 50 | `rb-calculation/export` + `rb-input-output/export` | 4 rows summing 37188.84 / 3718.88 / 33469.96; I&O 11 rows + `MONTH TOTAL` line with the same three figures | matches SQL | ✅ | ungrouped; PII = ADN + Name + rank title + centre name |
| 51 | `fb-calculation?month=2026-09` | pool `₹89,440.00`, qualifiers 0, points 0, paid `₹0.00`, leftover `₹89,440.00`, "No Fortune Bonus records found" | `fortune_monthly_pools` 2026-09-01: pool 8,944,000 p, total_points 0, payout 0, leftover 8,944,000; `fortune_bonus_results`/`_participants`/`_pool_levels` all **0 rows** | ✅ | per-level caps (₹30,000…₹30) shown match the 2026-09-03 client caps memo |
| 52 | `fb-calculation/export` | header-only, 0 rows | 0 results | ✅ | |
| 53 | `aw-rw-calculation?month=2026-09` | Milestones 3, Delivered 0, Award worth `₹60,000.00`, Cash net `₹0.00`; breakdown Silver 2 × ₹15,000, Pearl 1 × ₹30,000 | `lifetime_award_milestones`: 3 rows (2 × rank 1, 1 × rank 2), all `pending`, all `net_paise` NULL; `rank_tiers.lifetime_award_budget_paise` 1,500,000 / 3,000,000 | ✅ | tile is honestly labelled "current budgets" because the milestone rows carry no frozen value |
| 54 | `aw-rw-calculation/export` | 3 rows, cash reward `—` on all, status `pending` | 3 rows | ✅ | ungrouped |
| 55 | `adc-calculation?month=2026-09` | Centres paid 1, Collected BV `3,50,000 BV`, rate 3%, bonus `₹10,500.00` | `adc_bonus_results`: 1 row, total_attributed_bv 35,000,000 p, gross 1,050,000 p, net 1,050,000 p, order_count 1 | ✅ | also = `wallet_ledger_entries` adc_credit 1,050,000 p |
| 56 | `adc-calculation/export` | 1 row `…,350000.00,3.00,10500.00,0.00,10500.00,"credited"` | matches | ✅ | ungrouped; `Pincode,District,State` columns present but empty for this centre |
| 57 | `gbb` (index) | "No GBB batches yet — **engine has not yet run**." | `gbb_monthly_pools` has **2 rows** (2026-09 frozen 05 Sep 14:03, 2026-08 frozen 10 Sep 12:48) — the engine *has* run | ❌ **wrong statement** | index only reads `GbbMonthlyResult` (`AdminGbbController::index`), never the pool table — F-T31-02 |
| 58 | `gbb/2026-08` | Company BV `₹0.00`, pool `₹0.00`, AGP 0, payout `₹0.00`, leftover `₹0.00` | `gbb_monthly_pools` 2026-08-01: all zeros | ✅ figures foot | but "Company **BV** `₹0.00`" prints BV as rupees — F-T31-03 |
| 59 | `gbb/2026-09` | Company BV `₹17,88,800.00`, pool `₹89,440.00`, leftover `₹89,440.00` | 178,880,000 p BV / 8,944,000 p / 8,944,000 p | ✅ figures foot | same BV-as-₹ mislabel |
| 60 | `rank-bonus` (index) | 1 batch: September 2026, **4** distributors, `₹37,188.84` / `−₹3,718.88` / `₹33,469.96`, credited 05 Sep 14:49 | `rank_bonus_results`: 4 rows, 3,718,884 / 371,888 / 3,346,996 p, credited_at 2026-09-05 14:49:52 | ✅ | |
| 61 | `rank-bonus/2026-08` | "No Rank Bonus results for this month." | 0 rows for 2026-08-01 | ✅ | (there *is* 1 `rank_qualifications` row for Aug that produced no bonus — outside T31's scope) |
| 62 | `rank-bonus/2026-09` per-row | `₹10,010.00 / −₹1,001.00 / ₹9,009.00` ×2, `₹5,005.00 / −₹500.50 / ₹4,504.50`, `₹12,163.84 / −₹1,216.38 / ₹10,947.46` | identical in `rank_bonus_results` | ✅ every row to the paise | |
| 63 | `rank-bonus/2026-09` rank tiles | "SILVER PARTNER ₹25,043 pool · **3 qualifiers** · ₹22,522 credited"; header on the same page says "Qualifiers **2**" | `qualifier_count` = 2 (RAP) + 1 AO-GO grantee; credited = 900,900+900,900+450,450 = **2,252,250 p = ₹22,522.50** | ⚠️ figures right, presentation contradicts itself | tile also truncates ₹22,522.50 → ₹22,522 and ₹25,043.20 → ₹25,043 — F-T31-05 |
| 64 | `fortune-bonus` (index) | "No Fortune Bonus batches yet — engine has not yet run." | `fortune_monthly_pools` has **2 rows** (Sept, Aug) | ❌ same wrong statement as GBB | F-T31-02 |
| 65 | `fortune-bonus/2026-08` | all zeros; level points ladder L1 9 pts … L9 1 pt | `fortune_monthly_pools` 2026-08-01 all zeros | ✅ | ladder matches the 2026-08-09 cascade memo |
| 66 | `fortune-bonus/2026-09` | Company BV `₹17,88,800.00`, pool `₹89,440.00`, points 0, payout `₹0.00`, leftover `₹89,440.00`, "No participants enrolled" | pool row 1: 178,880,000 / 8,944,000 / 0 / 0 / 8,944,000; `fortune_bonus_participants` 0 rows | ✅ figures foot | same BV-as-₹ mislabel |
| 67 | `adc-bonus` (index) | September 2026, **1** centre, net `₹10,500.00`, 05 Sep 14:03 | `adc_bonus_results` 1 row, net 1,050,000 p, credited_at 2026-09-05 14:03:29 | ✅ | |
| 68 | `adc-bonus/2026-08` | "No ADC Bonus results for this month." | 0 rows | ✅ | |
| 69 | `adc-bonus/2026-09` | 1 order, `3,50,000 BV`, gross `₹10,500.00`, credited `₹10,500.00` | 35,000,000 p BV, 1,050,000 p / 1,050,000 p | ✅ | BV correctly rendered as BV here |
| 70 | Hub `/admin/compensation` — "Today's cut-off" tile | `⚠ Pending`, and the panel below says "No data yet" for 10 Sep 2026 | today's cut-off runs 00:10 tomorrow | ✅ hide-until-cut-off correct | |
| 71 | Hub — "Pending payouts" tile (**F52**) | **₹1,25,409.84** | `sum(amount_paise)` over **all** `wallet_ledger_entries` = **12,540,984 p**. Split: cash-payable types = **12,123,436 p**, repurchase-wallet types (`repurchase_deduction` + `repurchase_wallet_used`) = **417,548 p** | ⚠️ foots to the raw ledger sum, but the sum is the wrong population | the tile presents ₹1,25,409.84 as payable when only **₹1,21,234.36** is cash-payable; ₹4,175.48 is locked repurchase wallet — F-T31-06, confirms F52 |
| 72 | Hub — "GSB this week" tile | `₹0.00` | 2026-09-07/08/09 cut-offs all zero gross | ✅ | |
| 73 | Filters honoured on page **and** in export | `daily-cutoffs?date=2026-09-05&status=credited` → 3 rows on page, **3** in CSV; `gsb-calculation?…&slab=2` → 4 rows, **4** in CSV (+ TOTAL); `gsb-calculation?…&q=177536419` → 2 rows; `msb-calculation?from=to=2026-09-05` → 2 rows | credited on 05 Sep = 3; slab-2 rows in Sept = 4; ADN 177536419 has 2 GSB rows; 2 MSB rows on 05 Sep | ✅ | exports carry the filter, not the unfiltered set |
| 74 | HTTP status + speed of all 21 pages | all **200**; slowest = `daily-cutoffs/2026-09-05` at **182 ms**; every other page < 180 ms | — | ✅ | nothing near the 3 s threshold |
| 75 | Console | no console messages of any kind on a fresh load | — | ✅ | |
| 76 | **Left/Right labelling on GSB pages** | `daily-cutoffs` (list + detail) label `Left BV` / `Right BV` ✅. But `gsb-calculation` prints only `weaker 2,50,600 BV` — **no Left/Right**, and `gsb-input-output` has no side column at all | stored `weaker_bv_paise` = min(side BV + that side's carry-forward); e.g. ADN 444555666 on 05 Sep: L 5,07,200 vs R 52,200 + CF 15,000 = 67,200 → weaker = **67,200 (Right)** | ❌ side never named | F-T31-07 |
| 77 | **F61 — "weaker side recomputed in the view"** | `gsb-calculation/index.blade.php:125` renders `@bv($row->weaker_bv_paise)` — the **stored** value, not a recomputation | every stored `weaker_bv_paise` I checked equals min(side + CF); e.g. 06 Sep ADN 444555666 L 0 + CF 4,40,000 vs R 2,50,600 → 2,50,600 ✅ | ✅ not reproducible on this page | the page is faithful to the DB; F61 must live on another surface |

## Findings

### F-T31-01 — 5 Sept pool was frozen mid-day; every report shows the shortfall but none flags it (High) — confirms F30
`gsb_daily_pools` / `msb_daily_pools` / `gbb_monthly_pools` / `fortune_monthly_pools` rows for
2026-09-05 all carry `created_at 2026-09-05 14:03:29`, i.e. the day was closed at 14:03 instead of
23:59.

- `bv_ledger_entries` for 2026-09-05 total **80,940,000 p (8,09,400 BV)**; the frozen pool used
  **55,940,000 p (5,59,400 BV)** — **2,50,000 BV never entered the day's pool**.
- MSB consequence, arithmetic: correct pool = 3% × 80,940,000 = 2,428,200 p; point value
  = ⌊2,428,200 ÷ 39 points⌋ floored to the rupee = **₹622**; correct payout = 39 × ₹622 = **₹24,258**.
  Actual payout = **₹16,770** (`msb_daily_pools.payout_paise` 1,677,000). **Shortfall ₹7,488** —
  exactly the figure in F30.
- Every report renders the frozen numbers faithfully (`msb-calculation`, `msb-input-output`,
  `gsb-calculation` all show "Computed 05 Sep 2026 14:03"), so nothing is *wrong* on the page —
  but no page warns that a 14:03 compute time on a daily engine is anomalous. An admin has to
  notice the timestamp themselves.
- Same blast radius on the **monthly** pools: September's GBB / Fortune / Rank pools were all
  frozen at 05 Sep 14:03 with month-to-date turnover of **17,88,800 BV**, not the month's real
  turnover (`bv_ledger_entries` for September already totals **20,41,798 BV**). The GBB Input &
  Output page handles this well — it names the three ADNs who earned AGP after the freeze and
  says plainly they were not paid and that no admin action here will pay them. The Fortune and
  Rank pages carry no equivalent notice.

Repro: open `/admin/compensation/msb-input-output?from=2026-09-01&to=2026-09-30`, read the
05/09/2026 block. Expected: a day-close at 23:59 covering the full day. Actual: "Computed
05 Sep 2026 14:03" and a pool built on 5,59,400 of the day's 8,09,400 BV.

### F-T31-02 — GBB and Fortune Bonus index pages claim the engine never ran (Medium)
`/admin/compensation/gbb` renders "No GBB batches yet — engine has not yet run." and
`/admin/compensation/fortune-bonus` renders the same sentence for Fortune. Both engines *have*
run: `gbb_monthly_pools` holds 2 frozen rows (2026-09 at 05 Sep 14:03, 2026-08 at 10 Sep 12:48)
and `fortune_monthly_pools` holds 2 (05 Sep 14:03, 09 Sep 09:00).

Cause: `AdminGbbController::index()` builds its month list solely from `GbbMonthlyResult` where
`status = credited`. A month that ran and paid nobody produces no result row, so it vanishes from
the index. Expected: list the frozen pool months (payout ₹0 is a legitimate outcome) or, at
minimum, change the copy to "no month has paid out yet". Actual copy invites an admin to
re-trigger an engine that has already frozen its pool — and re-triggering a frozen month is
precisely what the platform refuses elsewhere.

### F-T31-03 — "Company BV" is printed as rupees on the GBB and Fortune month pages (Medium)
`/admin/compensation/gbb/2026-09` and `/admin/compensation/fortune-bonus/2026-09` both show
**`Company BV ₹17,88,800.00`**. The value is BV (`company_bv_paise` = BV × 100), not money.
Expected `17,88,800 BV`, which is exactly how the same number is rendered one click away on
`gbb-calculation` ("Month total BV 17,88,800 BV") and on `adc-bonus/2026-09` ("3,50,000 BV").
The GSB and MSB input/output **CSV headers** have the same fault: `Day Total BV (Rs)` and
`Monthly Turnover BV (net)` sit next to genuinely-rupee columns.
Risk: BV and ₹ happen to be 1:1 in the current plan, so a reader who takes ₹17,88,800 as revenue
is not obviously wrong — which is what makes the mislabel dangerous rather than merely untidy.

### F-T31-04 — MSB reports never state that MSB carries no repurchase deduction (Low) — relates to F33
`msb-calculation` and `msb-input-output` have **no repurchase-deduction column at all**, unlike
GSB, GBB, RB and FB which all show one. That is correct behaviour (F33: MSB is excluded as a
deduction source; `mentorship_bonus_results` has no deduction column and no `repurchase_deduction`
ledger row references an MSB result). But the absence is silent: an admin comparing MSB's
"Income ₹53,634.00" with GSB's gross-and-deduction layout cannot tell whether MSB is exempt or
whether the column was forgotten. One line of copy would close it.

### F-T31-05 — Rank-bonus month page contradicts itself on qualifier count and rounds tiles down (Low)
On `/admin/compensation/rank-bonus/2026-09` the header block says "Qualifiers **2**" while the
Silver Partner tile immediately below says "pool · **3** qualifiers". Both are defensible
(`qualifier_count` = 2 RAP qualifiers; the tile counts the AO-GO grantee too) but they sit on one
screen and read as an error. The same tiles truncate rather than round: pool `₹25,043`
(actual ₹25,043.20) and `₹22,522 credited` (actual ₹22,522.50).

### F-T31-06 — "Pending payouts" tile includes the repurchase wallet (Medium) — confirms F52
The Compensation Overview tile reads **₹1,25,409.84**. That is `sum(amount_paise)` over *every*
`wallet_ledger_entries` type, `repurchase_deduction` rows included:

| population | paise | ₹ |
|---|---|---|
| all types (what the tile shows) | 12,540,984 | 1,25,409.84 |
| cash-payable types only | 12,123,436 | 1,21,234.36 |
| repurchase wallet (`repurchase_deduction` + `repurchase_wallet_used`) | 417,548 | 4,175.48 |

₹4,175.48 of the headline figure is repurchase-wallet balance that can never be paid out as cash
(the R-60 rule: repurchase credit returns to the wallet, never as cash). A tile labelled "Pending
payouts" should show ₹1,21,234.36.

### F-T31-07 — GSB reports never name which side is weaker (Medium) — the live half of F61
`gsb-calculation` prints `weaker 2,50,600 BV` with no Left/Right label, and `gsb-input-output`
has no side column at all. `daily-cutoffs` does label `Left BV` / `Right BV`, but shows only the
day's raw BV — carry-forward is invisible there, so the weaker side cannot be derived from it
either. Worked example, ADN 444555666 on 05 Sep: the cut-off list shows L 5,07,200 / R 52,200, so
a reader concludes the weaker side is Right at 52,200 BV; the stored `weaker_bv_paise` is
**67,200** (52,200 + 15,000 carry-forward on R). Both pages are individually truthful and neither
lets an admin reproduce the match.
Note on F61 as filed ("weaker side recomputed in the view"): **not reproducible here.**
`gsb-calculation/index.blade.php:125` renders the stored `$row->weaker_bv_paise`, and every stored
value I checked equals min(side BV + that side's carry-forward). The defect on these pages is the
missing label, not a recomputation.

### F-T31-08 — `carry-forwards` is the only report with no CSV export (Low)
Every other report under `/admin/compensation` exposes `…/export`. `routes/web.php:543` registers
`carry-forwards` index only — no export route, and the page has no CSV button. (The T30–T34 route
note lists an export for it; the note is wrong, the app is merely inconsistent.)

### F-T31-09 — `daily-cutoffs` help text says "leg", house terminology is "group" (Low)
`daily-cutoffs/index.blade.php:9`: "weaker **leg** resets to zero, power **leg** carries forward".
Project terminology is Genos / group, never leg. Admin-facing, but the same words leak into
support conversations.

## Mutations made on staging
**None.** Read-only throughout: only GET requests (pages and `/export`) and SELECT statements.
No engine triggered, no setting or flag touched, no plan value read or written
(`/admin/compensation/plan-settings` was never opened, per the brief), no manual control
submitted, no row created, updated or deleted.

## Session mode
No session existed when I started (staging was sitting on `/login`). Per the brief's SESSION RULE
UPDATE I signed in myself as **`admin@arovolife.test`** — the plain admin role, not the developer
super-role — worked in my own MCP tab group, and signed out at the end because I was the one who
signed in. No impersonation was needed (this is an admin-only task).

## Console errors
None. Console tracking was armed and a page reloaded under it; zero messages of any level.

## Slowest page
`/admin/compensation/daily-cutoffs/2026-09-05` — **182 ms** (317 rows, paginated to 50). All 21
pages measured returned HTTP 200 and every one landed under 200 ms. Nothing approached the 3 s
threshold.

## Notes for the orchestrator
- Every money and BV figure on every one of the 17 reports foots to the source tables **to the
  paise**. Not one arithmetic discrepancy.
- The failures are all presentational: two index pages that deny their engine ran (F-T31-02),
  BV printed as rupees (F-T31-03), a payout tile summing the wrong ledger population (F-T31-06),
  and GSB reports that never name the weaker side (F-T31-07).
- F30 confirmed with exact arithmetic: MSB underpaid **₹7,488** on 5 Sept, and the same freeze
  cost September's monthly pools ~2,53,000 BV of turnover.
- F52 confirmed with exact figures. F33 confirmed (MSB genuinely carries no deduction).
- F61 is **half wrong**: no view recomputes the weaker side; the real problem is that no GSB
  report labels it Left or Right.
