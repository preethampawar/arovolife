# T16 — Engine-health digest (`compensation:engine-health-digest`)

Verdict: PASS-with-notes

## What the code does (read, not run)

- `app/Modules/Compensation/Console/Commands/EngineHealthDigestCommand.php` + `Services/EngineHealthService.php` + `Services/EngineStatusService.php` + `Notifications/EngineHealthDigestNotification.php`, spec `docs/plans/2026-09-08-engine-health-digest.md`.
- **Failed** = `engine_runs.status='failed'` in the last 30 days with no later `succeeded` run for the same `engine_key`+`period_start` (`EngineStatusService::unresolvedFailures()`).
- **Missing** = for every *scheduled, non-orchestrator* engine in `EngineRegistry::all()` (excludes `compensation.monthly-close` and `compensation.monthly-payout-close`, both `isOrchestrator: true`): walk back ≤31 days to the most recent fire instant ≤ now, compute the period that fire owes (`periodRelativeTo`, minus a day for the two engines whose cadence note is "runs the previous day"), and flag it missing if no `engine_runs` row exists for that exact `engine_key`+`period_start` with `status != failed`. **Only the single most-recent expected period is ever checked** — it is not a backlog scanner over every missed period, by design (`FIRE_LOOKBACK_DAYS` just locates that one date). A `skipped` (flag-off) or `running` row counts as "ran"; `running` is instead reported under Stuck.
- **Stuck** = `status='running'` rows with `started_at` more than 3 hours old.
- Recipient = `settings.notifications.engine_health_email` (developer-owned; admin gets 404 on the key). Blank/invalid → command exits `SUCCESS` with a `warn()`, nothing sent, no error.
- Healthy day (0 items) → nothing is sent unless `--always`. `--dry-run` prints only, never sends, whatever the flag combo.
- Notification is `ShouldQueue` on the `default` queue (not `compensation`), `via = ['mail']` only — no DB notification row is ever written for this one.
- Subject: `"Compensation engines need attention — {n} item(s)"` (or `"Compensation engines — all healthy"` for `--always` on a healthy day).

## Checks

| # | Check | Result | Evidence |
|---|---|---|---|
| 1 | Schedule entry present, 08:00 IST, isolated from other entries | PASS | `routes/console.php:115-119` on server: `Schedule::command(EngineHealthDigestCommand::class)->dailyAt('08:00')->timezone('Asia/Kolkata')->withoutOverlapping()->runInBackground()`. `runInBackground()` spawns a detached OS process per Laravel's scheduler design, so a PHP exception inside this command cannot propagate into `schedule:run`'s loop over the other `Schedule::command()` entries — verified by reading the scheduler mechanism, not by breaking it on staging (would violate rule 2/no destructive testing). |
| 2 | Was the 08:00 IST run today real | PASS (confirms it did NOT fire — expected) | `git log`/`reflog` on server: code live at 08:00 IST today (2026-09-10) was commit `e3153014` (deployed 2026-09-05 13:33:57 IST); `git show e3153014:app/routes/console.php \| grep EngineHealthDigest` → no match, **the schedule entry did not exist yet**. The commit that adds it (`64b4ceea`, 2026-09-08) is not an ancestor of `e3153014` but is an ancestor of `ecee51d3` (deployed 2026-09-10 09:10:55 IST) and `6f114500` (deployed 10:34:55 IST, current HEAD). laravel.log has no `engine_health` line before my manual run; `08:00` window in the log shows only unrelated "Ops env check" warnings. Not a defect — feature landed after today's 08:00 slot; tomorrow's 08:00 run will be the first real one. |
| 3 | Predict + verify today's report | PASS | Predicted from `engine_runs` query (0 failed/running rows on staging; every scheduled engine already had a `succeeded` row for its currently-due period **except** `gbb.monthly` and `offers.monthly`, which only had a Sept-01-dated row, not the Aug-2026 row the "previous month" cadence actually owes). Ran the command — output matched exactly: `Missing: Growth Booster Bonus — Aug 2026 (due 01 Sep 2026 00:45)`, `Purchase Offers — Aug 2026 (due 01 Sep 2026 04:00)`; 0 failed, 0 stuck. |
| 4 | The 00:05/00:30 schedule-time mismatch does NOT cause a false "missed" | PASS | `repurchase.evaluate` fired at the *old* 00:30 IST today (pre-deploy cron) and is stamped `period_start=2026-09-10, status=succeeded`. The digest matches only on `engine_key`+`period_start`+status, never on clock time, so it was correctly not flagged missing. Same for `gsb.daily-cutoff` (00:10 old vs new — matched by period `2026-09-09`) and `gsb.weekly-payout` (matched by period `2026-09-08`). |
| 5 | Command output vs mail content | PASS | Console table matched the two missing items; ran for real (no `--dry-run`). |
| 6 | Recipient setting | PASS | `settings.notifications.engine_health_email = preetham.pawar@gmail.com` (developer-owned key, matches brief). |
| 7 | Mail transport is real, not `log`/`array` | PASS | `.env`: `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.elasticemail.com`, `MAIL_PORT=2525`, `MAIL_FROM_ADDRESS="noreply-staging@karonix.com"` (password not printed, per rules). |
| 8 | Digest actually left the app | PASS | Command ran once (real send, not `--always`/`--dry-run`, since staging already had a non-healthy report — no need for a second run). `laravel.log`: `[2026-09-10 12:21:57] staging.INFO: compensation.engine_health.digest_sent {"failures":0,"missing":2,"stuck":0}`. `jobs` table was empty immediately after (queue worker drained the notification job fast, consistent with the &lt;12s probe-job baseline). No new `failed_jobs` row and no SMTP/mail exception appear in the log after that timestamp — the earlier "421 Daily limit exceeded" ElasticEmail errors are all dated 2026-09-05, not today. |

