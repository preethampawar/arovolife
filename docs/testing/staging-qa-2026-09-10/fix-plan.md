# Fix plan for findings F01–F123 (staging QA 2026-09-10)

Branch: `fix/staging-qa-2026-09-10` (from `main` @ 6f114500). One atomic commit per finding (Conventional Commits, `Compliance-Review:` trailer on hard-rule areas, `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`). Never push. Never touch staging from an implementer.

Status legend: `[ ]` todo · `[~]` running · `[x]` fixed + committed · `[!]` blocked · `[?]` awaiting user decision · `[-]` not a code change.

## A. Clear-path code fixes (Wave 1, parallel, disjoint files)

### B1 — Engine-run observability (Opus)
Files: `Compensation/Listeners/RecordEngineRun.php`, `Compensation/Support/EngineRegistry.php`, `Compensation/Console/Commands/EngineHealthDigestCommand.php` + notification, `Compensation/Http/Controllers/Admin/AdminEngineRunsController.php`, `resources/views/admin/compensation/engine-runs/*`, `Compensation/Jobs/{Propagate,Reverse}GroupBvJob.php`, `config/logging.php`, grievance sla-sweep command.
- [ ] F81 `RecordEngineRun::finished()` must persist the failure message in `engine_runs.error` (exception message, or the refusal reason). Test: a failing engine run leaves `error` non-null.
- [ ] F43/F50/F39 Deliberate refusals (non-Tuesday weekly payout, payout-close gate refusal, monthly-close preflight failure) must not be recorded as `failed` with `error NULL`: record status `skipped` with the reason in `error` (or `summary.reason`), consistent with the existing flag-off=skipped rule; exceptions stay `failed` with the message. Digest must not report `skipped` rows as failures. Tests for both paths.
- [ ] F30 (design half) Health digest gets an item when the premature-freeze self-heal *kept* a funded row (`premature_freeze_kept` log line today): write an `engine_runs`-independent signal (audit_log row `compensation.premature_freeze_kept`) and list it in the digest.
- [ ] F83 Engine Runs page: render summary as labelled fields (rows processed / already processed / exit code), show "Already processed — nothing to do" when the run changed nothing; confirm modal names engine + period.
- [ ] F84 Recompute-all / reset-purchase-data cards and routes: require `role:developer` in addition to `RecomputeGuard::isPermitted()`; plain `admin`/`admin-finance` never see them. Test: admin gets 404/403 and no HTML trace.
- [ ] F85 Events page shows duration in ms below 10 s; `repurchase.snapshot` gets a registry label (retired) or is filtered from the page.
- [ ] F121 Register `grievance:sla-sweep` in `EngineRegistry` (writes `engine_runs`, summary = tickets checked/escalated), or at minimum log a structured no-op line each run.
- [ ] F12 `PropagateGroupBvJob` / `ReverseGroupBvJob`: `$tries = 1` (ADR-0011); add the deviation note to `docs/compliance/risk-register.md` only if you keep 3 (do not keep 3).
- [ ] F123 Silence the `LOG_SLACK_WEBHOOK_URL is empty` warning (only register the slack channel when the URL is set).

### B2 — Monthly close & engine gates (Opus)
Files: `Compensation/Services/EngineStatusService.php`, `Compensation/Support/MonthlyEngineCompletionGate.php`, `Compensation/Support/OpenMonthGuard.php`, `Compensation/Console/Commands/{MonthlyPayoutCommand,GsbDailyCutoffCommand,MonthlyCloseCommand}.php`, `Compensation/Support/DerivedTables.php`, ADC credit writer, rank pools migration, `AdminGbbController` / `AdminFortuneBonusController` index.
- [ ] F05 `hasSucceededRun()` (resume check + payout-close gate) must require `started_at` after the period end (same shape as `hasSucceededRunAfterDay`). Tests: an in-period run does not satisfy the gate.
- [ ] F40 `MonthlyEngineCompletionGate::blockingFailure()` also treats a FAILED `compensation.monthly-close` run for the month (newer than the last success) as blocking.
- [ ] F47 `payout:monthly-run`: add to `OpenMonthGuard::FREEZING_COMMANDS`, run `MonthlyEngineCompletionGate` before sweeping, and default `--month` to the PREVIOUS month. Refuse the current month without `--in-flight`. Tests for each gate.
- [ ] F31 `gsb:daily-cutoff`: explicit closed-day guard on the CLI (`--date` must be < today unless `--in-flight`), independent of the repurchase gate; `--force` does not lift it.
- [ ] F06 Backfill `rank_monthly_pools` for months that have credited `rank_bonus_results` but no pool row (new migration, idempotent), so `refuseUnfrozenPaidMonth()` does not throw on `--restart`.
- [ ] F07 Add `purchase_offer_grants` to recompute `DerivedTables` (and its test).
- [ ] F09 `adc_credit` wallet rows must carry `bonus_month` (the ADC month).
- [ ] F08 Fortune enrolment must not be permanently closed by a premature `fortune_monthly_pools` row: enrolment reads participants at freeze time from the self-healed row; document the behaviour if you decide it is already covered by the self-heal.
- [ ] F87 `/admin/compensation/gbb` and `/fortune-bonus` index: read pool rows (frozen/credited) so "engine has not yet run" only shows when neither pool nor result rows exist.

