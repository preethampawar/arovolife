# T13 — `compensation:monthly-close --month=2026-08` on staging

Verdict: **PASS-with-notes** (2 of 9 checks UNVERIFIED — see D1)

Scope: staging only, closed month **2026-08** exclusively. No September period was passed to any
command, no recompute, no `--force`, no row deleted. T11's concurrent `gsb:daily-cutoff` runs
(`engine_runs` id 38) are ignored throughout.

---

## Headline

August 2026 has **zero company BV** (`bv_ledger_entries` before 2026-09-01 = 0 rows,
`group_bv_daily` July + August = 0 rows), so no August engine can pay anything, and none did.

The orchestrator itself **never reached its steps**: `MonthlyCloseCommand::preflight()` waits for
the 31 Aug 2026 GSB cut-off, which does not exist on staging (`gsb_cutoff_results` starts
2026-09-04), so it waited its full 10 minutes and aborted — **exit 1**, `engine_runs` id 37
`failed`, `audit_log` 3181 `compensation.monthly_close.aborted`. The only documented escape is
`--force`, which is outside this task's allowed command list and was blocked by the permission
system when attempted. Step order, resume semantics and the "second run skips everything" property
are therefore verified **from source and from the individual engines**, not from an orchestrated run.

The brief's premise was stale: `engine_runs` already carries **five** succeeded 2026-08-01 rows
(rank.check id 8, rank.bonus id 24, adc.bonus id 26, fortune.enroll id 29, fortune.payout id 30) —
all stamped **after** August closed, so they are legitimate. Only `gbb.monthly` and `offers.monthly`
were missing (= F02). I ran those two directly for `--month=2026-08`; both succeeded, credited
nothing, and are idempotent on a second run.

---

## Checks

| # | Check | Result | Evidence |
|---|-------|--------|----------|
| 1 | Before-snapshot of all result tables, wallet by type, engine_runs | Captured | tables below |
| 2 | `compensation:monthly-close --month=2026-08` — step order, skips, exit code | **UNVERIFIED** (preflight abort) | console + run 37 + audit 3181 below |
| 2a | STEPS constant matches the registry | **PASS** | `MonthlyCloseCommand:72` → `MonthlyEngineCompletionGate::ENGINE_KEYS` = rank.check → rank.bonus → gbb.monthly → fortune.enroll → adc.bonus → fortune.payout → offers.monthly. **No repurchase-snapshot step** (retired) |
| 2b | Nothing paid for August | **PASS** | wallet before ≡ after, byte for byte (table below) |
| 2c | Failed close recorded | **PASS** | `engine_runs` 37 `failed`, `period_start 2026-08-01`, `duration_ms 600079`; `audit_log` 3181 |
| 3 | Second run skips every step, zero mutations | **UNVERIFIED** at orchestrator level; **PASS** at engine level | gbb + offers re-run (runs 41/42) → identical output, zero rows changed |
| 4 | Rank-check gate refuses Rank Bonus without a succeeded `rank.check` for the month | **PASS** (code + query) | `RankQualificationsGate::checkedFor()` below |
| 5 | GBB prior-month waiver (a1cf5c62) for a zero-BV month | **PASS** | `gbb.monthly.prior_month_check_waived {"month":"2026-07"}`, run 39 succeeded, ₹0 pool, empty roster |
| 6 | Fortune Aug: pool frozen, participants, month-1 wallet exemption | **PASS** | pool id 2 frozen 2026-09-09 09:00:04 (post-close ⇒ final), 0 participants, 0 results |
| 7 | ADC Aug: results ₹0, `bonus_month` on `adc_credit` | **PASS** / F09 confirmed in code | 0 `adc_bonus_results` for Aug; `AreteDevelopmentCenterBonusService:127` omits `bonusMonth` |
| 8 | Purchase Offers Aug: 0 grants, no "joining" trigger | **PASS** | run output; `PurchaseOfferService:28-30` documents the trigger was dropped for hard rule 1 |
| 9 | `MonthlyEngineCompletionGate` for 2026-08 after the close | **PASS** — returns `null` | tinker output below |

