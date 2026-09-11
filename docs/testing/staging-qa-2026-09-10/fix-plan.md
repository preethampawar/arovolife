# Fix plan for findings F01–F123 (staging QA 2026-09-10)

Branch: `fix/staging-qa-2026-09-10` (from `main` @ 6f114500). One atomic commit per finding (Conventional Commits, `Compliance-Review:` trailer on hard-rule areas, `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`). Never push. Never touch staging from an implementer.

Status legend: `[ ]` todo · `[~]` running · `[x]` fixed + committed · `[!]` blocked · `[?]` awaiting user decision · `[-]` not a code change.

## A. Clear-path code fixes (Wave 1, parallel, disjoint files)

### B1 — Engine-run observability (Opus)
Files: `Compensation/Listeners/RecordEngineRun.php`, `Compensation/Support/EngineRegistry.php`, `Compensation/Console/Commands/EngineHealthDigestCommand.php` + notification, `Compensation/Http/Controllers/Admin/AdminEngineRunsController.php`, `resources/views/admin/compensation/engine-runs/*`, `Compensation/Jobs/{Propagate,Reverse}GroupBvJob.php`, `config/logging.php`, grievance sla-sweep command.
- [x] F81 `RecordEngineRun::finished()` must persist the failure message in `engine_runs.error` (exception message, or the refusal reason). Test: a failing engine run leaves `error` non-null.
- [x] F43/F50/F39 Deliberate refusals (non-Tuesday weekly payout, payout-close gate refusal, monthly-close preflight failure) must not be recorded as `failed` with `error NULL`: record status `skipped` with the reason in `error` (or `summary.reason`), consistent with the existing flag-off=skipped rule; exceptions stay `failed` with the message. Digest must not report `skipped` rows as failures. Tests for both paths.
- [x] F30 (design half) Health digest gets an item when the premature-freeze self-heal *kept* a funded row (`premature_freeze_kept` log line today): write an `engine_runs`-independent signal (audit_log row `compensation.premature_freeze_kept`) and list it in the digest.
- [x] F83 Engine Runs page: render summary as labelled fields (rows processed / already processed / exit code), show "Already processed — nothing to do" when the run changed nothing; confirm modal names engine + period.
- [x] F84 Recompute-all / reset-purchase-data cards and routes: require `role:developer` in addition to `RecomputeGuard::isPermitted()`; plain `admin`/`admin-finance` never see them. Test: admin gets 404/403 and no HTML trace.
- [x] F85 Events page shows duration in ms below 10 s; `repurchase.snapshot` gets a registry label (retired) or is filtered from the page.
- [x] F121 Register `grievance:sla-sweep` in `EngineRegistry` (writes `engine_runs`, summary = tickets checked/escalated), or at minimum log a structured no-op line each run.
- [x] F12 `PropagateGroupBvJob` / `ReverseGroupBvJob`: `$tries = 1` (ADR-0011); add the deviation note to `docs/compliance/risk-register.md` only if you keep 3 (do not keep 3).
- [x] F123 Silence the `LOG_SLACK_WEBHOOK_URL is empty` warning (only register the slack channel when the URL is set).

### B2 — Monthly close & engine gates (Opus)
Files: `Compensation/Services/EngineStatusService.php`, `Compensation/Support/MonthlyEngineCompletionGate.php`, `Compensation/Support/OpenMonthGuard.php`, `Compensation/Console/Commands/{MonthlyPayoutCommand,GsbDailyCutoffCommand,MonthlyCloseCommand}.php`, `Compensation/Support/DerivedTables.php`, ADC credit writer, rank pools migration, `AdminGbbController` / `AdminFortuneBonusController` index.
- [x] F05 `hasSucceededRun()` (resume check + payout-close gate) must require `started_at` after the period end (same shape as `hasSucceededRunAfterDay`). Tests: an in-period run does not satisfy the gate.
- [x] F40 `MonthlyEngineCompletionGate::blockingFailure()` also treats a FAILED `compensation.monthly-close` run for the month (newer than the last success) as blocking.
- [x] F47 `payout:monthly-run`: add to `OpenMonthGuard::FREEZING_COMMANDS`, run `MonthlyEngineCompletionGate` before sweeping, and default `--month` to the PREVIOUS month. Refuse the current month without `--in-flight`. Tests for each gate.
- [x] F31 `gsb:daily-cutoff`: explicit closed-day guard on the CLI (`--date` must be < today unless `--in-flight`), independent of the repurchase gate; `--force` does not lift it.
- [x] F06 Backfill `rank_monthly_pools` for months that have credited `rank_bonus_results` but no pool row (new migration, idempotent), so `refuseUnfrozenPaidMonth()` does not throw on `--restart`.
- [x] F07 Add `purchase_offer_grants` to recompute `DerivedTables` (and its test).
- [x] F09 `adc_credit` wallet rows must carry `bonus_month` (the ADC month).
- [x] F08 Fortune enrolment must not be permanently closed by a premature `fortune_monthly_pools` row: enrolment reads participants at freeze time from the self-healed row; document the behaviour if you decide it is already covered by the self-heal.
- [x] F87 `/admin/compensation/gbb` and `/fortune-bonus` index: read pool rows (frozen/credited) so "engine has not yet run" only shows when neither pool nor result rows exist.

