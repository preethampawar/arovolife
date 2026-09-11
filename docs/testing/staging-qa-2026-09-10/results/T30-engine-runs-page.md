# T30 — Engine Runs admin page

Verdict: **PASS-with-notes**

Session mode: **no staff session existed** (loading `/admin/compensation/engine-runs` redirected to `/login`).
I signed in myself as `admin@arovolife.test` (users.id 1, role `admin`, NOT developer) and **signed out at the end**
(verified: `/admin/compensation/engine-runs` → `/login` after logout). Own tab, closed at the end.
No impersonation used. Deployed build = origin/main 6f114500.

Console errors: **none** on either page (`read_console_messages`, pattern `.`, after a fresh load of each).
Slowest page: `/admin/compensation/engine-runs` — TTFB 289 ms, load 450 ms. Events page 178 ms / 239 ms. Both well under 3 s.

---

## Checks

| # | Check | Expected | Observed | Evidence | Result |
|---|---|---|---|---|---|
| 1 | Index page renders | Per-engine cards, all registered engines | 13 engine cards render; page is **not** a paginated run list — the run list lives on `/events` | `/admin/compensation/engine-runs`, page text | PASS |
| 2 | Index "Last run" foots to `engine_runs` | Last run per engine matches DB | All 13 match. e.g. GSB Daily Cut-off "succeeded 10 Sep 2026 12:41 · period 09 Sep 2026 · console · took 551ms" = row id 38 (`started_at 2026-09-10 12:41:23`, `duration_ms 551`). Monthly Close "failed 10 Sep 2026 12:37 · period Aug 2026 · took 10m 0s" = id 37 (`600079` ms) | `SELECT id,engine_key,period_start,status,started_at,duration_ms FROM engine_runs` | PASS |
| 3 | Status badges | succeeded / failed / skipped / running | `succeeded` and `failed` badges render; no skipped/running rows exist to observe | index + events pages | PASS |
| 4 | Trigger / actor columns | trigger + who | Events table has TRIGGER and BY columns. Console rows show `console` / "scheduler / CLI"; my run shows `manual` / `admin@arovolife.test` | events row 1 | PASS |
| 5 | Duration column | ms/s rendering | `1s`, `0s`, `10m 0s`, `557ms` — index shows ms, events rounds to seconds (a 557 ms run reads "1s" and a 39 ms run reads "0s") | events rows 1, 13 | PASS-with-notes (F4) |
| 6 | **Failed run with `error NULL` displayed honestly** | Reason shown, or an explicit "no reason recorded" | **Neither.** Both failed runs render DETAILS = `—` with no indication that a reason was never captured. Root cause: `RecordEngineRun::finished()` writes only status/finished_at/duration_ms — it never writes `error`, ever | `?status=failed` → 2 rows, both `—`; `SELECT COUNT(*),SUM(error IS NULL) FROM engine_runs` → **49 / 49** | **FAIL → F1** |
| 7 | Pagination / filters | Engine + status filters, pagination | Engine dropdown (13 registered engines) and Status dropdown (Failed/Running/Succeeded/Skipped) both work — `?status=failed` returned exactly the 2 failed rows. `paginate(50)`; 49 rows fit one page so no controls render (untested at >50) | `/events?status=failed` | PASS |
| 8 | Events page foots to source | Row count = `engine_runs` | 49 rows rendered = `SELECT COUNT(*) FROM engine_runs` → 49. Source is `engine_runs`, **not** `audit_log` | JS `document.querySelectorAll('tbody tr').length` = 49 | PASS |
| 9 | Events LEDGER column foots | Per-run wallet totals match | Exact match on all 5 attributed runs: run 1 → 11 / ₹52,864.00; run 3 → 11 / ₹26,770.00; run 12 → 1 / ₹10,500.00; run 17 → 12 / ₹37,188.84; run 20 → 3 / ₹256.00 | `SELECT engine_run_id,COUNT(*),SUM(amount_paise) FROM wallet_ledger_entries GROUP BY engine_run_id` | PASS-with-notes (F5) |
| 10 | Trigger form contents | Engine, period picker, reason, confirm modal, help copy | Date/Month input pre-filled with the engine's clamped default (cut-off = 09 Sep, yesterday), required reason min 10 chars, per-field `i` tooltips, "RUNS FIRST" prerequisite chips, Report page / Run events links. Top banner: "Engine runs move real money into wallets… every trigger is permanently audit-logged with your admin ID and the reason you provide." | page text; JS dump of the 9 trigger forms | PASS |
| 11 | Confirm modal | Names engine + impact before submitting | Modal: "Confirm: Run GSB Daily Cut-off (incl. MSB) / This queues … for the chosen period, after first running any missing prerequisite periods of: Repurchase Evaluation. / Wallet credits and result rows are written exactly as a scheduled run would write them. Idempotent — periods already computed are skipped, and nobody is credited twice." | `#confirm-modal` innerText after submit | PASS-with-notes (F6) |
| 12 | **Manual trigger reaches the queue** | Job queued then consumed | Job queued and consumed inside the poll interval (`jobs` was 0 before and 0 after; a new `engine_runs` row appeared with `chain_id` set). `QUEUE_CONNECTION=database`, 9 `queue:work` processes running | `SELECT COUNT(*) FROM jobs`; `ps aux \| grep -c "[q]ueue:work"` → 9 | PASS |
| 13 | New `engine_runs` row | trigger=web/admin, actor = my staff user | **id 49**: `gsb.daily-cutoff` / `2026-09-09` / `succeeded` / **trigger `manual`** / **actor_id 1** / `chain_id 579ead4c-ef94-49c7-b9fb-a6e7632fe4c1` / `duration_ms 557`. (Brief expected `web`; the enum value the app writes is `manual` — correct, not a defect) | `SELECT * FROM engine_runs WHERE id>48` | PASS |
| 14 | `audit_log` row | Actor + reason recorded | **id 3210**, `actor_id 1`, `compensation.engine.manual_run`, details = `{"engine":"gsb.daily-cutoff","period":"2026-09-09","reason":"Staging QA T30 idempotent re-run of an already-succeeded closed day","chain_id":"579ead4c…","warnings":[],"planned_chain":["gsb.daily-cutoff\|2026-09-09"]}` | `SELECT * FROM audit_log WHERE id>3209` | PASS |
| 15 | **Idempotency — no double credit** | Before == after | **Identical.** `gsb_cutoff_results` 2026-09-09 = 317 → 317; `gsb_daily_pools` = 1 → 1; `msb_daily_pools` = 1 → 1; `wallet_ledger_entries` = 40 rows / 12,540,984 paise → 40 / 12,540,984; **0 wallet entries carry `engine_run_id = 49`**; `failed_jobs` unchanged (11, newest 2026-09-05) | before/after snapshots (below) | PASS |
| 16 | Run *reports* "already processed" | Human-readable no-op summary | **No.** The only DETAILS content is `{"output":"","exit_code":0}` — the command's stdout is captured but empty, so the UI proves nothing. Idempotency here is proven by DB counts, not by the page | events row 1 `<details>` expanded | PASS-with-notes (F3) |
| 17 | **In-flight refusal (OpenMonthGuard)** | Current open period refused | **The guard is DISABLED on staging.** `.env` has `COMP_RECOMPUTE_ENABLED=true` + `COMP_RECOMPUTE_ALLOWED_DATABASES=ahdhesuhty`, so `RecomputeGuard::isPermitted()` is true, and `parsePeriodOrFail()` takes the testing branch that **skips the closed-period rule entirely**. Every period input's `max` is the end of next month: `gbb.monthly` max `2026-10`, `rank.bonus` max `2026-10`, `fortune.payout` max `2026-10`, `adc.bonus` max `2026-10`, `gsb.daily-cutoff` max `2026-10-31`. The UI **offers** the current in-flight month and would run it | JS dump of `max` on all 9 forms; `.env`; `AdminEngineRunsController::parsePeriodOrFail()` | **FAIL → F2** (not pressed — hard rule) |
| 18 | Refusal recorded readably if it happens in the job | non-NULL error | N/A from the UI (see 17). For the console path: existing failed run id 43 (`gsb.weekly-payout` 2026-09-10) has `error NULL` **and no log line at all** — `sed` over `laravel.log` 12:52–12:54 yields only unrelated Slack-webhook warnings. Run 37's reason exists only in the log: `compensation.monthly_close.aborted … "The daily cut-off for 31 Aug 2026 has not finished after 10 minutes…"` | log grep; `engine_runs` id 37/43 | **FAIL → F1** |
| 19 | **`payout.monthly` / `payout:monthly-run` (F47)** | Is it triggerable? | **Not triggerable from this page.** `manuallyTriggerable: false` → the card renders "Scheduler-only. Payout batches are created by the scheduler and approved separately on the Payouts page…" and no form. It **is** flag-ungated (`featureFlagClass: null`, badge "Always on") and `requiresClosedPeriod` is false with `defaultPeriod: 'current-month'` — its logged runs are for the **in-flight** month `2026-09` (ids 31, 46, 48) via console. **Not triggered.** | EngineRegistry `payout.monthly`; JS form dump has no `payout.monthly` entry | PASS-with-notes (F7) |
| 20 | `recompute-progress` (GET only) | Renders, idle | Returns raw JSON `{"state":"idle"}` — it is the poll endpoint for the index page, not a rendered page. GET only, no side effects | `/admin/compensation/engine-runs/recompute-progress` | PASS |
| 21 | **Recompute / reset buttons — documented, never pressed** | Buttons + confirm copy + role | Both cards render. **"Reset purchase data"**: "Deletes the orders themselves along with everything derived from them … (2,250 rows on `ahdhesuhty`)"; unlock = type `ahdhesuhty`; modal `Reset all purchase data?` / "Delete every order and everything derived from it?" / "This cannot be undone…". **"Run recompute"**: "Deletes every bonus result, frozen pool, carry-forward, rank qualification, repurchase cycle, wallet credit and payout batch (2,107 rows on `ahdhesuhty`)"; unlock = type `ahdhesuhty`; modal `Destroy and rebuild all compensation data?`. **NEITHER PRESSED.** | index page text; `index.blade.php` data-confirm-* | PASS |
| 22 | Which role sees the recompute cards | developer-only? | **No — not developer-only.** `index()` renders them purely on `RecomputeGuard::isPermitted()` with **no permission check**. I saw them as plain `admin`. POST is `can:finance.record`, held by roles `admin`, `admin-finance`, `developer`. Worse: the GET carries no permission at all, so `admin-operations` and `admin-compliance` would also see the buttons + the DB name + row counts and only hit 403 on submit | routes/web.php:255 & 615–630; controller `index()`; `SELECT r.name,SUM(p.name="finance.record") … GROUP BY r.name` | **F8** |
| 23 | Flag-off engines hidden | flag OFF ⇒ no trace | **Cannot be observed at runtime — every compensation flag on staging is ON** (`features` table: GenosSalesBonus, GrowthBooster, RankBonus, FortuneBonus, AreteDevelopmentCenterBonus, PurchaseOffers, RepurchaseEngine all `true`). Flags NOT toggled. Code-verified only: `index()` does `if ($flagOn === false) continue;`; dependency chips filter on the same test; `events()` `filterOptions` filters the dropdown while `definitions` keeps historical labels; `trigger()` rejects a flag-off engine; `RecordEngineRun` records flag-off runs as `skipped` with `summary {"reason":"feature_flag_off"}` | `SELECT name,value FROM features`; EngineRegistry + controller | PASS (code-verified, not runtime-verified) |
| 24 | Authorization on the page | Which role is required | GET `/admin/compensation/engine-runs` and `/events` require only `auth` + `role:developer\|admin\|admin-operations\|admin-finance\|admin-compliance` (routes/web.php:255; the compensation group at :478 sits at depth 2, i.e. a sibling of — not inside — the `role:developer\|admin` group at :262). Only the POSTs carry `can:finance.record`. Staff accounts in DB: **only** id 1 (`admin`) and id 242 (`developer`) — no lower-privilege account exists to test with, and none was created | routes/web.php; `SELECT u.id,u.email,r.name FROM users u JOIN model_has_roles…` | PASS-with-notes (F8) |

