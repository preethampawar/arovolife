# T42 — Log + failed-jobs sweep across the whole QA window

Window: 2026-09-10 12:00 IST → 2026-09-11 ~08:30 IST (UTC 2026-09-10 06:30 → 2026-09-11 03:00; server clock UTC, app tz Asia/Kolkata).

Verdict: **PASS-with-notes**

## Checks

| # | Check | Result | Evidence |
|---|---|---|---|
| 1 | laravel.log level counts in window | 3535 entries total: WARNING 3517, ERROR 8, INFO 10. No CRITICAL/ALERT/EMERGENCY. | `/tmp/t42_window.log` built from `storage/logs/laravel.log` (single file, no dated rotations found — only `deploy.log` sits alongside it) |
| 2 | Every ERROR/CRITICAL/ALERT/EMERGENCY explained | All 8 explained (see table below) | see Error/Warning table |
| 3 | WARNING de-dup | 100% (3517/3517) is one message: `Ops env check: LOG_SLACK_WEBHOOK_URL is empty — critical alerts will not reach Slack.` first `2026-09-10 12:00:01`, last `2026-09-11 08:29:56` | pre-existing/environmental — `LOG_SLACK_WEBHOOK_URL` unset, logged on ~every request |
| 4 | PII grep (PAN-shape, 12-digit, password, otp, bank account) | 0 hits for all patterns in the window slice | `grep -coE` for each pattern → 0 |
| 5 | failed_jobs | 11 pre-existing rows (228–238), none inside the window | see failed_jobs table |
| 6 | `jobs` queue count now | 0 | `SELECT COUNT(*) FROM jobs` → 0 |
| 7 | Stale jobs (>5 min old) | none | 0 rows |
| 8 | Queue workers alive | default×3, compensation×1 (tries=1), otp×1, all for `ahdhesuhty`, running since Sep10 | `ps aux \| grep queue:work` |
| 9 | engine_runs 50/51 | both succeeded, fast | see scheduler table |
| 10 | GSB cutoff BV vs bv_ledger_entries, incl. T33 reversal | pool `company_bv_paise=59900` = net BV in `bv_ledger_entries` for 2026-09-10 (accrual 239800 − reversal 179900) — **reversal correctly reflected** | see BV reconciliation below |
| 11 | 08:00 engine-health digest | ran, `failures=2, missing=0, stuck=0` — matches the only 2 `failed` rows in `engine_runs` (37, 43) exactly | log line + `engine_runs` query |
| 12 | payout:auto-retry / expire-unpaid / reconcile silence | correctly explained (see Scheduler section) | `routes/console.php`, command source |
| 13 | crontab | `crontab -l` under the given SSH user (`master_mvgumpkwtu`) points at a **different application** (`dssjtmjcpv`), not `ahdhesuhty` — could not directly confirm `ahdhesuhty`'s own cron entry (no permission to view another system user's crontab or `/etc/cron.d`) | see Scheduler notes — indirect evidence (on-time overnight runs) confirms it fires in practice |
| 14 | nginx/php-fpm access log 5xx in window | 3 in window (1 explained by T35, 2 new — see Findings F1) | `backend_*.access.log` + `.access.log.1` |

## De-duplicated ERROR / WARNING table

| Level | Message (trimmed) | Count | First | Last | Classification |
|---|---|---|---|---|---|
| WARNING | Ops env check: LOG_SLACK_WEBHOOK_URL is empty — critical alerts will not reach Slack. | 3517 | 2026-09-10 12:00:01 | 2026-09-11 08:29:56 | (b) pre-existing/environmental |
| ERROR | Call to a member function bindTo() on null (serializable-closure `Native.php:200`) | 3 | 2026-09-10 12:10:26 | 2026-09-10 12:10:26 | (a) T01 — deliberate `failed_jobs` probe row 239 (queued+removed by T01; confirmed in `T01-setup.md`/`T16-engine-health-digest.md`) |
| ERROR | compensation.monthly_close.aborted `{"month":"2026-08","stage":"preflight","reason":"The daily cut-off for 31 Aug 2026 has not finished after 10 minutes…"}` | 1 | 2026-09-10 12:47:11 | 2026-09-10 12:47:11 | (a) T13 monthly-close test (engine_runs id 37, already flagged as finding F1 in `T30-engine-runs-page.md`) |
| ERROR | Upload refused — malware scanner unavailable `{"reason":"No malware scanner is configured. Set CLAMAV_HOST…"}` | 1 | 2026-09-10 19:13:06 | 2026-09-10 19:13:06 | (a) T25 — deliberate ClamAV refusal test |
| ERROR | Expected response code "250" but got empty code (Symfony Mailer SmtpTransport) | 2 | 2026-09-10 19:17:05 | 2026-09-10 23:26:40 | (b) pre-existing/environmental SMTP config |
| ERROR | SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'sort_order' cannot be null (`content_pages` insert) | 1 | 2026-09-11 01:07:10 | 2026-09-11 01:07:10 | (a) T35 — deliberate 500 test |
| ERROR | `App\Modules\Shared\Support\Csv::safe(): Argument #1 ($value) must be of type string\|int\|null, float given` — `AdminGrievanceReportController.php:78` | 2 | 2026-09-11 08:30:02 | 2026-09-11 08:30:24 | **(c) NEW — Finding F1** |