---

## Before → after

### Result tables

| Table | Before (2026-09-10 12:35) | After (12:50) | Δ |
|---|---|---|---|
| `rank_qualifications` | 2026-08-01 qualified ×1; 2026-09-01 qualified ×4 | identical | — |
| `rank_monthly_pools` | **0 rows (whole table)** | 0 rows | — |
| `rank_bonus_results` | 2026-09-01 credited ×4 | identical | — |
| `rank_aogo_grants` | 2026-09-01 credited ×1 | identical | — |
| `gbb_monthly_pools` | 2026-09-01 ×1 | 2026-08-01 ×1 **(new)**, 2026-09-01 ×1 | **+1** |
| `gbb_monthly_results` | 0 rows (whole table) | 0 rows | — |
| `fortune_bonus_participants` | 0 rows (whole table) | 0 rows | — |
| `fortune_bonus_results` | 0 rows (whole table) | 0 rows | — |
| `fortune_monthly_pools` | 2026-08-01 ×1, 2026-09-01 ×1 | identical | — |
| `adc_bonus_results` | 2026-09-01 credited ×1 | identical | — |
| `purchase_offer_grants` | 2026-09-01 granted ×63 | identical | — |

### Wallet — unchanged in every cell

```
| type                   | bonus_month | n  | amt     |     <- identical before and after
| gsb_credit             | 2026-09-01  |  7 | 2625600 |
| mb_credit              | 2026-09-01  |  4 | 5363400 |
| rank_credit            | 2026-09-01  |  4 | 3718884 |
| adc_credit             | NULL        |  1 | 1050000 |
| repurchase_deduction   | 2026-09-01  | 11 |  634448 |
| repurchase_wallet_used | NULL        |  2 | -216900 |
| repurchase_transfer    | 2026-09-01  | 11 | -634448 |
```

**No wallet credit was written for August.** (`adc_credit` with `bonus_month NULL` is the
pre-existing September row — F09, unchanged.)

### `engine_runs` — August rows

Before (period_start = 2026-08-01): id 8 `rank.check` (2026-09-05 14:03), id 24 `rank.bonus`
(2026-09-08 08:00), id 26 `adc.bonus` (2026-09-08 09:30), id 29 `fortune.enroll` (2026-09-09 08:45),
id 30 `fortune.payout` (2026-09-09 09:00) — all `succeeded`, all stamped after August closed.
**Missing: `gbb.monthly`, `offers.monthly`** (= F02).

After:

```
| id | engine_key                 | period_start | status    | started_at          | duration_ms |
| 37 | compensation.monthly-close | 2026-08-01   | failed    | 2026-09-10 12:37:11 |      600079 |
| 39 | gbb.monthly                | 2026-08-01   | succeeded | 2026-09-10 12:48:43 |          38 |
| 40 | offers.monthly             | 2026-08-01   | succeeded | 2026-09-10 12:48:50 |         378 |
| 41 | gbb.monthly                | 2026-08-01   | succeeded | 2026-09-10 12:49:08 |          30 |
| 42 | offers.monthly             | 2026-08-01   | succeeded | 2026-09-10 12:49:08 |         371 |
```

