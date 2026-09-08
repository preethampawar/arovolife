# Plan — daily engine-health digest email (2026-09-08)

**Status:** approved design, ready to implement. Implementation model: Opus 5.
Branch: `fix/compensation-frozen-roster-and-monthly-close` (on top of `3eef0d0`).
Self-contained: every path, value and test is decided here — do not rely on
conversation context.

## Why

Failed or missed compensation engine runs are visible only inside the admin
console: the sidebar shows an "Engine failures" badge
(`app/resources/views/admin/layouts/admin.blade.php` ≈ line 160, fed by
`EngineStatusService::unresolvedFailureCount()`), and the Engine Runs page shows
each engine's last run. Nothing reaches an admin who did not open the console
that day. A missed scheduled run (the worker was down, the scheduler did not
fire) leaves **no** row at all, so even the badge is silent. Observability proper
is deferred to Phase 12; this is the small backstop the client asked for on
2026-09-08: one email, once a day, only when something needs a human.

## What it does

A scheduled command runs every day at **08:00 IST** (after the last overnight
engine: monthly payout close at 04:00). It builds a health report and, **only
when the report is non-empty**, emails it to the configured admin mailbox. A
healthy day sends nothing.

The report has three sections:

1. **Failed runs not yet re-run** — `engine_runs` rows with
   `status = failed` in the last 30 days that have no later `succeeded` run for
   the same `engine_key` + `period_start` (the exact rule
   `EngineStatusService::unresolvedFailureCount()` already uses — refactor it to
   share the query builder, see Task 1).
2. **Scheduled periods that never ran** — for every scheduled, non-orchestrator
   engine in `EngineRegistry::all()`, the period its most recent scheduled fire
   instant (≤ now) should have produced, when no run of that engine exists for
   that period in **any** status except `failed` (a `skipped` flag-off run counts
   as ran; a `running` row counts as ran — it is reported under 3 if stuck).
3. **Stuck runs** — `status = running` rows whose `started_at` is more than
   **3 hours** ago.

Each line names the engine label, the period (formatted with
`EngineDefinition::displayPeriod()`), the timestamp, and for failures the first
line of `engine_runs.error`. **Every item carries its own numbered "What to do"
steps** (see "Remedy steps" below) — the reader is a non-developer admin who
must be able to close the gap from the email alone. The mail's action button
opens the Engine Runs page: `route('admin.compensation.engine-runs.index')`.

## Remedy steps (the heart of the email)

The Engine Runs page (`/admin/compensation/engine-runs`) has one card per
engine. Each card has a period field — an HTML `type="month"` picker for
monthly engines (value `YYYY-MM`) or a `type="date"` picker for daily ones
(value `YYYY-MM-DD`) — a **Reason** box (required, at least 10 characters) and a
**Preview & Confirm →** button that opens a confirmation showing every engine
the run will fire; confirming queues the chain. The trigger automatically runs
any missing prerequisite first (`EngineChainResolver`: prerequisites for the
same period, skipped when already computed) and never the engines downstream.
Four engines have **no button** (`manuallyTriggerable: false` in
`EngineRegistry`): `gsb.weekly-payout`, `payout.monthly`,
`compensation.monthly-close`, `compensation.monthly-payout-close` — separation
of duties: the person who approves a batch must never be the one who creates it.

`EngineHealthService::remedyFor(EngineDefinition $engine, Carbon $period, string $kind): list<string>`
returns the numbered steps for one item, `$kind` ∈ `failed | missing | stuck`.
Period values in the steps are given BOTH as the picker shows them and as the raw
value, e.g. `"August 2026 (2026-08)"` / `"07 Sep 2026 (2026-09-07)"`, built from
`displayPeriod()` and `formatPeriod()`.

**A. Triggerable engine, `failed` or `missing`** (repurchase.evaluate,
gsb.daily-cutoff, rank.check, rank.bonus, gbb.monthly, fortune.enroll,
fortune.payout, adc.bonus, offers.monthly):

1. Open the admin console → Compensation → Engine Runs (button below).
2. Find the card **"{label}"**.
3. In its **{Month|Date}** field select **{display period} ({raw value})**.
4. In **Reason** type why you are running it, e.g. "Scheduled run on {due_at}
   {failed|did not run} — re-running from the health email".