INFO (10 lines, all benign, no action): 2× `compensation.engine_health.digest_sent`, 2× `gbb.monthly.prior_month_check_waived`, 1× `gbb.pool.frozen` (T13), 3× `engine.chain.*` (GSB cutoff manual re-run, T30), 2× `gsb.pool.frozen` / `msb.pool.frozen` (the real overnight cutoff, see below).

## failed_jobs (11 rows, all pre-existing, none in window)

| id | queue | job class | failed_at | exception (first ~80 chars) |
|---|---|---|---|---|
| 228 | compensation | RecomputeAllJob | 2026-08-29 17:16:19 | RecomputeNotPermitted: Refusing to r… |
| 229 | default | OrderStatusChangedNotification | 2026-09-01 21:28:29 | UnexpectedResponseException: Expected respons… |
| 230 | default | OrderPlacedNotification | 2026-09-01 21:31:39 | UnexpectedResponseException: Expected respons… |
| 231 | default | AdminNewOrderNotification | 2026-09-01 21:31:40 | UnexpectedResponseException: Expected respons… |
| 232 | default | OrderPlacedNotification | 2026-09-01 21:33:09 | UnexpectedResponseException: Expected respons… |
| 233 | default | OrderStatusChangedNotification | 2026-09-01 21:33:10 | UnexpectedResponseException: Expected respons… |
| 234 | default | AdminNewOrderNotification | 2026-09-01 21:33:10 | UnexpectedResponseException: Expected respons… |
| 235 | default | OrderStatusChangedNotification | 2026-09-05 01:39:09 | UnexpectedResponseException: Expected respons… |
| 236 | default | OrderPlacedNotification | 2026-09-05 01:39:57 | UnexpectedResponseException: Expected respons… |
| 237 | default | OrderStatusChangedNotification | 2026-09-05 01:39:58 | UnexpectedResponseException: Expected respons… |
| 238 | default | AdminNewOrderNotification | 2026-09-05 01:39:58 | UnexpectedResponseException: Expected respons… |