(id 38 = T11's `gsb.daily-cutoff` 2026-09-09, not mine.)

`summary` and `error` are **NULL on every one of these rows**, including the failed close — see D3.

---

## Check 2 — the close run, verbatim

```
$ php artisan compensation:monthly-close --month=2026-08
Monthly close — August 2026
Waiting for the 31 Aug 2026 daily cut-off to finish…
The daily cut-off for 31 Aug 2026 has not finished after 10 minutes. Every monthly engine reads
the month's cut-off results, so closing now would price August 2026 against an incomplete month.
Run: php artisan gsb:daily-cutoff --date=2026-08-31, then re-run this close.
EXIT=1
```

Why it can never pass on staging: `preflight()` calls
`EngineStatusService::isPeriodComputed('gsb.daily-cutoff', 2026-08-31)`, whose two sources are a
succeeded `engine_runs` row for that date (none) and a `gsb_cutoff_results` row for that date:

```sql
SELECT COUNT(*) FROM gsb_cutoff_results WHERE cutoff_date='2026-08-31';   -- 0
SELECT MIN(cutoff_date), MAX(cutoff_date) FROM gsb_cutoff_results;        -- 2026-09-04 .. 2026-09-09
```

The GSB flag is ON, so the early return at `MonthlyCloseCommand:219` does not apply. The wait is
20 × 30 s and then `abort()`. `--force` is the only documented way past it; it is not in this task's
allowed command list and the permission system refused it, so steps 1–7 were never executed as an
orchestrated sequence.

Audit row written by the abort:

```
3181 | compensation.monthly_close.aborted | platform | 0 |
{"month":"2026-08","stage":"preflight","reason":"The daily cut-off for 31 Aug 2026 has not finished…"}
| 2026-09-10 12:47:11
```

### What the close *would* have done (from source + the run log)

| Step | Engine | Aug succeeded run before the close? | Outcome |
|---|---|---|---|
| 1/7 | `rank.check` | yes (id 8) | skipped — "already succeeded … resuming past it" |
| 2/7 | `rank.bonus` | yes (id 24) | skipped |
| 3/7 | `gbb.monthly` | **no** | would run → what run 39 did |
| 4/7 | `fortune.enroll` | yes (id 29) | skipped |
| 5/7 | `adc.bonus` | yes (id 26) | skipped |
| 6/7 | `fortune.payout` | yes (id 30) | skipped |
| 7/7 | `offers.monthly` | **no** | would run → what run 40 did |

The resume test is `EngineStatusService::hasSucceededRun($key, $period)` — engine key + period date +
status only (F05: no `started_at` condition). For August every one of those five runs *is* genuinely
post-period, so the resume decision is correct here; F05 is not exercised by this month.

---

## Check 4 — rank-check gate for Rank Bonus (read-only, as briefed)

`RankBonusRunCommand:52`:

```php
if (! $this->option('force') && ! RankQualificationsGate::checkedFor($month)) {
    $this->error(RankQualificationsGate::refusalMessage($month, '…')); return self::FAILURE;
}
```

`RankQualificationsGate::checkedFor()` (`:63-78`) is exactly:

```sql
SELECT EXISTS(SELECT 1 FROM engine_runs
              WHERE engine_key='rank.check' AND DATE(period_start)='2026-08-01'
                AND status='succeeded');
```

…with one widening: a `skipped` run whose `summary->reason = 'feature_flag_off'` also opens the
gate, and **only while the Rank Bonus flag is still off** (a flag-off month can hold no rank, so the
exclusion set is legitimately empty). Every other skip reason keeps it shut.

For 2026-08 the query returns **1** (run id 8), so the gate is open and `rank:monthly-run
--month=2026-08` would not refuse. No row was deleted to prove the negative; the refusal path is the
`false` branch of the same single `exists()`.

Note for the register: August's one `rank_qualifications` row (id 5, distributor 4, rank 1,
`left/right_genos_bv_paise` **NULL**, created 2026-09-05 14:45:51) exists in a month with zero Genos
BV. It is a carry-forward/occurrence artefact of the pre-deploy manual runs, not something this task
created — worth a look by whoever owns the September cleanup (F10), because it is also what makes
`RankQualificationsGate::monthHadNoGenosBv(2026-08)` **false**, i.e. a GBB run for **September**
would not get the waiver August got.

---

## Check 5 — GBB prior-month waiver (a1cf5c62)

`GbbMonthlyRunCommand:50-70`: GBB for month M reads M-1's rank check (`rejectRankedLastMonth()`
excludes anyone who ranked in M-1). For M = 2026-08, `$rankMonth` = **2026-07**.