5. Click **Preview & Confirm →**. The preview lists every engine that will run;
   any missing prerequisite (for example the Repurchase Evaluation the cut-off
   needs) is added for you — this is expected. Click **Confirm**.
6. Wait a minute, then reload Engine Runs: the card's **Last run** must read
   **succeeded** for {display period}. If it reads failed again, do not retry
   more than once — send the error text shown under "Run events →" to the
   developer.
7. Nothing is ever credited twice: a re-run only fills what the failed run left
   empty. (For `failed`, prepend the first line of the recorded error as
   "Error recorded: …".)

**B. `gsb.weekly-payout` (`missing` or `failed`)** — no button:

1. Nothing to click. The weekly batch is created only by the scheduler, so the
   same person never both creates and approves a batch.
2. Every unpaid weekly income is still in the distributors' wallets; **next
   Tuesday's batch sweeps it automatically**, one week late.
3. Only if the next Tuesday also produces no batch (this email will tell you):
   ask the developer to run `php artisan gsb:weekly-payout --date={that Tuesday YYYY-MM-DD}`
   on the server, then approve the batch on Compensation → Weekly Payouts.

**C. `payout.monthly` / `compensation.monthly-payout-close` (`missing` or `failed`)** — no button:

1. First clear every other item in this email for the month — the monthly
   payout refuses to run while any crediting engine for the month is failed
   or missing.
2. Then ask the developer to run
   `php artisan compensation:monthly-payout-close --month={crediting month YYYY-MM}`
   on the server (it re-checks that every crediting engine succeeded, then
   creates the batch).
3. Approve the batch on Compensation → Monthly Payouts. A batch is never
   created twice for the same month.

**D. `compensation.monthly-close` (`failed`)** — orchestrator, no button:

1. The close stopped at one step; that step is listed separately in this email
   with its own instructions (section A). Re-run that step, then the steps
   after it in this order: Rank Qualification Check → Rank Bonus → Growth
   Booster → Fortune Enrolment → ADC Bonus → Fortune Payout → Purchase Offers,
   each for **{display period}** — skip any whose card already reads
   succeeded for that month.
2. When every step reads succeeded, the payout on the 8th proceeds on its own.
   If the 8th has already passed, follow section C.

**E. `stuck`** (any engine, `running` for more than 3 hours):

1. Do not trigger anything for this engine yet — a second run is refused while
   one is recorded as running.
2. A run this long almost always means the compensation queue worker stopped
   mid-run. Ask the developer to restart it (`php artisan queue:restart`, or
   restart the queue container) and to mark the stuck run failed if it does not
   finish within the next hour.
3. Once the card no longer shows running, follow section A for the same
   period.

Every section ends with the same closing line in the mail (see Task 3).

## Decisions (do not reopen)

| Decision | Value | Reason |
|---|---|---|
| Recipient | new setting `notifications.engine_health_email` (string, email format, code default **blank** = off) | Same mechanism as `notifications.admin_order_email` (`SendAdminNewOrderMail`); blank disables |
| Visibility (client, 2026-09-08) | **developer role only.** The key lives in the `notifications` group, which `AdminSettingsController::GROUP_OWNERS` already maps to `developer`; the registry entry ALSO carries an explicit `'owner' => 'developer'` so the decision survives a later group re-ownership. No address is hardcoded anywhere and no per-user routing exists — the developer types the mailbox for each environment into the setting. | The client dropped the earlier "send to the developer's address only" instruction once the recipient turned out to be a setting: a developer-owned setting already means only the developer role can see or change it. Admin, admin-finance and admin-compliance get 404 on the key (stealth rule, [DEV-04]/[DEV-06] pattern in `tests/Modules/Admin/DeveloperRoleTest.php`). The mail itself names no role. |
| Send when healthy | no | The client asked for a signal, not a daily "all fine"; `--always` exists to test the mailbox |
| Time | daily 08:00 IST, `withoutOverlapping()`, `runInBackground()` | after every overnight engine; same style as the other `routes/console.php` entries |
| Queue | notification `implements ShouldQueue`, default queue (NOT `compensation`) | the compensation queue is one process with tries 1 for money jobs (ADR-0011); mail does not belong on it |
| Failure window | 30 days | matches `unresolvedFailureCount()` |
| Stuck threshold | 3 hours | the longest engine (a full replay) is minutes; 3 h is unambiguous |
| Look-back for "missed" | 31 days of fire dates | covers the monthly engines' previous fire |
| Orchestrators | excluded from section 2 (`isOrchestrator`) | their steps are checked individually; `payout.monthly` (manuallyTriggerable=false, cadence 8th 04:00) IS included |
| Worker staleness | not included | `WorkerFreshness::staleReason()` measures the *current* process, not the worker; the existing stale-worker refusal inside the engines already produces a FAILED run, which section 1 reports |