---

## Findings

### F1 — HIGH — A failed engine run records no reason anywhere in `engine_runs`, and the UI shows `—`
`RecordEngineRun::finished()` updates only `status`, `finished_at`, `duration_ms`. It never writes `error`, and never writes
`summary` except on the flag-off path. Result: **all 49 rows in `engine_runs` have `error IS NULL`** — including both
failures. The Events page therefore renders DETAILS `—` for a failed run with no indication that a reason was never
captured, which reads as "failed for no reason" rather than "we did not record the reason".

For run 37 (`compensation.monthly-close` Aug 2026) the reason survives only in `laravel.log`:
`staging.ERROR: compensation.monthly_close.aborted {"month":"2026-08","stage":"preflight","reason":"The daily cut-off for
31 Aug 2026 has not finished after 10 minutes. …"}`.

For run 43 (`gsb.weekly-payout` 2026-09-10 failed) there is **no log line at all** in the 12:52–12:54 window — the failure
reason does not exist anywhere. An operator has no route to it. This is F39/F40/F43/F50 confirmed and generalised: it is
not two bad rows, it is that the column is never populated on any path.

Fix direction: capture the command's exception / stderr in `finished()` (the `CommandFinished` event carries the exit code;
wrap or read the output buffer) and render it in the DETAILS cell; until then, render "no failure reason recorded" rather
than an em dash.