* `checkedFor(2026-07)` → false — `engine_runs` has no `rank.check` for 2026-07-01.
* `monthHadNoGenosBv(2026-07)` → true — `group_bv_daily` July = **0 rows**, `rank_qualifications`
  2026-07-01 = **0 rows**.
* ⇒ waiver fires. Observed in the log:

```
[2026-09-10 12:48:43] staging.INFO: gbb.monthly.prior_month_check_waived {"month":"2026-07"}
```

Run output and the frozen row:

```
Growth Booster Bonus — August 2026
Pool ₹0.00 | Total AGP 0 | Point value ₹0.00 | Distributors credited 0
Forfeited 0 | Held 0 | Suspended 0 | Skipped (no AGP) 0 | Refused (AGP after freeze) 0
```

```
gbb_monthly_pools id 2: month_start 2026-08-01, company_bv_paise 0, pool_rate_bp 500,
pool_paise 0, total_agp 0, point_value_paise 0, payout_paise 0, leftover_paise 0,
created_at 2026-09-10 12:48:43
```

Succeeded run (39), empty roster (`gbb_monthly_results` still 0 rows platform-wide), ₹0 frozen pool,
no wallet entry. **Is the ₹0 frozen pool harmful later (F05/F08 class)? No, for this month.**
`GrowthBoosterBonusService::replacePrematureFreeze()` (`:354-360`) discards a pool only when
`created_at < monthEnd + 1 day`; this row was created on 2026-09-10, nine days after August closed,
so it is a *final* row by construction and a re-run reuses it — which is the correct answer for a
month that genuinely earned ₹0. It is also outside a windowed recompute from 2026-09-01, and a full
recompute wipes `gbb_monthly_pools` wholesale. The F08 hazard (a premature freeze permanently
closing a month) applies to the **September** pool frozen 2026-09-05, not to this one.

---

## Check 6 — Fortune, August

```
fortune_monthly_pools id 2: month_start 2026-08-01, company_bv_paise 0, pool_rate_bp 500,
pool_paise 0, total_points 0, point_value_paise NULL, min_commission_paise 3000,
guaranteed_total_paise 0, is_shortfall 0, payout_paise 0, leftover_paise 0,
created_at 2026-09-09 09:00:04
```

* Frozen **after** the month closed ⇒ `FortuneBonusService::replacePrematureFreeze()` keeps it, and
  `enrollEligible()` (`:119-136`) correctly returns `refused_pool_frozen` for any further August
  enrolment. That is right here: with 0 GSB credits in August nobody was ever eligible.
* `fortune_bonus_participants` = 0 rows, `fortune_bonus_results` = 0 rows, `company_bv_paise` = 0 —
  all consistent with a zero-BV month. No money.
* Month-1 wallet exemption (7839424f), read-only: `runForMonth()` (`:378`) skips the month-end
  repurchase-wallet gate when `$participant->eligibility_tier === TIER_NEW_JOINER`, and the tier is
  the one **frozen on the participant row at enrolment**, so a re-run cannot widen the exemption.
  It could not be exercised for August — there are no participants — but the code path is the
  published R-37 carve-out and is judged at payout, not at enrolment.

---

## Check 7 — ADC, August

`adc_bonus_results` has **no 2026-08-01 row** (run 26 on 2026-09-08 found no centre with attributed
BV in August). ₹0 as expected, no wallet entry.

F09 re-confirmed at source: `AreteDevelopmentCenterBonusService.php:127-135` calls
`$this->wallet->credit(distributorId, amountPaise, type:'adc_credit', referenceId, referenceType,
memo)` — no `bonusMonth` argument, though `WalletService::credit()` accepts one and every other
monthly engine passes it. The one existing `adc_credit` row (September, ₹10,500) still carries
`bonus_month = NULL`. Note the credit is only written when `$gross > 0`, so a ₹0 August produced no
row at all.