### B3 — Payout operations (Opus)
Files: `Compensation/Services/{PayoutService,PayoutReconciliationService,WalletService}.php`, `AdminWeeklyPayoutController`, `AdminMonthlyPayoutController`, payout views, NEFT export route, dashboard income snapshot, new migration for `utr_number` unique index.
- [x] F14 NEFT import validates the amount column against `net_amount_paise` per line; mismatch → line rejected with reason, batch import reports counts.
- [x] F15 Duplicate-UTR guard in import + unique index on `payout_line_items.utr_number` (nullable unique).
- [x] F93 Hold reasons re-evaluated at `approve()` (and at batch creation): lines whose hold cleared are released into the batch; the stored `hold_reason` and audit row reflect the re-evaluated state. Test: bank+KYC added after creation → line payable after approve.
- [x] F48 Monthly batch gets an earning window: stamp `earnings_through` = last day of the paid month and select only wallet rows with `bonus_month <= month` (or `earned_on <= earnings_through`). Test: next-month credits stay unswept.
- [x] F45 Weekly and monthly sweeps take a shared cache/DB lock around the ₹50L cap check + sweep.
- [x] F95 (clear half) NEFT export: `can:finance.record`, 403/redirect with a flash on an unapproved batch. Column content stays as-is pending decision D5.
- [x] F96 covered by F14/F15/F48 — verify only.
- [x] F97 "Wallet balance" column shows gross with the deduction in a separate column (or rename to "Payable before deductions"); confirm modal shows the real held/payable totals; monthly pages post to `monthly-payouts/*` routes.
- [x] F52/F89 `WalletService::creditTotalsByMonth()` excludes `repurchase_deduction` (and any non-income types); dashboard THIS MONTH / LIFETIME / chart use it; "Pending payouts" tile = cash-payable only (exclude repurchase wallet). Tests with a deduction row.

### B4a — Admin reports & analytics (Sonnet)
Files: `resources/views/admin/analytics/index.blade.php`, `Commerce/Support/Bv.php`, GBB/Fortune month views, GSB/MSB report views + CSV controllers, `Shared/Support/Csv.php`, `AdminGrievanceReportController`, BV ledger admin routes.
- [x] F119 Remove the `/ 100` at analytics lines 50 and 202 (pass paise to `@bv`); `Bv::format` uses `IndianNumber`. Test: 2,039,999 BV renders as `20,39,999`.
- [x] F88 BV never carries ₹: GBB/Fortune month pages "Company BV" via `@bv`; CSV headers "Day Total BV" (no "(Rs)").
- [x] F90 Every weaker/power/carry-forward figure in GSB reports (daily calc, I&O, carry-forwards) shows an explicit Left/Right label from the stored `power_side`.
- [x] F91 MSB reports note "No repurchase deduction applies to MSB"; rank-bonus month page uses one qualifier count; tiles keep paise (no truncation); carry-forwards gets a CSV export; "leg" → "group" in daily-cutoffs help text.
- [x] F122 `Csv::safe()` accepts `int|float|string|null`; grievance report export formats the float to 2 dp. Test: export with a fractional median returns 200.
- [x] F103 BV-ledger admin routes gated `can:audit.read`; headings "Personal BV" / "Lifetime personal BV".