### B3 — Payout operations (Opus)
Files: `Compensation/Services/{PayoutService,PayoutReconciliationService,WalletService}.php`, `AdminWeeklyPayoutController`, `AdminMonthlyPayoutController`, payout views, NEFT export route, dashboard income snapshot, new migration for `utr_number` unique index.
- [ ] F14 NEFT import validates the amount column against `net_amount_paise` per line; mismatch → line rejected with reason, batch import reports counts.
- [ ] F15 Duplicate-UTR guard in import + unique index on `payout_line_items.utr_number` (nullable unique).
- [ ] F93 Hold reasons re-evaluated at `approve()` (and at batch creation): lines whose hold cleared are released into the batch; the stored `hold_reason` and audit row reflect the re-evaluated state. Test: bank+KYC added after creation → line payable after approve.
- [ ] F48 Monthly batch gets an earning window: stamp `earnings_through` = last day of the paid month and select only wallet rows with `bonus_month <= month` (or `earned_on <= earnings_through`). Test: next-month credits stay unswept.
- [ ] F45 Weekly and monthly sweeps take a shared cache/DB lock around the ₹50L cap check + sweep.
- [ ] F95 (clear half) NEFT export: `can:finance.record`, 403/redirect with a flash on an unapproved batch. Column content stays as-is pending decision D5.
- [ ] F96 covered by F14/F15/F48 — verify only.
- [ ] F97 "Wallet balance" column shows gross with the deduction in a separate column (or rename to "Payable before deductions"); confirm modal shows the real held/payable totals; monthly pages post to `monthly-payouts/*` routes.
- [ ] F52/F89 `WalletService::creditTotalsByMonth()` excludes `repurchase_deduction` (and any non-income types); dashboard THIS MONTH / LIFETIME / chart use it; "Pending payouts" tile = cash-payable only (exclude repurchase wallet). Tests with a deduction row.

### B4a — Admin reports & analytics (Sonnet)
Files: `resources/views/admin/analytics/index.blade.php`, `Commerce/Support/Bv.php`, GBB/Fortune month views, GSB/MSB report views + CSV controllers, `Shared/Support/Csv.php`, `AdminGrievanceReportController`, BV ledger admin routes.
- [ ] F119 Remove the `/ 100` at analytics lines 50 and 202 (pass paise to `@bv`); `Bv::format` uses `IndianNumber`. Test: 2,039,999 BV renders as `20,39,999`.
- [ ] F88 BV never carries ₹: GBB/Fortune month pages "Company BV" via `@bv`; CSV headers "Day Total BV" (no "(Rs)").
- [ ] F90 Every weaker/power/carry-forward figure in GSB reports (daily calc, I&O, carry-forwards) shows an explicit Left/Right label from the stored `power_side`.
- [ ] F91 MSB reports note "No repurchase deduction applies to MSB"; rank-bonus month page uses one qualifier count; tiles keep paise (no truncation); carry-forwards gets a CSV export; "leg" → "group" in daily-cutoffs help text.
- [ ] F122 `Csv::safe()` accepts `int|float|string|null`; grievance report export formats the float to 2 dp. Test: export with a fractional median returns 200.
- [ ] F103 BV-ledger admin routes gated `can:audit.read`; headings "Personal BV" / "Lifetime personal BV".

