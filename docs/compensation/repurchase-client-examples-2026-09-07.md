# Repurchase system — client worked examples and clarifications (2026-09-07)

**Status:** Implemented on branch `fix/compensation-frozen-roster-and-monthly-close`,
commits `329c5d8..056e830` (2026-09-07); public-page copy pending DSA §6.2 notice
(R-75). Supersedes the "rules 1–9" hold-and-release model the 2026-09-06 branch
work was built on.

**Sources**

- Client Google Doc "New Arovolife — Re-purchase system" (Drive file
  `1_LVrHDFeKlRNujCKMDQm5Hp5MnTtP1ye`): GSB examples 1–2, RB examples 1–3, the
  "students and examinations" analogy. Dates are on the August 2026 calendar.
- Client answers to our four questions, relayed 2026-09-07 (quoted in §6).

---

## 1. The cycle

| Rule | Value | Evidence |
|---|---|---|
| Anchor | The day the distributor first reaches 600 BV of personal purchase. That day is **day 0** of the first cycle. | "completed his 600-BV on July 7th, therefore his Beginning Date is July 7th"; answer 4 |
| Length | **due_date = start + 30 days** (inclusive end). Jul 7 → Aug 6, Jul 13 → Aug 12, Jul 24 → Aug 23, Aug 9 → Sep 8, Aug 17 → Sep 16, Aug 27 → Sep 26 — all six examples are exactly start + 30. | Doc; answer 4 |
| Condition A | Self-purchase BV inside `[start, due]` ≥ the rank's obligation (600 BV non-ranked; per-rank table otherwise). | Answer 2 (A) |
| Condition B | Repurchase wallet = ₹0 at the end of `due_date`. | Answer 2 (B): "100% apply" |
| Verdict | Taken once, on `due_date`; both conditions must hold. | Answer 2 |
| On-time pass | Next cycle starts `due + 1`. | Unchanged (not contradicted) |
| Fail | From `due + 1` the distributor is **failed** until the first day both conditions hold again (the *fulfilment day*). | Doc examples |
| Late fulfilment | A fresh cycle starts **on** the fulfilment day: `start = fulfilled_on`, `due = fulfilled_on + 30`. | "reset to a fresh 30-day cycle starting from August 27 and extending to September 26"; answer 4 |
| Grace | **None.** Failure begins the day after `due_date`. There is no grace window and no "10-day grace". | Doc: 7 Aug fails for a 6 Aug due date |
| Month-end shortness | Not an issue: the window is a day count, never "same date next month". | Answer 4 |

**Assumption A1 (not contradicted, kept from the branch):** late fulfilment is
measured cumulatively — BV from the failed cycle's `start` up to the day in
question, wallet from the frozen due-date balance plus daily movement — and
completes on the first day both conditions hold.

---

## 2. What a failed day does — forfeit, never hold

The mechanism is a **per-day exclusion of group BV**, and income follows from
BV. It is not a hold on income, and nothing is released later.

> "for those who have failed to repurchase BVs on time, the business BVs in
> their left and right genos are not added to them, and therefore they lose
> the income related to it." — the analogy
>
> "he permanently lost the Business Volume (BV) associated with those three
> days" — answer 4

For every day `d` with `due + 1 ≤ d ≤ fulfilled_on − 1`:

### 2.1 GSB (daily)

- No slab match is attempted for that distributor on `d`. No income, ever.
- The day's left and right group BV is **not added** to either carry-forward.
  Both stores — the slab-1 weaker accumulator and the power-side carry-forward
  — stay exactly as they stood at the end of `due_date`.