### B4b — Admin KYC, content, messaging (Opus)
Files: `resources/views/admin/kyc/show.blade.php`, `AdminKycController`, messaging reports view/controller, `Content/Http/Controllers/Admin/*`, content form request, `resources/views/admin/help/*`, Trix asset loading, `ApproveKycSubmission` (F108 only if trivial).
- [x] F106 KYC previews go through the audited streaming route (`admin.kyc.document_viewed` per view), no presigned URL in the page; the stream route requires the admin session + `kyc.review`. Test: `<img src>` points at the app route; unauthenticated GET is 403.
- [x] F110 KYC queue "awaiting re-upload" tile counts `flagged_at IS NOT NULL` documents; Approve disabled (and server-side refused) while any document is flagged and unresolved.
- [x] F116 Message report shows ADN + link to admin distributor page for reporter and sender.
- [x] F117 `sort_order` validated (`nullable|integer`, default 0) → 422 not 500; vendor Trix JS+CSS via npm/Vite (no unpkg); Body field shows an error if the editor fails to boot.
- [x] F118 Publish/archive transitions audit as `content_page.published` / `content_page.archived` (announcements too).
- [x] F109 Remove the Franchise Programme help card (or restore the doc without the 3% claim); note the orphan `FranchiseFeature` flag row for staging cleanup.
- [x] F111 Approval notification via `['mail','database']`; `/admin/distributors` search matches phone; distributor detail gets a KYC-state row; audit log gets an actor filter; root not shown as its own sponsor; flash "has been emailed" only after a successful send (queue failure → "email could not be sent").

### B5a — Distributor UI logic (Opus)
Files: `Messaging/Http/Controllers/MessageController.php` + policy, `resources/views/income/genos-bv.blade.php`, income controllers, wallet page, `Genealogy/Http/Controllers/TreeController.php`, `_binary-node.blade.php`, distributor-requests controller, dashboard tooltips.
- [x] F25/F75 `MessageController::show()` → 404 unless the viewer may message the target (audience rule) or a thread already exists between them; policy test enumerating a stranger id.
- [x] F61/F62 `/income/genos-bv` shows the STORED weaker side / `power_side` per day (no recompute in the view); "Power CF after" labelled Left/Right; Genos Ledger row for the personal-BV top-up day; Mentorship table gets a Date column.
- [x] F63 Wallet ledger dated by `earned_on`/`bonus_month` (created_at shown as secondary), with bonus month and payout batch columns.
- [x] F67 Re-rooted `/tree/{adn}`: "YOU" ribbon only on the viewer's own node; banner says "Showing {name}'s placement".
- [!] F78 Distributor-request submit failure keeps input (`withInput()`). — not reproducible: both refusal routes already flash input and the form reads it back via `old()`; pinned with a regression test, see `fixes/B5a.md`.
- [x] F53 Payout-week / 8th-of-month cadence copy in dashboard + My Business tooltips renders only while the `compensation` content page is published (same gate as R-75).

### B5b — Distributor copy & small UI (Sonnet)
Files: `resources/views/income/wallet.blade.php`, `shop/pay-unavailable.blade.php`, notification bell partial, My Business view, enums/labels, membership card back, profile views, `ScannedForMalware.php`, flash partials.
- [x] F19 Wallet copy describes credit-time deduction (not "after your first payout").
- [x] F20 Pay-unavailable copy adds the 7-working-day refund timeline.
- [x] F54 Bell links to announcements when only announcements are unread; carry-forward vs carry-over hint text; unify hero-card gradient.
- [x] F64 Hold status label map ("No bank account on file" etc.) on page + CSV; `/income` title "My Income — Overview".
- [x] F68/F73 `/tree` banner drops "binary placement tree"; membership-card back uses contractor wording; PERSONAL BV tile no truncation; profile bank mask shows real last-4; password messages say "password" not `new_password`; single page title; DSA PAN mask 10 chars; reserved accounts (0-day cooling-off) do not print "30-day window"; bank field width.
- [x] F79 Flash rendered once (remove the duplicate in the view or layout); grievance-reply confirm modal gets specific copy.
- [x] F74 (copy half) Malware-scan refusal message names the document, not `documents.id_proof.0`.