### F2 — HIGH — On staging the premature-freeze guard is off, and a plain `admin` can freeze the current in-flight month
`COMP_RECOMPUTE_ENABLED=true` and `COMP_RECOMPUTE_ALLOWED_DATABASES=ahdhesuhty` in the server `.env` make
`RecomputeGuard::isPermitted()` true, which makes `parsePeriodOrFail()` return early **before** the
`requiresClosedPeriod` check. Every period input's `max` becomes end-of-next-month:

```
gsb.daily-cutoff  value 2026-09-09  max 2026-10-31
gbb.monthly       value 2026-08     max 2026-10
rank.bonus        value 2026-08     max 2026-10
adc.bonus         value 2026-08     max 2026-10
fortune.payout    value 2026-08     max 2026-10
```

So the page offers — and the job would execute — `gbb.monthly` for `2026-09`, freezing September's pool economics
mid-month. This is exactly the 24-Aug-2026 bug class the controller's own comment warns about ("a manual cut-off at 23:27
froze that day's pool at ₹0 before the evening's BV had landed"). **I did not press it** (hard rule: never run an in-flight
engine), so this is verified from the rendered `max` attributes, the `.env`, and the code path — not from a live refusal.

Scope note: `RecomputeGuard` blocks production absolutely (`! isProduction()`), so this cannot reach prod via this flag.
But it means **staging cannot be used to verify the in-flight refusal at all**, and any staging figure a client is shown
can have been produced by a premature freeze. Recommend either a separate staging env with the flag off for sign-off runs,
or moving the closed-period check outside the testing-gate branch and gating only the *horizon*.