- On the fulfilment day, the cut-off resumes normally and that day's BV is
  added on top of the preserved carry-forward ("the BVs from that day will be
  credited to the old ones").
- Record the day for reporting (a zero-income result row in a `forfeited`
  state) so the daily calculation report and the distributor's My Business
  page can show why the day paid nothing.

Answer 3, verbatim: *"the BVs on both sides of the left genos and right genos
at that time will stop there as assets. Again, on the day the repurchase
condition is satisfied, the BVs from that day will be credited to the old
ones."*

### 2.2 Rank Bonus (monthly)

- Rank qualification for a calendar month sums group BV **only over days on
  which the distributor was not failed**. Failed days' group BV is excluded
  from the left/right target permanently.
- Rank Bonus income is **never withheld** by the repurchase state. If the rank
  is achieved on the surviving BV, the bonus is credited on the 1st and paid
  on the 8th as usual — even if the distributor is still failed at month end.

| RB example | Cycle | BV counted | Verdict |
|---|---|---|---|
| 1 | Jul 24–Aug 23; no repurchase 24–31 Aug | 1–23 Aug: L 2.8L / R 2.9L | Rank 1 granted; paid 8 Sep |
| 2 | same | 1–23 Aug: L 2.1L / R 2.9L | not granted (left short) |
| 3 | fails 24–26 Aug, fulfils 27 Aug | 1–23 Aug + 27–31 Aug: L 2.3L + 0.2L / R 3.0L | Rank 1 granted; paid 8 Sep; new cycle 27 Aug–26 Sep |

### 2.3 Growth Booster, Fortune

Not covered by the doc. **Assumption A2 (to confirm with the client):** the
same per-day model applies — a failed day simply produces no GSB slab match,
so no AGP and no Fortune slab-achievement for that day — and there is **no**
month-end hold of the GBB or Fortune payout by the repurchase state. The
separate month-end **wallet = ₹0** gates on GBB, Fortune, rank
requalification and AO-GO (client 2026-09-05) are unchanged.

### 2.4 Unaffected

Mentorship, ADC and Awards & Rewards are outside the repurchase condition.

**Not the same thing as the deduction.** The 10% credit-time repurchase
deduction *does* apply to Mentorship (client, 2026-09-10 — the fifth deduction
source alongside GSB, Rank, Growth Booster and Fortune, as the 2026-06-26
clarifications always had it). What §2.4 says is that a **failed cycle** never
withholds Mentorship: it is not held, released or forfeited by the cycle, and a
failed sponsee simply produces no GSB slab for the sponsor to earn from. ADC and
Awards & Rewards remain outside both the condition and the deduction.

---

## 3. Weekly payout — Wednesday-to-Tuesday earning week, paid one Tuesday later

> "the daily closing weekly payout cycle starts every Wednesday and closes on
> Tuesday … eligible earnings accrued between Wednesday, August 5, and
> Tuesday, August 11, into their accounts on Tuesday, August 18, following a
> one-week cooling-off period." — answer 1

- Earning week: **Wednesday → Tuesday** (inclusive), keyed on the day the
  income was **earned** (the GSB cut-off date, not the wallet credit time —
  Tuesday's cut-off is credited at 00:10 on Wednesday).
- Payment: the **Tuesday one week after** the earning week closes. The batch
  dated Tuesday `T` sweeps Group A entries earned on or before `T − 7`.
- Check: 6 Aug (Thu) and 11 Aug (Tue) both fall in the week 5–11 Aug → paid
  18 Aug ✔. 18 Aug (Tue) falls in 12–18 Aug → paid 25 Aug ✔.
- **Assumption A3:** the rule covers both Group A types, GSB and Mentorship
  ("daily closing weekly payout" is the whole Group A batch).
- **Copy rule:** never call this week a "cooling-off period" in distributor
  copy. Cooling-off is the statutory 30-day cancellation window (hard rule 5).
  Use "payout processing week" or "paid the Tuesday after the week closes".

---

## 4. What the current branch does differently (must change)

| # | Current branch (2026-09-06 work) | Required |
|---|---|---|
| 1 | Failed-day income is calculated, marked `repurchase_held`, kept in the Rank/GBB denominator and **released in full** on fulfilment by four `ReleaseHeld*OnReactivation` listeners. `IncomeEligibilityService` never returns BLOCKED. | Forfeit. No held rows, no release, no listeners. Failed days contribute nothing. |
| 2 | `due_date = start + (cycle_days − 1)` = start + 29 with the 30 default. | `due_date = start + 30`. Every example is off by one otherwise. |
| 3 | GSB on a failed day consumes the weaker leg and advances the power carry-forward as if matched (the "frozen path" shape). | Do not touch either carry-forward; do not add the day's BV. |
| 4 | Rank qualification sums `group_bv_daily` over the whole month. | Exclude each distributor's failed days. |
| 5 | Rank Bonus writes `repurchase_held` for an achiever whose month-end verdict is failed. | Never held. Credit and pay normally. |
| 6 | `grace_end_date`, `STATUS_GRACE`, `comp.repurchase.grace_days`. | No grace. Failed from `due + 1`. |
| 7 | Weekly batch sweeps every unswept Group A entry as of the batch Tuesday. | Sweep only entries earned on or before batch date − 7. |

Unchanged and correct: the 600-BV anchor, the one-time verdict at the window
end with frozen `wallet_balance_paise` / `wallet_zeroed`, re-anchoring on the
fulfilment day, the date-based verdict (`verdictAsOf`) that lets a month be
re-run to the same answer, the repurchase deduction at credit time, the
`bonus_month` cap windowing, and the frozen pool + roster (R-72 is untouched
by this doc).

Because the branch work is uncommitted, the hold-and-release pieces should be
**removed** from it rather than reversed by a second migration: the
`repurchase_held` enum additions for Rank and Fortune
(`2026_09_06_100001_…`), the two new release listeners and the two existing
ones, and the "held stays in the denominator" pricing in Rank Bonus and GBB.

---

## 5. Open assumptions to confirm with the client

- **A2** — GBB and Fortune follow the per-day model with no month-end hold
  (§2.3). *Different answer = different code in two engines.*
- **A3** — Mentorship is paid on the same Wednesday–Tuesday, plus-one-week
  schedule as GSB (§3).
- **A1** — Late fulfilment counts BV cumulatively from the failed cycle's
  start (§1).

---

## 6. Client answers, verbatim (2026-09-07)

**Q1 — payout date.** "rule sir. … In our company, the daily closing weekly
payout cycle starts every Wednesday and closes on Tuesday. … The company will
deposit eligible earnings accrued between Wednesday, August 5, and Tuesday,
August 11, into their accounts on Tuesday, August 18, following a one-week
cooling-off period. Similarly, we will deposit eligible earnings from
Wednesday, August 12 to Tuesday, August 18 into their account on Tuesday,
August 25, after a one-week cooling period."

**Q2 — wallet = 0.** "100% apply sir / yes. The distributor will have to prove
his repurchase condition in two ways. A) The Distributor must generate BVs
equal to or greater than his eligibility from multiple purchases for his
personal needs within his 30-day repurchase period. B) The distributor must
clear his repurchase wallet to 0 on the last day of his repurchase period (30
days)."