### B6 — Commerce (Opus)
Files: cart view/`CartService`, checkout address validation, `shop/product.blade.php`, order detail views, returns form, admin order/payment views, footer, `ContentPageSeeder` (policy page only — coordinate: B7 owns publish-state), shop banner/category views.
- [x] F34 Cart thumbnail = product's primary gallery image (same source as PDP), never `image_url` hot-links.
- [x] F57 Enforce `commerce.shipping.india_mainland_only`: refuse Andaman & Nicobar (744xxx) and Lakshadweep (682551–682559) pincodes at address save + checkout with a clear message.
- [x] F58 "GST invoice" badge → "Invoice issued for every order" until R-28 ships.
- [x] F59 Distributor price via `IndianNumber`; hide empty Billing card; "1 item" singular; cooling-off counter inclusive (30 days remaining on day 0); database notification on order status change.
- [x] F100 Buyer's return form shows the buy-back deduction per reason (less GST where applicable; shipping refunded only on cooling-off) from the same matrix admin renders.
- [x] F101 Admin order detail links the invoice (`payments/orders/{order}/invoice`) and its payment intent.
- [x] F35 Add a public "Refunds, returns & shipping" policy page (draft, from T&C §4 + buyback matrix + 7-working-day refund) and a footer link; leave it unpublished for the client's review.
- [!] F37 `/join` states joining is free; policy pages get the print contact footer; banner "arovolife" (no "Shopping Mall"); shop category list = the 6 homepage categories; carousel arrows do not overlap hero text on mobile; Immunity Booster listing uses its own art. (Officer names stay a launch-gate item.)
- [x] F55 (client Q7) Signed-in Direct Sellers are charged the distributor price; guests, MRP and BV unchanged; cart repriced on sign-in.
- [x] F56 (client Q14) Verified: `payments.cod.enabled` exists nowhere in the code — staging DB row only, pinned by a test. Ops must delete the row.
- [x] F102 (client Q15) The return form states that a return covers the whole order; partial returns go to customer care.
- Notes: F59's "distributor price via IndianNumber" was already correct (`ProductVariant` aliases `IndianNumber as Number`). F37 is `[!]` because two of its sub-items are staging data, not code — the 7th shop category and the Immunity Booster pack art. Both, plus the F35 DSA §5.4 conflict, are written up in `fixes/B6.md`.

### B7 — Compliance seeding & guards (Opus)
Files: `database/seeders/{ContentPageSeeder,RolesAndPermissionsSeeder}.php`, `Shared/Rules/NoIncomeProjection.php`, messaging settings defaults, `routes/web.php` gates.
- [x] F17 `ContentPageSeeder` seeds `compensation` as draft (never published); `platform:reset` path too. Test asserts compensation is unpublished after seeding.
- [x] F18 Messaging `reporting_enabled` defaults OFF (until the privacy page is published); admin toggle stays.
- [x] F26 New `content.publish` permission (announcement publish/email, content-page publish/archive) → `admin`, `admin-operations`, `admin-compliance` (NOT admin-finance); recompute/reset endpoints → `role:developer` (coordinate with B1 F84: B1 owns the controller, B7 owns the seeder + route gate).
- [x] F113 `NoIncomeProjection` adds pattern matching on top of the phrase list: currency amount + period (`₹|rs|inr … per (day|week|month|year)`), `earn/make/income … (per|a|every) (day|week|month)`, `guaranteed|assured|fixed … (income|earning|return)`, percentages of return. Keep an allow-list for the historical-fact copy already seeded. Tests with the T35 sentence and 10 variants, plus 5 legitimate sentences that must pass.

### B8 — Repurchase (Opus)
Files: `2026_09_06_100003_backfill_verdicts…` migration, new migration, `RepurchaseEvaluateCommand` + service.
- [x] F21 Add the missing `due_date < today` guard to the backfill migration (prod has not run it); staging rows are cleaned by F10.
- [x] F22 New migration re-dates OPEN cycles from `start+29` to `start+30`.
- [x] F23 `repurchase:evaluate` isolates per-distributor exceptions (continue, collect), exits non-zero with a `failed_partial` summary listing the ADNs; `gsb:daily-cutoff` gate reads that summary and refuses only when the failure count is non-zero — document the choice.
- [x] F24 `repurchase:evaluate` writes a run summary (evaluated / fulfilled / failed / forfeited counts).