## Files

### Task 1 — `EngineStatusService`: expose the failure rows
`app/app/Modules/Compensation/Services/EngineStatusService.php`

- Extract the query in `unresolvedFailureCount(int $withinDays = 30)` (≈ lines 128–146)
  into `private function unresolvedFailureQuery(Carbon $since): Builder` and add
  `public function unresolvedFailures(int $withinDays = 30): Collection`
  (`Collection<int, EngineRun>`, ordered by `started_at` desc). `unresolvedFailureCount()`
  keeps its signature and calls the shared builder — the sidebar badge must not change.
- Add `public function stuckRuns(int $olderThanHours = 3): Collection` —
  `status = running`, `started_at < now()->subHours($olderThanHours)`, ordered by `started_at`.

### Task 2 — `EngineHealthService` + DTO
New `app/app/Modules/Compensation/Services/EngineHealthService.php` (`final`, constructor-promoted
`EngineStatusService $status`), returning new
`app/app/Modules/Compensation/Services/DTOs/EngineHealthReport.php` (`final readonly` class with
three `list<array{...}>` properties: `failures`, `missing`, `stuck`, and `isHealthy(): bool`).

`report(Carbon $now): EngineHealthReport`:

- `failures`: from `unresolvedFailures()`; each item
  `['engine' => label, 'key' => key, 'period' => displayPeriod, 'started_at' => 'd M Y H:i', 'error' => first line of error, max 200 chars]`.
  Resolve the label through `EngineRegistry::get($key)` inside a `try` — an
  unknown key (a retired engine still in the log) falls back to the raw key.
- `missing`: for each `EngineRegistry::all()` definition where
  `$definition->cadence->isScheduled() && ! $definition->isOrchestrator`:
  walk `$date` back from `$now->copy()->startOfDay()` for up to 31 days until
  `$definition->cadence->runsOn($date)`; the fire instant is
  `$definition->cadence->atOn($date)`; skip the engine if that instant is after
  `$now` (not due yet today — e.g. running the digest manually at 00:03) by
  stepping to the previous fire date instead. Expected period =
  `$definition->periodRelativeTo($fireDate)`; period start =
  `$definition->periodStart($period)`. Missing when no `engine_runs` row exists
  with that `engine_key`, `whereDate('period_start', …)` and
  `status != failed`. Item:
  `['engine' => label, 'key' => key, 'period' => displayPeriod, 'due_at' => fire instant 'd M Y H:i']`.
- `stuck`: from `stuckRuns()`; item `['engine', 'key', 'period', 'started_at']`.
- Every item additionally carries `'steps' => list<string>` from
  `remedyFor($definition, $periodStart, $kind)` (section "Remedy steps"), and
  `'period_value' => formatPeriod(...)` (the raw picker value). For an unknown
  engine key (retired engine) the steps are the single line
  "This engine no longer exists — nothing to re-run; ask the developer to clear the row."
  The month/date picker label in step 3 comes from
  `$definition->periodType === EnginePeriodType::Month ? 'Month' : 'Date'`.