### B4b — Admin KYC, content, messaging (Opus)
Files: `resources/views/admin/kyc/show.blade.php`, `AdminKycController`, messaging reports view/controller, `Content/Http/Controllers/Admin/*`, content form request, `resources/views/admin/help/*`, Trix asset loading, `ApproveKycSubmission` (F108 only if trivial).
- [ ] F106 KYC previews go through the audited streaming route (`admin.kyc.document_viewed` per view), no presigned URL in the page; the stream route requires the admin session + `kyc.review`. Test: `<img src>` points at the app route; unauthenticated GET is 403.
- [ ] F110 KYC queue "awaiting re-upload" tile counts `flagged_at IS NOT NULL` documents; Approve disabled (and server-side refused) while any document is flagged and unresolved.
- [ ] F116 Message report shows ADN + link to admin distributor page for reporter and sender.
- [ ] F117 `sort_order` validated (`nullable|integer`, default 0) → 422 not 500; vendor Trix JS+CSS via npm/Vite (no unpkg); Body field shows an error if the editor fails to boot.
- [ ] F118 Publish/archive transitions audit as `content_page.published` / `content_page.archived` (announcements too).
- [ ] F109 Remove the Franchise Programme help card (or restore the doc without the 3% claim); note the orphan `FranchiseFeature` flag row for staging cleanup.
- [ ] F111 Approval notification via `['mail','database']`; `/admin/distributors` search matches phone; distributor detail gets a KYC-state row; audit log gets an actor filter; root not shown as its own sponsor; flash "has been emailed" only after a successful send (queue failure → "email could not be sent").

### B5a — Distributor UI logic (Opus)
Files: `Messaging/Http/Controllers/MessageController.php` + policy, `resources/views/income/genos-bv.blade.php`, income controllers, wallet page, `Genealogy/Http/Controllers/TreeController.php`, `_binary-node.blade.php`, distributor-requests controller, dashboard tooltips.
- [ ] F25/F75 `MessageController::show()` → 404 unless the viewer may message the target (audience rule) or a thread already exists between them; policy test enumerating a stranger id.
- [ ] F61/F62 `/income/genos-bv` shows the STORED weaker side / `power_side` per day (no recompute in the view); "Power CF after" labelled Left/Right; Genos Ledger row for the personal-BV top-up day; Mentorship table gets a Date column.
- [ ] F63 Wallet ledger dated by `earned_on`/`bonus_month` (created_at shown as secondary), with bonus month and payout batch columns.
- [ ] F67 Re-rooted `/tree/{adn}`: "YOU" ribbon only on the viewer's own node; banner says "Showing {name}'s placement".
- [ ] F78 Distributor-request submit failure keeps input (`withInput()`).
- [ ] F53 Payout-week / 8th-of-month cadence copy in dashboard + My Business tooltips renders only while the `compensation` content page is published (same gate as R-75).

### B5b — Distributor copy & small UI (Sonnet)
Files: `resources/views/income/wallet.blade.php`, `shop/pay-unavailable.blade.php`, notification bell partial, My Business view, enums/labels, membership card back, profile views, `ScannedForMalware.php`, flash partials.
- [ ] F19 Wallet copy describes credit-time deduction (not "after your first payout").
- [ ] F20 Pay-unavailable copy adds the 7-working-day refund timeline.
- [ ] F54 Bell links to announcements when only announcements are unread; carry-forward vs carry-over hint text; unify hero-card gradient.
- [ ] F64 Hold status label map ("No bank account on file" etc.) on page + CSV; `/income` title "My Income — Overview".
- [ ] F68/F73 `/tree` banner drops "binary placement tree"; membership-card back uses contractor wording; PERSONAL BV tile no truncation; profile bank mask shows real last-4; password messages say "password" not `new_password`; single page title; DSA PAN mask 10 chars; reserved accounts (0-day cooling-off) do not print "30-day window"; bank field width.
- [ ] F79 Flash rendered once (remove the duplicate in the view or layout); grievance-reply confirm modal gets specific copy.
- [ ] F74 (copy half) Malware-scan refusal message names the document, not `documents.id_proof.0`.