(id 239, the T01 SerializableClosure probe row, failed inside the window at 12:10:26 but was removed by T01 per its own result file — count now = 11, matching T01's after-count.)

## Scheduler overnight table

| engine_key | id | status | trigger | started_at | duration_ms | notes |
|---|---|---|---|---|---|---|
| repurchase.evaluate | 50 | succeeded | console | 2026-09-11 00:05:04 | 108 | on schedule (`dailyAt('00:05')`) |
| gsb.daily-cutoff | 51 | succeeded | console | 2026-09-11 00:10:03 | 271 | on schedule (`dailyAt('00:10')`); produced 317 `gsb_cutoff_results` rows for cutoff_date 2026-09-10 |

**BV reconciliation for the 2026-09-10 cutoff:** `bv_ledger_entries` for `DATE(effective_at)='2026-09-10'` = 2 accruals (order 17: +179900 paise @17:23:19, order 18: +59900 paise @17:27:22) + 1 reversal (order 17: −179900 paise @23:13:59, the T33 return) → net **59900 paise**. `gsb.pool.frozen` log line for `cutoff_date":"2026-09-10"` shows `company_bv_paise:59900` — an exact match. **The pool correctly priced against the day's net BV, including the T33 reversal**, not the gross pre-reversal figure.

**08:00 engine-health digest:** `[2026-09-11 08:00:03] staging.INFO: compensation.engine_health.digest_sent {"failures":2,"missing":0,"stuck":0}`. `engine_runs` has exactly 2 rows with `status='failed'` system-wide: id 37 (`compensation.monthly-close`, 2026-09-10 12:37:11) and id 43 (`gsb.weekly-payout`, 2026-09-10 12:52:33) — both already known/explained QA-test failures (T13/T30). The digest is sent via `Notification::route('mail', …)` (ad-hoc route, by design not persisted to `notifications` or `audit_log` — confirmed by reading `EngineHealthDigestCommand::handle()`), so the log line + the failure count match is the available server-side evidence; email body content itself wasn't inspected (no mailbox access).

**payout:auto-retry (`dailyAt('11:00')`):** correctly silent — its only occurrence in-window would be 2026-09-11 11:00 IST, which is after the window closes (~08:30). No finding.

**payments:reconcile (`everyFiveMinutes`) / orders:expire-unpaid (`cron('2-59/5 * * * *')`):** correctly silent in `laravel.log`. Both write only to `Log::channel('payments')` and only on specific conditions (gateway-unreachable warning, cancel-declined, sync failure, invoice-gap warning) — not on every run; `$this->info(...)` console output goes to `/dev/null` under cron. `PaymentsReconcileCommand` short-circuits with "Razorpay is not configured; nothing to reconcile" when the gateway isn't configured (Razorpay flag is OFF per current build). No `storage/logs/*payment*` file exists, confirming zero warnings fired in ~20.5 hours / ~246 five-minute ticks. Silence is expected behaviour, not a gap.

**crontab:** `crontab -l` for the SSH user `master_mvgumpkwtu` shows only `* * * * * cd /home/master/applications/dssjtmjcpv/public_html && … php artisan schedule:run` — a **different Cloudways application** (`dssjtmjcpv`, confirmed unrelated: it has its own separate `queue:work` processes in `ps aux`), not `ahdhesuhty`. `/etc/cron.d/` has no `ahdhesuhty` entry, and `master_mvgumpkwtu` lacks permission to list another system user's crontab or read `/var/spool/cron/crontabs/` (uid 1003, not root). So the exact cron line for `ahdhesuhty` could not be directly read with the credentials given. **Indirect evidence is strong that it is in fact running**: engine_runs 50 and 51 fired unattended, overnight, at 00:05:04 and 00:10:03 — 1–3 seconds past their configured `dailyAt` minute, which is not something a human would trigger manually while asleep, and matches the documented plan (`engine_schedule_2026-09-05` memory). Noted as an access/visibility gap for the orchestrator, not a functional failure.

## Findings

**F1 — High — NEW, unexplained: Grievance compliance-report CSV export throws a 500 (`Csv::safe()` TypeError on a float value)**
- `[2026-09-11 08:30:02]` and `[2026-09-11 08:30:24]` `staging.ERROR: App\Modules\Shared\Support\Csv::safe(): Argument #1 ($value) must be of type string|int|null, float given, called in .../AdminGrievanceReportController.php on line 78` (userId 242).
- Matching 500s in the nginx/php-fpm access log: `GET /admin/grievances/report/export` → `500` at `11/Sep/2026:03:00:02 +0000` and `11/Sep/2026:03:00:24 +0000` (UTC = the same two IST timestamps above).
- Root cause: `Csv::safe(int|string|null $value): string` (`app/Modules/Shared/Support/Csv.php:20`) does not accept `float`; `AdminGrievanceReportController::export()` (line 78) passes a raw `$row[$column]` — one `GrievanceComplianceReport` column (almost certainly `median`, the only non-integer metric per the column list in `T36-analytics-grievances-adc.md` check #16) is a float, not `int|string|null`, so PHP's strict type check throws before any CSV bytes are streamed.
- This is a **regression discovered after T36 signed the export off as PASS** earlier in the QA window (T36 exercised the export successfully and verified it carries no PII) — the median must have become a non-integer value only later (more grievance data landed from other QA tasks in the interim), so this is genuinely new, not a re-flag of a T36 finding.
- Impact: the export is a compliance artifact ("This file goes to the Compliance Committee and, on request, to a regulator" per the controller's own comment) — it is currently broken whenever the trailing-12-month median resolution time is fractional, which will recur in production.
- Not a QA-action artifact, not PII (the TypeError message itself carries no PII).

No other new/unexplained findings. The 12:47:11 monthly-close abort and the 19:13/19:17/23:26/01:07 errors are all pre-classified per the task brief and cross-checked against T13/T25/T30/T35 result files.

## Mutations: none

All actions were read-only: `grep`/`awk`/`sort | uniq -c` over log files copied to `/tmp` on the remote host (outside the app directory), read-only `SELECT` queries against MySQL, `ps aux`, `crontab -l`, and local `Read`/`grep` of the repo source to identify root cause. No `queue:retry`, no `queue:clear`, no deletions, no writes to the app or its database.