### B9 — Payout maker-checker & bank-upload export (Opus)
Files: `RolesAndPermissionsSeeder`, migration `2026_09_11_110000_add_created_by_to_payout_batches`, `PayoutBatch`, `PayoutService`, `HandlesPayoutBatchActions`, `routes/web.php`, both payout `show.blade.php` + `payout-settings.blade.php`, `resources/help/payout-operations.md`, `docs/compliance/risk-register.md` (R-81, R-82).
- [x] F94 Maker-checker on payouts (client Q3): new `finance.approve` permission held by `admin`/`developer` only — not `admin-finance` (which keeps `finance.record`: running, reconciling, retrying) and not `admin-compliance`; `payout_batches.created_by` stamped from `Auth::id()` or `EngineRunContext::actorId()` on every creation path and left NULL for the scheduler; the approve route refuses a batch whose maker is the approver, hides the button, explains why on the page, and writes `payout.batch.self_approval_refused`. Help doc + R-81. **Deploy: re-run `RolesAndPermissionsSeeder` or no batch can be approved.**
- [x] F44 The manual-NEFT export becomes a file the bank executes (client Q5): beneficiary name (from B10's `bank_beneficiary_name_enc`, falling back to `users.full_name`), full account number and IFSC decrypted with the PiiCrypter pattern `PayoutService` already uses, ungrouped net, and a `arovolife <ADN> B<batch id>` narration. Button reads "Download bank file (NEFT)"; the copy that said "upload it to the bank" is now true.
- [x] F95 Columns half: the nine-column bank-upload layout above; every download writes `payout.batch.bank_file_exported` with actor, batch, line count and a raw SHA-256 of the exact bytes in `after_hash` — no account number in any audit row, flash or log. A line whose ciphertext no longer opens exports blank account/IFSC with status `bank_decrypt_failed`. Gate half (approved-only, `can:finance.record`) already closed in B3. R-82 records the new DPDP disclosure surface.

### B10 — Self-service bank details (Opus)
Files: new `BankDetailsController` + `BankDetailsRequest` + `BankDetailsUpdatedNotification`, `profile/bank.blade.php`, migration `2026_09_11_120000_add_bank_beneficiary_name_to_distributors`, `routes/web.php`, `profile/show.blade.php`, `income/wallet.blade.php`, help docs.
- [x] F70 `GET/POST /profile/bank`: account number typed twice, IFSC, optional bank name, beneficiary name; OTP-gated exactly like the contact change; PiiCrypter ciphertext; `distributor.bank_details_updated` audit row with before/after digests; mail+database receipt. Wallet page banner links to it while the hold stands.
- [x] F28 `distributors.bank_beneficiary_name_enc` added (VARBINARY(512), PiiCrypter) for the B9 bank-upload export, plus plain `bank_name`.

### B11 — MSB deduction, company Arete Centre, recompute-gate banner (Opus)
Files: `MentorshipBonusService` + `mentorship_bonus_results` migration and the four MSB surfaces, `AreteDevelopmentCenterBonusService`, `AdminAreteCenterController`, `engine-runs/index.blade.php`, help + compensation docs.
- [x] F33 MSB (`mb_credit`) becomes the fifth repurchase-deduction source (client Q6): credits go through `WalletService::creditWithRepurchaseDeduction()`, the deduction and the credited amount are frozen on the result row, and every MSB surface (both admin reports + CSVs, the admin tab, the distributor page) shows Income · Repurchase deduction · Credited to wallet. The B4a "no repurchase deduction applies to MSB" note is inverted; help and the repurchase doc updated.
- [x] F120 The ADC engine skips `centre_type = 'company'` before it reads `assigned_distributor_id`, so a company centre never earns the bonus (client Q2); the admin form refuses an owner ADN on a company centre with a validation error. `adc_bonus_results` and `wallet_ledger_entries` are already in recompute `DerivedTables`, so the staging row rebuilds.
- [x] F82 Engine Runs shows a red banner while `RecomputeGuard::isPermitted()` is true — "engines can be run for the current, in-flight month. Do not run a period that has not ended" — to every reader of the page (client Q18: staging keeps the gate open). Gate closed = zero trace.

### B12 — consent records, helpline hours, malware-scan switch (Opus)
Files: `config/uploads.php`, `ScannedForMalware`, `.env.example`, risk register; `WithdrawConsent` + `ConsentWithdrawalController` + `consent/withdraw.blade.php`; new `BackfillAgreementsCommand` + `ConsentRecordController` + `consent/record.blade.php` + `ConsentServiceProvider` + `routes/web.php` + `profile/show.blade.php`; `config/arovolife.php` + five footers + two grievance views.
- [x] F74 `uploads.malware_scan` from `CLAMAV_ENABLED` (client Q20): default ON and only an explicit `false` skips, so an environment that never sets the switch keeps refusing. Scanning code untouched; each unscanned file logs `uploads.scan_skipped` with user id, field, size and SHA-256, never the file name. Risk accepted as R-83. Ops: set `CLAMAV_ENABLED=false` on staging and production at deploy.
- [x] F71 A missing consent row is live consent (client Q9), so the full DPDP §6 flow shows for every active account; only an explicit withdrawal — a `withdrawn_at` stamp or the `consent_withdrawn` closure — reaches the already-withdrawn screen. A zero-row withdrawal terminates and audits normally, fabricates no acceptance row, and stays idempotent.
- [x] F72 `consent:backfill-agreements` (registered in `ConsentServiceProvider`) derives one `agreements` row per accepted version — earliest acceptance's hash and timestamp, supersession chained in effective order — idempotent and re-runnable, reporting two texts recorded under one version string. New `GET /profile/consents` "My consents & agreements" shows the distributor their own rows (title, version as recorded, accepted at, IP, in force/withdrawn) with links to the published pages and the withdrawal route. Ops: run the backfill after deploy.
- [x] F77 Hours are 10:00–18:00 Mon–Sat (client Q11). Five footers said 9:30–17:30 daily except Sundays; the sentence now lives once in `arovolife.support_hours` and seven templates read it, with a suite-wide scan that fails on any hardcoded hours range. The seeder needed no change — `content/grievance.md` and `returns.md` were already right, so no republish.

### B14 — small follow-ups reported by other batches (Sonnet)
Files: `AdminPaymentController`, `AdminDistributorRequestController`, `AdminLineChangeController`, `AdminDistributorCreateController`, `admin/catalog/products/form.blade.php`, new `NotificationController` + `notifications/index.blade.php` + `_notification-bell.blade.php`, `ProductCatalogSeeder`.
- [x] F101 (follow-up) `generateInvoice()` redirects `back()` instead of always to the Payments index, so issuing from the order page returns there.
- [x] F111 (follow-up) `AdminDistributorRequestController` (approve/reject), `AdminLineChangeController` (reject) and `AdminDistributorCreateController` (store) no longer flash "has been emailed" for mail that is only queued — same wording fix B4b applied to `AdminKycController` (`79e604b2`).
- [x] F117 (follow-up) The catalog product form loads Trix from the bundled `@vite('resources/js/trix.js')` entry (B4b, `19913b74`), not unpkg; the S3-upload attachment listener still binds.
- [x] F59/F54 (follow-up) The bell also counts unread database notifications (order status changes, B6 `1c5aa8d6`) and routes to a new minimal `/notifications` list when they are the only thing unread.
- [x] F34 (follow-up) `ProductCatalogSeeder`'s three `picsum.photos` random-image `image_url`s are null (no local placeholder asset exists to point at); the storefront's existing null-safe fallback (`Product::primaryImageUrl()`) covers it. Two now-invalid `phpstan-baseline.neon` entries removed.

### B13 — audit before/after digests on every admin, KYC and settings mutation (Opus)
Files: new `Compliance/Support/AuditDigests.php`; ~105 `AuditLog::create` sites across `Admin`, `Catalog`, `Commerce`, `Compensation`, `Compliance`, `Content`, `Grievance`, `Messaging`, `Identity`, `Kyc`, `Genealogy`, `Returns`; `tests/Modules/Compliance/AuditDigestsTest.php`, `tests/Feature/Compliance/AuditRowsCarryDigestsTest.php`; `docs/architecture/data-model.md`.
- [x] F108 (client Q16, one sweep) Every admin, KYC and settings audit row now carries `before_hash` / `after_hash` through one helper, `AuditDigests::of()` — raw 32 bytes, canonical JSON, `updated_at` dropped, identity numbers digested masked and credentials collapsed to `set`. Creates store a NULL before, deletes a NULL after, and an action that moves nothing (a document view, a refused approval) carries the same digest both sides with a comment saying why. `row_hash` needed no work — `AuditLog::booted()` already chains every row. A source fence test refuses any new `AuditLog::create` on those paths that omits `before_hash`. Scheduler-authored "nothing happened" rows (monthly-close refusal/abort/in-flight, dispatch jobs, purge commands) are deliberately out of scope. Detail: `fixes/B13.md`.

## B. Client decisions (received 2026-09-11 ~12:45 IST) → Wave 3 batches

| Q | Finding | Decision | Batch |
|---|---|---|---|
| 1 | F10 | YES — windowed recompute on staging from 2026-09-01, after deploy of this branch; 5-point warning first | ops |
| 2 | F120 | NO — engine excludes `centre_type='company'`; admin cannot assign a distributor to a company centre; the ₹10,500 stays with the company (staging row rebuilt by F10) | B11 |
| 3 | F94 | YES — `finance.approve` (admin only), `payout_batches.created_by`, creator cannot approve own batch | B9 |
| 4 | F70 | YES — self-service Bank details page (account ×2, IFSC, beneficiary name, OTP, audit) | B10 |
| 5 | F44/F95 | YES — NEFT export becomes a bank-upload file (account no, IFSC, beneficiary name), approved batches only, finance only, audited per download | B9 |
| 6 | F33 | YES — MSB becomes the 5th repurchase-deduction source | B11 |
| 7 | F55 | YES — members pay the distributor price | B6 |
| 8 | F115 | NO — several live announcements; pin limit 3 stays. Closed by design | — |
| 9 | F71/F72 | YES both — missing consent row = live consent for the withdrawal flow; backfill `agreements`; "My consents & agreements" page | B12 |
| 10 | F107 | CLIENT (Q10 + Q22, 2026-09-11): keep ALL KYC images including Aadhaar, encrypted, with audited access and a retention period set in Settings. Done: `KycDocumentVault` (PiiCrypter ciphertext on every upload path; `kyc:encrypt-documents` converts existing objects), approval no longer deletes scans, setting `kyc.document_retention_days` (default 2,920 days), nightly `kyc:purge-expired-documents` with audit rows, R-31 + CLAUDE.md rule 8 amended, help guide updated. Deviation from hard rule 8 recorded for RA-01 sign-off | [x] |
| 11 | F77 | YES — 10:00–18:00 Mon–Sat; footer + Grievance Redressal Policy to match | B12 |
| 12 | F36 | keep guest browsing. Closed by design | — |
| 13 | F65 | keep ADN list; strip email/phone from `/tree/suggest` | B5a |
| 14 | F56 | COD stays off — remove the orphan setting | B6 |
| 15 | F102 | NO — whole-order returns; say so on the form | B6 |
| 16 | F108 | YES — before/after digests on every admin/KYC/settings mutation | B13 (last) |
| 17 | F114 | YES — seeder run on staging 2026-09-11 12:50 IST: permissions 12→13, `messaging.moderate` on admin/admin-operations/admin-compliance/developer. Re-run after deploy for `content.publish`/`finance.approve` | done |
| 18 | F82 | YES — gate stays open on staging; red banner on Engine Runs while open | B11 |
| 19 | F11 | Cloudways caps the Supervisor `--timeout` at 999 s (user, 2026-09-11) — panel change impossible. No action needed: a job's own `$timeout` overrides the worker flag (`RecomputeAllJob` 7200, `RunEngineChainJob` 3600); `PropagateGroupBvJob` / `ReverseGroupBvJob` are per-order and finish in seconds. Recompute runs from the CLI, not the queue. ADR-0011's 7200 stays as the job-level figure | [x] |
| 20 | F74 | CLIENT: disable ClamAV on staging and prod, never block uploads → `CLAMAV_ENABLED=false` switch, fail-open only when explicitly disabled, warning log per unscanned upload, risk-register entry. `.env` change on staging at deploy time | B12 + ops |
| 21 | F04 | YES — digest arrived. Closed | — |

### Wave 3 batches
- B9 (Opus, after B3): F94, F44/F95 (+F28 beneficiary name column shared with B10)
- B10 (Opus): F70 + F28
- B11 (Opus): F33, F120, F82 banner
- B12 (Opus): F71, F72, F77, F74 switch
- B13 (Opus, last): F108
- B4b (Opus): F106, F110, F116, F117, F118, F109, F111 (F107 excluded — pending)
- B8 (Opus, after B2): F21, F22, F23, F24

## C. Not code — client / ops / data
- [-] F01 real BV values (client)   · [-] F03 ElasticEmail daily limit (ops)   · [-] F04 digest inbox (user)
- [-] F13/F27/F29/F38/F41/F42/F46/F49/F51/F60/F66/F69/F76/F80/F85-info/F92/F99/F104/F105/F112/F123 — info rows; data items fold into F10; F112 Pennant row + F109 flag row = staging cleanup list
- [-] F02, F98 closed