**Q3 — carry-forward on a failed day.** "Yes, continue sir. If the repurchase
condition is not satisfied on the last day of the repurchase cycle, then the
BVs on both sides of the left genos and right genos at that time will stop
there as assets. Again, on the day the repurchase condition is satisfied, the
BVs from that day will be credited to the old ones. Please develop this
method, sir."

**Q4 — anchor on the 31st.** "Since we consider the day a distributor in our
company generates a bill exceeding 600 BV as the first day of the cycle, we
request you to calculate the 30-day period starting from that date. Similarly,
I request that a 30-day period be determined, calculated from the date the
distributor fulfills their repurchase conditions. … Distributor C's repurchase
period needs to be reset. Previously, the period ran from July 24 to August
23; however, he failed to make a repurchase on the 24th, 25th, and 26th,
finally completing it on August 27. Since he permanently lost the Business
Volume (BV) associated with those three days, his repurchase period must be
reset to a fresh 30-day cycle starting from August 27 and extending to
September 26."

---

## 7. Deploy checklist

1. `php artisan migrate` — forward-only. The five migrations this change
   ships, in order (all under
   `app/Modules/Compensation/Database/Migrations/`):

   - `2026_09_07_100000_drop_grace_end_date_from_repurchase_cycles`
   - `2026_09_07_100001_add_repurchase_forfeited_status_to_gsb_cutoff_results`
   - `2026_09_07_100002_add_earned_on_to_wallet_ledger_entries`
   - `2026_09_07_100003_backfill_earned_on_on_wallet_ledger_entries`
   - `2026_09_07_100004_add_earnings_through_to_payout_batches`

   `2026_09_07_100000_create_rank_monthly_pools_table` shares the date but is
   **unrelated** — it belongs to the R-72 rank-pool work and is not part of
   this change.

   The `earned_on` backfill must complete before the first post-deploy
   Tuesday batch runs, or Group A rows credited before the backfill will not
   sweep on the correct week. Verify it landed:

   ```sql
   SELECT COUNT(*) FROM wallet_ledger_entries
    WHERE type IN ('gsb_credit', 'mb_credit') AND earned_on IS NULL;
   ```

   It must return **0** before the first Tuesday batch. A non-zero count
   means the backfill did not run (or ran before those rows existed) — do not
   run the batch until it is 0.

   One dev-only artefact: migration `2026_09_06_100001` was deleted from the
   branch after it had already run on dev, so dev keeps an orphaned
   `migrations` row for it and a dead `repurchase_held` member in the
   `rank_bonus_results` / `fortune_bonus_results` status ENUMs. Both are
   harmless — nothing writes or reads that status any more — and must be left
   alone: **never roll back to reach it.** Staging and production, which
   never ran it, get the correct schema from a clean forward migrate.