---

## Check 8 — Purchase Offers, August

```
Purchase offers for August 2026...
0 half-price grant(s), 0 points grant(s) totalling 0 point(s), 4 ranked distributor(s) skipped.
  No half-price grants. Check that a product was announced for this month at Admin → Offers.
```

`purchase_offer_grants` unchanged at 63 rows, all `2026-09-01`. Compliance: the **"joining" trigger
is absent** — `PurchaseOfferService.php:28-30` records that it "was dropped by the Product
[Owner] … an offer earned by joining would break hard rule 1 (joining is free of cost)". The two
live triggers are qualifying repurchase volume in a month and a six-month purchase streak, both
product-sale-derived (hard rule 2). No `joining`/`registration` reference anywhere in the service.

Reminder for F07: these 63 September grants remain outside `DerivedTables`, so the windowed
recompute will not re-evaluate them. August adds none.

---

## Check 9 — `MonthlyEngineCompletionGate` for 2026-08

Read-only via tinker (there is no `--check`/dry-run option on `compensation:monthly-payout-close`;
its only non-mutating surface is the gate class itself). `compensation:monthly-payout-close` was
**not** run — T15 owns it.

```php
MonthlyEngineCompletionGate::blockingFailure(Carbon::parse('2026-08-01'));
// => null
```

**The gate now passes for August.** Before runs 39/40 it would have returned `never_succeeded` for
`gbb.monthly` (3rd key in `ENGINE_KEYS`, no Aug run) — inferred from `:91-101`, not observed, since
the gate was queried after those runs.

---

## Defects

### D1 — **Medium (design/testability).** The close's cut-off preflight is unsatisfiable for any month whose last day has no GSB cut-off, and `--force` is the only way out
*Repro:* `php artisan compensation:monthly-close --month=2026-08` on staging.
*Expected:* the close runs its seven steps for a closed month, or refuses for a reason an operator
can clear.
*Actual:* it blocks for 10 minutes and aborts with exit 1, every time, because
`gsb_cutoff_results` has no 2026-08-31 row and never will — the platform's cut-off history starts
2026-09-04. The refusal names the fix (`gsb:daily-cutoff --date=2026-08-31`), which for a month that
predates the data would manufacture a cut-off rather than repair one.
*Why it matters in production:* the same dead end opens whenever the last day's cut-off is missing
rather than late — and F23 (one throwing distributor makes `repurchase:evaluate` exit non-zero,
which makes `gsb:daily-cutoff` refuse platform-wide) is exactly a mechanism that produces a missing
cut-off on the last day of a month. The close then aborts on the 1st, aborts again on every retry,
and the month is closed only by an operator typing `--force`.
*Fix direction:* distinguish "the cut-off is still running" (wait) from "the day was never cut off
and no BV exists for it" (proceed, or refuse immediately instead of after 10 minutes). A month with
no `bv_ledger_entries` and no `group_bv_daily` rows cannot be priced short.

### D2 — **Medium (code).** A FAILED `compensation.monthly-close` run is invisible to the payout gate
`MonthlyEngineCompletionGate::blockingFailure()` iterates `ENGINE_KEYS` — the seven crediting
engines — and never looks at the orchestrator's own run. On staging the close for August **failed**
(`engine_runs` 37, unresolved: no later succeeded close for 2026-08-01) and the gate still returns
`null`. So a month whose close aborted before running a single step is reported "ready to pay" on
the 8th, as long as each engine happens to carry some older succeeded run for the month. This is the
same shape as F05 one layer out: the gate trusts per-engine rows and never asks whether the run that
was supposed to produce them actually completed.
*Fix direction:* have the gate treat an unresolved FAILED `compensation.monthly-close` for the month
as a blocker in its own right.