### F3 — MEDIUM — The run "summary" is an empty raw-JSON blob
The only DETAILS content the page can ever show is the manual-trigger summary, and it renders as
`{"output":"","exit_code":0}` — raw JSON, with `output` empty because the chain job does not capture the command's stdout.
An admin re-running an already-computed period sees no confirmation that nothing was double-credited; I had to prove that
from the database. Should render a sentence ("317 distributors already processed for 09 Sep 2026 — nothing credited").

### F4 — LOW — Events page rounds duration to whole seconds
A 557 ms run reads `1s` and a 39 ms run reads `0s`, while the index card for the same run reads `557ms`. Two surfaces,
two precisions, for the same column. Prefer the index's formatting on both.

### F5 — LOW — Wallet entries with no `engine_run_id` are invisible to the per-run ledger
2 of the 40 `wallet_ledger_entries` rows have `engine_run_id IS NULL`, netting **−₹2,169.00** (debits). The page's per-run
LEDGER totals sum to ₹1,27,578.84 while the wallet total is ₹1,25,409.84. The page is not wrong — it reports per run — but
nothing on it says a slice of the ledger is unattributed, so the column cannot be reconciled to the wallet.

### F6 — LOW — The confirmation modal does not name the period it is about to run
Copy says "for the chosen period", not "for 09 Sep 2026". Since the date input is a free-form picker whose `max` now
reaches into next month (F2), the confirm step is the last chance to catch a mis-set date and it does not show one.