### Task 3 — notification
New `app/app/Modules/Compensation/Notifications/EngineHealthDigestNotification.php`
(`final`, `extends Notification implements ShouldQueue`, `use Queueable`, `via` = `['mail']`),
constructed with the `EngineHealthReport` and the events URL. Subject:
`"Compensation engines need attention — {n} item(s)"` where n = total items.
Body (MailMessage): greeting `'Engine health — ' . today('Asia/Kolkata')->format('d M Y')`,
then an opening line `"{n} item(s) need attention. Each one below tells you exactly what to do."`.
For each non-empty section a bold heading line (`**Failed runs**`, `**Scheduled runs that did not happen**`, `**Runs that appear stuck**`),
then per item a bold title line followed by its numbered steps:

- failures title: `"**{i}. {engine} — {period}** (failed {started_at})"` then `"Error recorded: {error}"`
- missing title:  `"**{i}. {engine} — {period}** (was due {due_at}, no run recorded)"`
- stuck title:    `"**{i}. {engine} — {period}** (running since {started_at})"`

and then `"What to do:"` followed by the item's steps, one `->line()` each, as
`"1. …"`, `"2. …"` (MailMessage renders Markdown, so numbered lines and `**bold**`
work; keep every step under ~200 characters). Then
`->action('Open Engine Runs', $url)` and the closing lines:

- `'A re-run never credits anybody twice: every engine only fills what the failed run left empty, and every trigger is audit-logged with your name and reason.'`
- `'If a step fails again after one retry, stop and send the recorded error to the developer instead of retrying.'`
- `'You will get this email again tomorrow at 08:00 IST if anything is still open; you get nothing on a healthy day.'`

Copy rules: brand lowercase "arovolife" if named; factual only, no money
figures; the word "developer" is fine here (admin-only mail) but never name a
person.

### Task 4 — command
New `app/app/Modules/Compensation/Console/Commands/EngineHealthDigestCommand.php`:

```
signature: compensation:engine-health-digest
           {--always : Send even when every engine is healthy (mailbox test)}
           {--dry-run : Print the report, send nothing}
description: Email the admin mailbox the compensation engine failures, missed runs and stuck runs
```

`handle(EngineHealthService $health)`:
1. `$report = $health->report(Carbon::now('Asia/Kolkata'))`.
2. Print each section as a table (or "nothing to report").
3. `--dry-run` → return SUCCESS.
4. Recipient = `DB::table('settings')->where('key','notifications.engine_health_email')->value('value')`;
   if not `filter_var(..., FILTER_VALIDATE_EMAIL)` → `warn('notifications.engine_health_email is not set — digest not sent.')`, return SUCCESS.
5. If `$report->isHealthy()` and not `--always` → `info('All engines healthy — nothing sent.')`, return SUCCESS.
6. `Notification::route('mail', $recipient)->notify(new EngineHealthDigestNotification($report, route('admin.compensation.engine-runs.events', ['status' => 'failed'])))`;
   `Log::info('compensation.engine_health.digest_sent', ['failures'=>count, 'missing'=>count, 'stuck'=>count])`; return SUCCESS.

Register it in `app/app/Providers/AppServiceProvider.php` in the `commands([...])`
list next to `MonthlyCloseCommand::class` (≈ line 165) — `ScheduledCommandsAreRegisteredTest`
fails otherwise (memory: six commands once shipped unregistered).

### Task 5 — schedule
`app/routes/console.php`, after the `MonthlyPayoutCloseCommand` entry (≈ line 102):

```php
// Daily engine-health digest at 08:00 IST — after every overnight engine
// (monthly payout close is the last, 04:00 on the 8th). Emails the admin
// mailbox only when a run failed, a scheduled period never ran, or a run is
// stuck; a healthy day sends nothing. Recipient: notifications.engine_health_email.
Schedule::command(EngineHealthDigestCommand::class)
    ->dailyAt('08:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();
```

Do NOT add it to `EngineRegistry` — it is not an engine and must not appear on
the Engine Runs page (`EngineRegistryTest` pins registry vs schedule; check
whether that test asserts every scheduled command is in the registry — if it
does, add the command to that test's explicit non-engine allow-list the way
`cooling-off:remind` / `grievance sla` are handled).

### Task 6 — setting
`app/app/Modules/Admin/Http/Controllers/AdminSettingsController.php` — add directly
after the `notifications.admin_order_email` entry (≈ line 679):