2. Restart the `compensation` queue worker and the scheduler. Pre-deploy
   worker code has no `earned_on` stamping and none of the new evaluate
   guards — it would throw on the new Group A columns or miss the
   `gsb:daily-cutoff` / `rank:check-qualifications` guards entirely (see R-71
   for why a stale worker running old code is a standing risk class, not a
   one-off).
3. Decide staging's legacy `repurchase_held` rows (edge 20): replay them via
   the Engine Runs recompute, or accept them as legacy. Either way they stay
   forfeited-model-incompatible and must never be released — the four
   `ReleaseHeld*OnReactivation` listeners that used to do that are deleted.
4. The repurchase feature flag stays **OFF** in production until the DSA
   §6.2 thirty-day notice has run (R-75).
5. Re-seed the compensation content page (`app/database/seeders/content/compensation.md`)
   only after the §6.2 notice — the new payout-week cadence sentence is
   written into the file but must not reach `ContentPageSeeder` before then
   (R-75).
6. The first weekly batch run under the new rule is the first one to stamp
   `earnings_through`; batches created before this deploy show "—" for that
   column and should not be reconciled against the new window logic.

## 8. Implementation deltas vs §4

Client re-confirmed 2026-09-07 that the implementation may add controls
beyond §4's required-changes list, provided the plan mechanics (§1–§3) are
unchanged. Two design additions came out of the review process:

- **Evaluate guards on `gsb:daily-cutoff` and `rank:check-qualifications`.**
  Neither command may run for a date/month unless a succeeded
  `repurchase.evaluate` `EngineRun` proves the period has been judged
  (`--force` overrides; skipped outright when the repurchase flag is off).
  The rank check accepts a run dated the 1st of the following month or later.
  The cut-off is stricter: it needs a run that has SEEN the whole cut-off day
  — dated later than it, or dated for it but started after it ended — because
  the scheduled 00:05 run on day D cannot see a fulfilment purchase made
  later on D, and would let the cut-off forfeit a day the distributor
  actually fulfilled. This closes
  the same "prerequisite the scheduler did not run" hazard as R-70, applied
  to the forfeit model's own dependency: a cut-off or rank check that runs
  before that day's/month's repurchase verdicts are in would silently
  forfeit or admit the wrong distributors.
- **Open-month refusal on the freezing engines (2026-09-08 audit).** The
  five pool-freezing monthly commands and both closes refuse a `--month`
  that has not ended in IST; `--in-flight` is the testing override the
  recompute tool and the developer-gated Engine Runs page pass. A flag-off
  rank check (`skipped` run) satisfies the dependants' prerequisite, so a
  Rank flag left OFF no longer deadlocks the monthly close. The recompute
  wipers keep the checkout-time `repurchase_wallet_used` debits (they are a
  purchase record no engine rebuilds), and a real-clock `repurchase:evaluate`
  undoes a failed verdict that a future-dated replay froze inside a window
  that has not closed. Both the `--in-flight` close and the verdict undo
  write `audit_log` rows; the admin trigger path injects `--in-flight` only
  while the recompute testing gate is open. R-76 records the flag-toggle
  interaction (a flag-off rank check opens the gate only while the flag is
  still off, never for any other skip reason).
- **Distributor-facing copy (same audit).** The repurchase-wallet reminder
  counts down to the nearer of the window's last day and the calendar month
  end (both are ₹0 conditions), and once the window's last day has passed
  with a balance it says the window was missed ("your Genos BV for each day
  until this is ₹0 is not counted") instead of "1 day left"; the window's
  last day is only used while the repurchase engine flag is on. The
  rank page counts forfeited days only up to yesterday — today is settled by
  tomorrow's cut-off and can still become the fulfilment day.
- **`RepurchaseWalletGateService` month-end wallet gate.** A single service
  (`clearedAtMonthEnd()`) restores the month-end repurchase-wallet = ₹0 gate
  for GBB, Fortune, rank requalification and AO-GO, replacing the
  branch-specific hold logic §4 described as removed outright. Blocked
  months are `repurchase_wallet_blocked`, gross 0, excluded from the
  denominator — forfeited, never held-and-released.