### F7 — LOW — `repurchase.snapshot` leaks a raw engine key into the admin UI
Events row 44 renders the literal string `repurchase.snapshot` where every other row shows a label, because
`compensation:repurchase-snapshot` has an `engine_runs` row but no `EngineRegistry` entry
(`events.blade.php:76` falls back to `$run->engine_key`). One row in DB. Either register the engine or map its label.

### F8 — MEDIUM — The two destructive testing cards render to staff who cannot use them, and leak the DB name and row counts
`index()` decides whether to render "Reset purchase data" and "Run recompute" **only** from `RecomputeGuard::isPermitted()`
— it never asks whether the viewer holds `finance.record`. The GET route carries no permission beyond
`role:developer|admin|admin-operations|admin-finance|admin-compliance`. So an `admin-compliance` or `admin-operations`
user sees both destructive buttons, the target database name `ahdhesuhty`, the "What would be destroyed" row-count
breakdown (2,250 / 2,107 rows), and only discovers they are forbidden on submit. Also worth flagging against the
brief's expectation: these are **not** developer-only — `admin` and `admin-finance` both hold `finance.record`.
Not runtime-verified (only two staff accounts exist on staging: id 1 `admin`, id 242 `developer`, and I created none).

---

## Mutations made on staging

Exactly one trigger, deliberately idempotent (a closed day that had already succeeded as run id 38).

| Table | id | Before → After |
|---|---|---|
| `engine_runs` | **49** (new) | `gsb.daily-cutoff` / `2026-09-09` / `succeeded` / trigger `manual` / `actor_id 1` / `chain_id 579ead4c-ef94-49c7-b9fb-a6e7632fe4c1` / `duration_ms 557` / `summary {"output":"","exit_code":0}` / `error NULL`. Max id 48 → 49 |
| `audit_log` | **3210** (new) | `compensation.engine.manual_run`, actor 1, reason "Staging QA T30 idempotent re-run of an already-succeeded closed day". Max id 3209 → 3210 |
| `jobs` | 1 `RunEngineChainJob` | 0 pending → queued → consumed by the running `queue:work` worker → 0 pending. No row survives |

Verified unchanged by that run (before → after):

| Table | Before | After |
|---|---|---|
| `gsb_cutoff_results` where `cutoff_date='2026-09-09'` | 317 | **317** |
| `gsb_daily_pools` where `cutoff_date='2026-09-09'` | 1 | **1** |
| `msb_daily_pools` where `cutoff_date='2026-09-09'` | 1 | **1** |
| `wallet_ledger_entries` (all) | 40 rows / 12,540,984 paise | **40 rows / 12,540,984 paise** |
| `wallet_ledger_entries` where `engine_run_id=49` | — | **0** |
| `failed_jobs` | 11 (newest 2026-09-05 01:39:58) | **11** (unchanged) |

Not done, per the hard rules: no recompute-all, no reset-purchase-data, no in-flight engine, no `payout.monthly`, no flag
toggles, no settings changes, no account creation, no migrations, no `.env` edits. All DB access was read-only `SELECT`.

---

## Notes for the orchestrator

1. F2 is the one that changes how the rest of this QA round should be read: `COMP_RECOMPUTE_ENABLED=true` on staging
   lifts the closed-period rule for **every** manual engine trigger, so any earlier task that triggered a monthly engine
   may have frozen an in-flight pool. Worth cross-checking other tasks' mutations.
2. F1 means "engine failures" cannot be diagnosed from the admin console at all — and for run 43 not even from the log.
   If any task needs to know why something failed, the answer may not exist.
3. The in-flight refusal (brief step 4) is **unverifiable on this environment** and I did not force it. It needs either a
   staging env with the flag off, or a Pest test asserting `parsePeriodOrFail()` refuses a current-month
   `requiresClosedPeriod` engine when the guard is closed.
4. `payout.monthly` (F47) is **not** exposed as triggerable on this page — the risk is on the console/scheduler path,
   where its default period is the current, in-flight month.
5. Session: I signed in and signed out; the shared Chrome profile is left signed out.