```php
'notifications.engine_health_email' => [
    'group' => 'notifications',
    'label' => 'Engine health digest email',
    'description' => 'Mailbox that receives one email a day (08:00 IST) listing compensation engine runs that failed, scheduled runs that never happened, and runs that are stuck. Nothing is sent on a healthy day. Leave blank to turn the digest off.',
    'type' => 'string',
    'format' => 'email',
    'max' => 255,
    'default' => '',
    // Developer-only: explicit even though the group default already says so.
    'owner' => 'developer',
],
```

Check `app/database/seeders/SettingsSeeder.php` — if it seeds `notifications.admin_order_email`
explicitly, seed this key too (blank). Ownership needs no other wiring:
`ownerForKey()` reads the entry's `owner`, and the `notifications` group is
already developer-owned in `GROUP_OWNERS` (≈ line 1191). Add the new key to the
`DEV-04` dataset in `tests/Modules/Admin/DeveloperRoleTest.php` (admin POST →
404, value unchanged) and to the hidden-key loop in `DEV-06` (admin settings
page never renders the key). Developer write path is covered by `DEV-05`.

### Task 7 — help docs (must ship in the same change)
- `app/resources/help/compensation.md`: new section `## Daily engine-health email`
  just before `## Manual controls` (≈ line 248): what it checks (the three
  sections), when (08:00 IST), that a healthy day sends nothing, that the
  receiving mailbox is part of platform configuration (admins cannot change
  it and the help page must NOT mention a settings key, group or role — the
  key is developer-owned and the stealth rule applies), what to do on each line
  (re-run from Engine Runs; for a stuck run restart the compensation worker),
  and that `php artisan compensation:engine-health-digest --always` tests the
  mailbox. Reproduce the five remedy sections (A–E) in the help page so the
  instructions exist in one place the admin can read without an email.
- `app/resources/help/payout-operations.md`, section
  `## Monthly close: crediting on the 1st, payment on the 8th` (≈ line 67): one
  sentence that a failed step also reaches the digest mailbox the next morning.
- `docs/runbooks/artisan-commands.md`: a `### compensation:engine-health-digest`
  entry (options, schedule, examples) in the compensation commands section,
  a short "Developer actions the digest asks for" subsection (restart the
  compensation worker; run `gsb:weekly-payout --date=…`; run
  `compensation:monthly-payout-close --month=…`; and how to mark a run that
  is stuck in `running` as failed — check whether a command already exists
  for that (grep `RunEngineChainJob::abortStaleWorker` / `EngineRunService`);
  if none does, document the one-row SQL
  `UPDATE engine_runs SET status='failed', error='marked failed by developer: worker died mid-run', finished_at=NOW() WHERE id=<id> AND status='running'`
  and nothing broader), and
  a row in the scheduler table (≈ line 670) `| compensation:engine-health-digest | Daily 08:00 | Emails failed / missed / stuck runs; silent when healthy |`.

### Task 8 — tests
New `app/tests/Modules/Compensation/EngineHealthDigestTest.php` (Pest, `RefreshDatabase`,
`Carbon::setTestNow('2026-09-08 08:00:00')` in `beforeEach`, `Notification::fake()`,
setting inserted via `DB::table('settings')->insert(['key' => 'notifications.engine_health_email', 'value' => 'ops@arovolife.test', …])`
— copy the columns from how other tests insert settings; grep `settings')->insert` in `app/tests`).
Create `engine_runs` rows with `EngineRun::create([...])` (fillable: engine_key,
period_start, status, trigger, summary, started_at, finished_at — see
`app/tests/Modules/Compensation/RankQualificationsGateTest.php` helper).

Cases:
1. **failed run is reported** — `gsb.daily-cutoff` failed for 2026-09-07, plus
   succeeded rows for every other scheduled engine's expected period (helper
   `seedHealthyRuns()` that inserts a succeeded run for each scheduled
   non-orchestrator engine's expected period as of 08 Sep 08:00 — derive it via
   the same registry walk, or hard-code: evaluate 2026-09-08, cut-off 2026-09-07,
   weekly payout 2026-09-08, monthly engines 2026-08-01, payout.monthly per
   `periodRelativeTo(2026-09-08)`); run the command; `Notification::assertSentOnDemand(EngineHealthDigestNotification::class, fn ($n, $channels, $notifiable) => …mail route is the setting address && str_contains(rendered subject/first failure line, 'GSB Daily Cut-off'))`.