## What the user should check in their inbox

- **To:** preetham.pawar@gmail.com
- **From:** noreply-staging@karonix.com (via ElasticEmail SMTP)
- **Subject:** `Compensation engines need attention — 2 item(s)`
- **Sent around:** 2026-09-10, 12:21–12:22 IST
- **Body should list:** Growth Booster Bonus — Aug 2026 (due 01 Sep 2026 00:45), and Purchase Offers — Aug 2026 (due 01 Sep 2026 04:00), each with numbered "What to do" steps and an "Open Engine Runs" button.

## Defects

None in the digest command/service/notification itself — behaviour matches `docs/plans/2026-09-08-engine-health-digest.md` on every point checked, including the two edge cases the plan explicitly calls out (single-most-recent-period check, time-tolerant period matching).

- **[Medium, cross-reference to T14, not owned by this task]** The digest correctly surfaced that `gbb.monthly` and `offers.monthly` genuinely never produced an Aug-2026 (`period_start=2026-08-01`) run on staging — only a `2026-09-01`-dated row exists for each (from the same in-flight-month test data baseline flags for T14). This looks like real data, not a digest bug: the other 5 crediting engines (`rank.check`, `rank.bonus`, `adc.bonus`, `fortune.enroll`, `fortune.payout`) all do have an Aug-2026 succeeded row (from the old pre-deploy schedule) and were correctly NOT flagged. Recommend T14 reconciles whether GBB/Offers for August 2026 need a real backfilled run before go-live, since the digest will silently stop mentioning it once it's older than the "most recent period" window even if never actually run.
- **[Low, infra, pre-existing]** ElasticEmail (`smtp.elasticemail.com`) returned "421 … Daily limit exceeded" 454 times in the log and left 4 rows in `failed_jobs` (ids 235-238) on 2026-09-05 — a real risk that a future digest send could silently fail into `failed_jobs` if staging's daily quota is exhausted by other test traffic that day, with no retry/alert path back to the developer. Not triggered today (digest sent cleanly), and not something to fix under this task, but worth a line in the staging mail runbook.
- **[Info, unrelated]** `failed_jobs` id 239 ("Call to a member function bindTo() on null" in `serializable-closure`) failed at 2026-09-10 12:10:26 IST, minutes before my run — this is the "probe failed job 239" T01 is already tasked with removing; confirmed it is unrelated to the digest send (different exception class, different queue timing, no serializable-closure/bindTo error anywhere near the `digest_sent` log line).

## Mutations made on staging

- Ran `php artisan compensation:engine-health-digest` once (no destructive flags) on 2026-09-10 ≈12:21:57 IST. This is a read + send-mail action: it queried `engine_runs`/`settings`/`EngineRegistry` (read-only) and sent one real email via the configured SMTP relay to preetham.pawar@gmail.com. No database rows were written by the command itself (it does not log to `engine_runs`, by design — see plan "Do NOT add it to EngineRegistry"). No other mutation made.

## Notes for the orchestrator (≤10 lines)

- Digest logic verified correct against the plan on every checked point; real SMTP send confirmed via log absence-of-error + empty `jobs` table, not merely "should work."
- Today's 08:00 IST run did not happen because the feature wasn't deployed yet at that time (code landed 09:10 IST today) — expected, not a defect. Tomorrow 08:00 IST will be the first real scheduled fire.
- Ask the user to confirm the email in their own inbox: subject `Compensation engines need attention — 2 item(s)`, ~12:21 IST today, from noreply-staging@karonix.com.
- Flag the Aug-2026 GBB/Purchase-Offers gap to whoever owns T14 — same underlying in-flight-month test data the baseline already calls suspect.