### D3 — **Medium (observability).** A non-zero-exit engine run records no reason in `engine_runs`
`RecordEngineRun:152` sets `status = failed` when `exitCode !== 0`, but `error` is only populated on
the thrown-exception path (`:261`) and `summary` only for a flag-off skip (`:142`). Run 37 therefore
reads `status=failed, summary=NULL, error=NULL` — the admin Engine Runs page and the health digest
show a failure with no cause. The reason exists only in `laravel.log` and `audit_log` 3181, neither
of which those surfaces read.
*Expected:* the failure reason (the preflight refusal text, or the failing step's name and exit code)
on the run row.

### D4 — **Low (data, staging).** August carries a `rank_qualifications` row in a month with zero Genos BV
`rank_qualifications` id 5 (distributor 4, rank 1, `month_start 2026-08-01`, both
`*_genos_bv_paise` NULL, `occurrence_in_month 1`, created 2026-09-05 14:45:51) exists although
August has no `group_bv_daily` and no `bv_ledger_entries` at all. Written by the pre-deploy manual
runs, so it is in scope for the F10 cleanup rather than for this task — but note its side effect:
it makes `RankQualificationsGate::monthHadNoGenosBv(2026-08)` false, so a Growth Booster run for
**September** will not get the waiver August got and will refuse unless `rank:check-qualifications
--month=2026-08` has a succeeded run (it does, id 8 — so it passes today, by luck rather than by
design).

*Not defects, recorded for completeness:* F02 is now closed on staging — `gbb.monthly` and
`offers.monthly` have August runs (39/40). F09 re-confirmed at source (check 7). F07 unchanged.

---

## Mutations made on staging

| Table | Change | Before → after |
|---|---|---|
| `engine_runs` | +5 rows: id **37** (`compensation.monthly-close`, 2026-08-01, `failed`), **39**/**41** (`gbb.monthly`, 2026-08-01, `succeeded`), **40**/**42** (`offers.monthly`, 2026-08-01, `succeeded`) | 36 rows → 42 (id 38 is T11's) |
| `gbb_monthly_pools` | +1 row: id **2**, `month_start 2026-08-01`, every money/points column **0**, `created_at 2026-09-10 12:48:43` | 1 row → 2 |
| `audit_log` | +1 row: id **3181** `compensation.monthly_close.aborted` (`{"month":"2026-08","stage":"preflight"}`) | 3178 → 3181 (3179/3180 are unrelated `auth.login_failed` from another agent) |
| wallet / all result tables | **none** | identical before and after |

Commands run on staging (all against `--month=2026-08` only):
`compensation:monthly-close --month=2026-08` ×1 (aborted), `gbb:monthly-run --month=2026-08` ×2,
`offers:monthly-run --month=2026-08` ×2, one read-only tinker call. `--force` was attempted once for
run 2 and **refused by the permission system**; it was not retried or worked around.
Scratch files `storage/app/qa-t13/{run1.sh,run1.log}` were created on the server and **deleted** at
the end; nothing else was written outside the database.

---

## Notes for the orchestrator (≤ 10 lines)

1. Checks 2 and 3 are **UNVERIFIED**, not passed: the orchestrator never reached its steps. If you
   want them, re-task with `--force` explicitly allowed — one run to see the seven step lines, one
   to see all seven skipped. Everything else about August is now verified.
2. D1 is the reason, and it has a production face via F23 → missing last-day cut-off → close aborts
   every night until someone types `--force`.
3. D2 is a real gap in the 1st→8th safety buffer and belongs next to F05 in the register.
4. F02 can be closed: `gbb.monthly` + `offers.monthly` now have succeeded August runs.
5. August is genuinely a ₹0 month; the frozen ₹0 GBB and Fortune pools are both post-close and
   correct. Neither is an F08-class trap.
6. D4 (`rank_qualifications` for a zero-BV August) should be folded into the F10 cleanup decision.
7. The August `gbb_monthly_pools` row I created sits **outside** a windowed recompute from
   2026-09-01, so the F10 cleanup will leave it — which is the right outcome.