2. **failure later re-run successfully is not reported** — same failed row plus
   a later succeeded row for the same period → with everything else healthy,
   `assertNothingSent()` and output contains "All engines healthy".
3. **missed period is reported** — healthy seed minus the cut-off row for
   2026-09-07 → sent; the mail lists "GSB Daily Cut-off" with "no run recorded".
4. **flag-off skip counts as ran** — replace an engine's succeeded row by a
   `skipped` row with `summary => ['reason' => 'feature_flag_off']` → nothing sent.
5. **stuck run is reported** — a `running` row started 4 h ago → sent, line
   contains "running since".
6. **blank setting sends nothing** — no setting row, one failed run → exit 0,
   `assertNothingSent()`, output contains "not set".
7. **`--always` sends on a healthy day**; **`--dry-run` never sends** even with failures.
8. **not due yet today** — `Carbon::setTestNow('2026-09-08 00:03:00')`: the
   00:05 evaluate for 2026-09-08 is NOT reported missing (fire instant after now);
   the cut-off for 2026-09-06 (fired 07 Sep 00:10) is the one expected.
9. **remedy steps are specific** — for a failed `gsb.daily-cutoff` on
   2026-09-07 the rendered mail (`$notification->toMail(…)->render()` or
   `->toMail()->introLines`) contains `Find the card "GSB Daily Cut-off"`,
   `select 07 Sep 2026 (2026-09-07)`, `Preview & Confirm`; for a missing
   `gsb.weekly-payout` it contains `next Tuesday's batch sweeps it automatically`
   and `gsb:weekly-payout --date=`; for a failed `payout.monthly` it contains
   `compensation:monthly-payout-close --month=2026-08`; for a stuck run it
   contains `queue:restart`; for a failed `compensation.monthly-close` it lists
   the step order starting `Rank Qualification Check → Rank Bonus`.
10. **remedyFor unit cases** — `EngineHealthService::remedyFor()` for a month
    engine says `In its Month field select August 2026 (2026-08)`; for a day
    engine `In its Date field select 07 Sep 2026 (2026-09-07)`.

Also extend `app/tests/Feature/ScheduledCommandsAreRegisteredTest.php` only if it
enumerates commands explicitly (it likely reads the schedule — then no change).

## Verification (before claiming done)
```
cd app
vendor/bin/pint --dirty
vendor/bin/phpstan analyse --memory-limit=1G --no-progress app/Modules/Compensation app/Modules/Admin app/Providers
php -d memory_limit=2G vendor/bin/pest tests/Modules/Compensation tests/Feature/EngineRegistryTest.php tests/Feature/ScheduledCommandsAreRegisteredTest.php tests/Modules/Admin --compact
php artisan compensation:engine-health-digest --dry-run     # against dev: should list nothing, or the 3 failed replay jobs are NOT engine_runs so nothing
# Signed in as the DEVELOPER role (an admin login must return 404 on the key):
#   Settings → Notifications → "Engine health digest email" = any mailbox for this environment
php artisan compensation:engine-health-digest --always      # on dev mail goes to Mailpit (http://localhost:8027) — check it renders and every step reads correctly
# Sign in as admin: /admin/settings must not show the key; POST to admin.settings.update for it must be 404.
# Add to the staging deploy checklist: the developer sets the same setting on staging (staging mail is real SMTP). No address lives in code or seeders.
```
Known pre-existing failures on this branch, unrelated: `GoogleAnalyticsConsentGateTest`
and `HardeningTest` SEC-08 (environment-dependent).

## Commit
One commit: `feat(compensation): daily engine-health digest email — failed, missed and stuck runs`
with trailer `Compliance-Review: compliance-officer` (admin-only mail, no
distributor-facing copy, no money moved — a review is still required because it
touches the compensation module; run the `compliance-officer` agent on the diff
before committing). Do not push.