### B6 — Commerce (Opus)
Files: cart view/`CartService`, checkout address validation, `shop/product.blade.php`, order detail views, returns form, admin order/payment views, footer, `ContentPageSeeder` (policy page only — coordinate: B7 owns publish-state), shop banner/category views.
- [ ] F34 Cart thumbnail = product's primary gallery image (same source as PDP), never `image_url` hot-links.
- [ ] F57 Enforce `commerce.shipping.india_mainland_only`: refuse Andaman & Nicobar (744xxx) and Lakshadweep (682551–682559) pincodes at address save + checkout with a clear message.
- [ ] F58 "GST invoice" badge → "Invoice issued for every order" until R-28 ships.
- [ ] F59 Distributor price via `IndianNumber`; hide empty Billing card; "1 item" singular; cooling-off counter inclusive (30 days remaining on day 0); database notification on order status change.
- [ ] F100 Buyer's return form shows the buy-back deduction per reason (less GST where applicable; shipping refunded only on cooling-off) from the same matrix admin renders.
- [ ] F101 Admin order detail links the invoice (`payments/orders/{order}/invoice`) and its payment intent.
- [ ] F35 Add a public "Refunds, returns & shipping" policy page (draft, from T&C §4 + buyback matrix + 7-working-day refund) and a footer link; leave it unpublished for the client's review.
- [ ] F37 `/join` states joining is free; policy pages get the print contact footer; banner "arovolife" (no "Shopping Mall"); shop category list = the 6 homepage categories; carousel arrows do not overlap hero text on mobile; Immunity Booster listing uses its own art. (Officer names stay a launch-gate item.)

### B7 — Compliance seeding & guards (Opus)
Files: `database/seeders/{ContentPageSeeder,RolesAndPermissionsSeeder}.php`, `Shared/Rules/NoIncomeProjection.php`, messaging settings defaults, `routes/web.php` gates.
- [ ] F17 `ContentPageSeeder` seeds `compensation` as draft (never published); `platform:reset` path too. Test asserts compensation is unpublished after seeding.
- [ ] F18 Messaging `reporting_enabled` defaults OFF (until the privacy page is published); admin toggle stays.
- [ ] F26 New `content.publish` permission (announcement publish/email, content-page publish/archive) → `admin`, `admin-operations`, `admin-compliance` (NOT admin-finance); recompute/reset endpoints → `role:developer` (coordinate with B1 F84: B1 owns the controller, B7 owns the seeder + route gate).
- [ ] F113 `NoIncomeProjection` adds pattern matching on top of the phrase list: currency amount + period (`₹|rs|inr … per (day|week|month|year)`), `earn/make/income … (per|a|every) (day|week|month)`, `guaranteed|assured|fixed … (income|earning|return)`, percentages of return. Keep an allow-list for the historical-fact copy already seeded. Tests with the T35 sentence and 10 variants, plus 5 legitimate sentences that must pass.

### B8 — Repurchase (Opus)
Files: `2026_09_06_100003_backfill_verdicts…` migration, new migration, `RepurchaseEvaluateCommand` + service.
- [ ] F21 Add the missing `due_date < today` guard to the backfill migration (prod has not run it); staging rows are cleaned by F10.
- [ ] F22 New migration re-dates OPEN cycles from `start+29` to `start+30`.
- [ ] F23 `repurchase:evaluate` isolates per-distributor exceptions (continue, collect), exits non-zero with a `failed_partial` summary listing the ADNs; `gsb:daily-cutoff` gate reads that summary and refuses only when the failure count is non-zero — document the choice.
- [ ] F24 `repurchase:evaluate` writes a run summary (evaluated / fulfilled / failed / forfeited counts).

## B. Awaiting user decision (Wave 2) — see the yes/no list sent 2026-09-11
- [?] F10 windowed recompute on staging (destructive; 5-point warning before running)
- [?] F120 company centre assignment / engine exclusion + reversal of ₹10,500
- [?] F94 maker-checker (`finance.approve`, `created_by`, self-approval blocked)
- [?] F70 self-service bank-details page (+ F28 confirmation & beneficiary name)
- [?] F44/F95 NEFT export columns
- [?] F33 MSB as repurchase-deduction source
- [?] F55 which price members pay
- [?] F115 announcement publish rule
- [?] F71/F72 consent withdrawal copy + agreements registry
- [?] F107 purge scope on KYC approval
- [?] F82 staging recompute gate
- [?] F114 re-seed permissions on staging
- [?] F77 helpline hours
- [?] F36 guest browsing
- [?] F65/F69 downline ADNs + suggest endpoint under R-65
- [?] F56 COD
- [?] F102 per-line returns
- [?] F108 audit before/after hashes scope
- [?] F11 supervisor timeout on staging
- [?] F74 ClamAV on staging

## C. Not code — client / ops / data
- [-] F01 real BV values (client)   · [-] F03 ElasticEmail daily limit (ops)   · [-] F04 digest inbox (user)
- [-] F13/F27/F29/F38/F41/F42/F46/F49/F51/F60/F66/F69/F76/F80/F85-info/F92/F99/F104/F105/F112/F123 — info rows; data items fold into F10; F112 Pennant row + F109 flag row = staging cleanup list
- [-] F02, F98 closed
