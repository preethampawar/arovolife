# T36 — Analytics, dormancy, grievances admin + report + SLA sweep, Arete centres

Verdict: FAIL (one Critical, one High, findings below; everything else PASS)

## Session mode
Staff session pre-existing when this task started: developer super-admin (user 242, preetham.pawar@gmail.com), signed in as "VJ" in the UI. Used as-is in a new tab (tabId 1049838113) I created and closed at the end. Never signed it out. The two other tabs open in the shared window (1049838082, 1049838099) belong to the user/orchestrator and were not touched. All mutations below were performed by actor_id 242.

## Checks
| # | Check | Page figure | SQL figure | Result | Evidence |
|---|---|---|---|---|---|
| 1 | Analytics — Paid orders (30d window 2026-08-12→09-11) | 17 | 17 | PASS | `orders` paid_at not null, status not in (cancelled,refunded), created_at in window |
| 2 | Analytics — Gross value | ₹25,685.00 | 2,568,500 paise | PASS | same query, SUM(total_paise) |
| 3 | Analytics — Average order | ₹1,510.88 | intdiv(2568500,17)=151088 paise | PASS | |
| 4 | Analytics — BV generated | **20,399 BV** | true value 2,039,999 BV (bv_paise 203,999,900) | **FAIL — 100x under-report** | see Finding F1 |
| 5 | Analytics — Cancelled/refunded | 0 / 1 | 0 / 1 | PASS | |
| 6 | Registration funnel (6 stages) | 69/69/69/69/2/2 | 69/69/69/69/2/2 | PASS | orientation_views, consents, distributors, kyc_documents, users.activated_at |
| 7 | Commerce funnel (5 stages) | 6/0/18/18/1 | 6/0/18/18/1 | PASS | carts, cart_items, orders |
| 8 | Distributor base (as of today) | 317/317/7/7/310 | 317/317/7/7/310 | PASS | distributors, orders |
| 9 | Monthly buyer retention, Sep row | buyers 7, returning 0, pct — | Aug buyers=0 so returning=0, pct null | PASS | |
| 10 | Highest volume leaderboard (7 rows) | 6,005 / 5,000 / 3,650 / 2,806 / 2,650 / 144 / 144 BV | true values 600,599 / 500,000 / 365,000 / 280,600 / 265,000 / 14,400 / 14,400 BV | **FAIL — same 100x bug** | see Finding F1 |
| 11 | Analytics — no per-distributor income/earnings exposed | leaderboard shows only BV/orders/team, no ₹ | — | PASS (hard rule 3) | |
| 12 | Dormancy — Under notice / Dormant / Terminated tabs | 0 / 0 / 0 ("Nobody in this state.") | 0 / 0 / 0 | PASS | `distributors` inactivity_notice_at, effective_date vs 12mo cutoff, terminated_at — all min effective_date is 2026-07-04, well inside window |
| 13 | Dormancy copy (12 months + 7 days notice, sweep OFF) | matches `ComplianceTerminationSettings` | — | PASS | withdraw-notice control not touched |
| 14 | Grievances queue open count | 2 → 1 after ticket 2 closed | tickets status not settled | PASS | |
| 15 | Grievance report month figures (Sep 2026) | received 2, resolved 1, closed 1, open 1, ack-in-time 2/2, breaches 0/0/0, 60-day ext. 2, median 0.5d | matches ticket_events/tickets after mutations | PASS | median = (resolved_at−created_at) for ticket 2 ≈ 0.549d → 0.5 |
| 16 | Grievance report CSV export columns | aggregate only: month, received, resolved, closed, still_open, ack_owed/not_owed, ack_in_time, 3 breach counts, third_party_extensions, anonymous, median | code-verified, `GrievanceComplianceReport::csvColumns()` | PASS — **no reporter PII of any kind** (not even ticket subject) | `AdminGrievanceReportController::export()`, `GrievanceComplianceReport.php:177` |
| 17 | Arete centres list | 2 rows, both active | `arete_centers` 2/2 active | PASS | |
| 18 | Arete centre 1 "This month BV" | 3,50,000 BV | bv_ledger_entries×orders sum = 35,000,000 paise = 350,000 BV | PASS (correct lakh grouping, single division — contrast with F1) | |
| 19 | Arete centre 2 monthly cap override | ₹19,984 | monthly_cap_override_paise=1,998,400 | PASS | |
| 20 | ADC applications page | "0 open · 0 approved · 0 rejected", empty-state copy | `arete_center_applications` 0 rows | PASS | |
| 21 | Arete centre create form (not submitted) | Owner ADN field present regardless of Type selection | — | PASS-with-note, see F2 | |
| 22 | Distributor-side `/arete-centres` directory | members-only via `auth` middleware + `abort_unless` in controller; no owner ADN/member counts/earnings shown | route `Route::middleware(['auth'])` (web.php:911) wraps it; controller docblock + code | PASS (verified by code read; not re-tested by logging out of shared staff session, which is prohibited) | |
| 23 | Separation of duties — `grievance.handle`, `compliance.discipline` exclude `admin-finance` | `admin-finance` role on staging holds only `finance.record`, `audit.read` | `role_has_permissions` query on staging DB | PASS | matches `RolesAndPermissionsSeeder.php` SCOPED/SHARED maps exactly |
| 24 | Any scoped role combining finance + grievance/discipline | none — only super-roles `admin`/`developer` can do both, by documented design | seeder header comment + staging role_has_permissions | PASS (expected exception) | |
| 25 | SLA sweep schedule | `grievance:sla-sweep` hourly, Asia/Kolkata, withoutOverlapping, runInBackground | `routes/console.php:142` | PASS | |
| 26 | SLA sweep last execution evidence | **could not confirm** | `engine_runs` has zero `grievance*`/`sla*` rows (command isn't part of `EngineRegistry`); `laravel.log` has zero "grievance" hits at any hourly boundary | **UNVERIFIED**, see Finding F3 | |

## Findings

### F1 — Critical — Analytics BV figures under-reported by 100x (double unit conversion)
`resources/views/admin/analytics/index.blade.php` lines 50 and 202 compute `$totals['bv_paise'] / 100` (and `$row['bv_paise'] / 100`) **before** passing the value into `@bv(...)`. But `App\Modules\Commerce\Support\Bv::format()` already does `intdiv($paise, 100)` internally — it expects raw paise. The blade divides by 100 twice, so every BV figure on `/admin/analytics` is exactly 100x too small:
- Headline "BV GENERATED" tile: shows 20,399 BV, true value is 2,039,999 BV.
- "Highest volume in the window" table: all 7 rows are 100x too small (verified row-by-row against `bv_ledger_entries` SUM per distributor — e.g. ADN 608628172 shows 6,005 BV, true value 600,599 BV).

Everywhere else the same `Bv::format()`/`@bv` pattern is used correctly with raw paise (e.g. the Arete Centres "This month BV" column correctly shows 3,50,000 BV for 35,000,000 paise), so this is a page-specific blade bug, not a formatter-wide defect. Fix: drop the `/ 100` in both call sites, pass `$totals['bv_paise']` and `$row['bv_paise']` directly.

### F2 (Critical, confirmed live — not theoretical) — Company-default Arete Centre has an assigned owner and already received a real ADC Bonus payout
- Centre 1 ("Arovolife Company Centre", `centre_type='company'`, `is_company_default=1`) is documented in the UI itself ("a company centre has no owner") and in code (`AreteDevelopmentCenterBonusService.php:65-66`: *"Company-default centre has no assigned distributor yet — skip payout."*) as never having a payee.
- On staging, `arete_centers.assigned_distributor_id = 33` (ADN 678721891) is set for this row — verified via `Edit Centre` page ("Owner ADN: 678721891") and DB.
- The ADC Bonus engine only checks `assigned_distributor_id === null` to decide whether to skip a centre — it does **not** check `centre_type`. Because the company centre has an owner set, the engine already ran and credited it: `adc_bonus_results` id=1, center_id=1, distributor_id=33, month_start=2026-09-01, status=`credited`, net_paise=1,050,000 (3% of the centre's 3,50,000 BV). `wallet_ledger_entries` id=23 confirms the actual wallet credit: type=`adc_credit`, amount_paise=1,050,000, memo="Arete Dev Center Bonus — Arovolife Company Centre 2026-09-01".
- Root cause is two-fold: (a) the Add/Edit Centre form shows the "Owner ADN" field regardless of Type, with no validation blocking an owner ADN when Type = Company centre; (b) the bonus engine's skip-check trusts `assigned_distributor_id` alone instead of also checking `centre_type !== 'company'` as its own comment implies it should.
- Impact: a distributor (ADN 678721891, user 34) has already been paid ₹10,500 sourced from BV collected at the company-owned default centre — money that, by the platform's own documented design, nobody should receive. This is compliance-adjacent (hard rule 2 attribution correctness) and should be raised with the compliance-officer subagent before this reaches production; I did not attempt to reverse the credit (out of scope, read-only task).

### F3 — Medium — Grievance SLA sweep execution cannot be confirmed from the standard evidence trail
`grievance:sla-sweep` is not registered in `EngineRegistry`, so it never writes to `engine_runs` (confirmed: zero rows with `engine_key LIKE '%grievance%'` or `'%sla%'`). It also has no `Log::` calls of its own and its `Schedule::command()` definition has no `->sendOutputTo()`/`->appendOutputTo()`, so successful no-op runs (the common case — neither test ticket is anywhere near a breach) leave no trace in `laravel.log` either. The crontab on this server (`master_mvgumpkwtu`) shows `* * * * * cd .../applications/dssjtmjcpv/public_html && ... schedule:run` — a different application path than the one under test (`ahdhesuhty`) — which is surprising, but other scheduled compensation engines (`gsb.daily-cutoff`, `repurchase.evaluate`) *did* fire at their exact configured IST times today per `engine_runs`, so the scheduler is reaching this app somehow (not investigated further — out of scope for T36; flagging as worth a follow-up for whoever owns the scheduler-audit task). Net effect: I cannot state whether `grievance:sla-sweep` actually ran on staging today, only that its schedule definition is correct and neither open ticket is near a breach regardless.

### Positive note — staging SMTP (F111) did not reproduce during this session
All grievance notification sends during the ticket-2 lifecycle (assignment implicit, response, third-party flag, status-update, resolution, closure) show no corresponding SMTP error in `laravel.log` at their timestamps, and `failed_jobs`/`jobs` show no new rows from this session (most recent failed_jobs row is from 2026-09-05, daily-limit/empty-response errors). Mail appears to have sent successfully this time; F111 is intermittent, not a hard failure.

### Note — grievance report CSV export was fetched via browser navigation
`Export 12 months (CSV)` was reached by navigating directly to `/admin/grievances/report/export`, which triggers a `streamDownload` (Content-Disposition: attachment) in the shared Chrome session — this likely saved a small CSV file to the machine's download folder. The file contains zero PII (see check #16 — it's an aggregate-only report), so there is no confidentiality concern, but flagging the mechanism for completeness. Verified server-side via `audit_log` (`grievance.report_exported`, actor 242, x2) and via full source review of `AdminGrievanceReportController::export()` and `GrievanceComplianceReport::csvColumns()` rather than reading the downloaded file itself.

## Mutations made on staging

### Ticket 2 (`tickets` id 2, GRV-260910-39CCK, distributor 1) — full lifecycle
| Step | Action | DB effect | Event id | Audit id |
|---|---|---|---|---|
| 1 | Assign to me | `assigned_to_user_id`=242 | ticket_events 8 | audit_log 3252 |
| 2 | Respond ("QA test — please ignore") | `first_response_at`=2026-09-11 08:25:10, status→in_progress | ticket_events 9 | audit_log 3254 |
| 3 | Waiting on a third party ("QA test — please ignore") | `third_party_dependent`=1, `sla_resolution_at` extended 2026-10-10→2026-11-09 | ticket_events 10 | audit_log 3256 |
| 4 | Send progress update ("QA test — please ignore") | `last_status_update_at`=2026-09-11 08:25:59 | ticket_events 11 | audit_log 3258 |
| 5 | Resolve ("QA test — please ignore") | status→resolved, `resolved_at`=08:26:43, `resolution_note` set, `retention_until`=2033-09-11 | ticket_events 12 | audit_log 3260 |
| 6 | Close ("QA test — please ignore") | status→closed, `closed_at`=08:27:09, `closed_by_user_id`=242 | ticket_events 14 | audit_log 3262 |

All breach columns (`acknowledgement_breached_at`, `first_response_breached_at`, `resolution_breached_at`) remained NULL throughout — no breach occurred, resolved well inside every clock.

### Ticket 1 (`tickets` id 1, GRV-260910-2PF6K, public reporter) — escalate + third-party only, left open
| Step | Action | DB effect | Event id | Audit id |
|---|---|---|---|---|
| 1 | Escalate to Grievance Officer ("QA test — please ignore") | `escalation_level` 1→2, `escalated_at`=2026-09-11 08:29:01 | ticket_events 15 | audit_log 3265 |
| 2 | Waiting on a third party ("QA test — please ignore") | `third_party_dependent`=1, `sla_resolution_at` extended to 2026-11-09 | ticket_events 16 | audit_log 3267 |

Final status: `acknowledged` (unsettled/open), as required — no respond/resolve/close performed on ticket 1.

No dormancy notices withdrawn, no Arete centres/applications created, no engines/sweeps triggered, no settings/flags changed.

## Console errors
Not captured — `read_console_messages` was only wired up after most navigation had already happened, and I did not reload every page solely to backfill this. No visibly broken layout or JS-driven failure was observed on any page during manual review.

## Slowest page
No page exceeded ~1s to load/render by observation; the analytics page (6 aggregate queries) and the grievance report (12-month trailing aggregate) were the heaviest but both returned promptly. No formal timing instrumentation was used.
