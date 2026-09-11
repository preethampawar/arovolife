# T34 — Distributors, KYC review, distributor requests, tree, audit log, feature flags, settings, help docs
Verdict: PASS-with-notes

Session mode: **reused the existing staff session** — signed in as `preetham.pawar@gmail.com` (users.id 242, role `developer`, display name "VJ"), the hidden developer super-admin. I did NOT sign in and did NOT sign out. Because the live session is `developer`, every "what does a plain `admin` see" question was answered from code/policy (routes + controller ownership tables), not by logging in as admin@arovolife.test — as the brief's SESSION CHANGE block requires.

Worked in own tab (1049838095); the user's tab (1049838082) was left untouched.

## Checks
| # | Check | Result | Evidence |
|---|---|---|---|
| 1 | `/admin/distributors` list + status tabs | PASS | Active (33) / Pending (284) / Blocked / Terminated. Foots to DB: `users.status` active 35 (33 distributors + 2 staff), pending 284. 277 ms |
| 2 | Search by ADN | PASS | `?q=444555666` → exactly 1 row |
| 3 | Search by name / email | PASS | controller searches `distributors.adn`, `users.email`, `users.full_name` (LIKE) |
| 4 | Search by phone | N/A — not implemented | `?q=9533933130` → "No distributors found." Placeholder honestly reads "Search ADN, email, name…", so no mis-promise. Note F34-7 |
| 5 | Export CSV — PII masked | PASS | 318 rows, header `ADN,Full Name,Email,Phone,State,PAN Last4,Aadhaar Last4,Bank IFSC,Depth,Side,Effective Date,Cooling Off End,Status,DOB,Sponsor ADN,KYC Verified,Couple Role,Spouse ADN`. 0 PAN-shaped hits; no Aadhaar 12-digit (the 317 `\d{12}` hits are `+91…` phones); bank **account number absent**, IFSC only. Export writes `admin.register.exported` audit row |
| 6 | Cooling-off dates in CSV | PASS | 286 rows effective+30d; the 31 rows where end == effective are exactly the 31 reserved company accounts (by design) |
| 7 | `/admin/distributors/1` (ADN 444555666) masking | PASS | `PAN (last 4) XXXXXX5710`, `Aadhaar (last 4) XXXXXXXX0000`, Bank IFSC only, no account number. 273 ms |
| 8 | Detail page: status / placement / sponsor / downline | PASS | Active; Depth Level 0; Side Root; Chosen by referral_explicit; Downline total 316; Left 973708897 / Right 177536419 — matches the seeded root |
| 9 | Detail page: KYC state + orders/BV summary | PARTIAL | No KYC-state row and no orders/BV summary on the page — only links out to *BV Ledger* and *Compensation →*. Note F34-8 |
| 10 | Freeze / terminate: confirm + reason | PASS | both are hidden inline forms with `reason:textarea` **required** and `data-confirm` ("Block this account?" / "Terminate this account permanently?"); gate `can:compliance.discipline` |
| 11 | Deactivate / activate | PASS (no reason field) | `data-confirm="Deactivate this distributor record…"`, no reason field — reversible record-level status flip; gate `can:compliance.discipline` |
| 12 | Password reset / set password / identity / id-photo | PASS | all under `can:distributor.credentials` (admin + developer only). On `/edit` every form carries `data-confirm`; set-password additionally lives in a modal on the show page |
| 13 | `nominee/unmask` gate | PASS (by code) | `can:admin.kyc.unmask`; no nominee block rendered for ADN 444555666 (no nominee on file) so the button was not exercised |
| 14 | `/admin/distributors/1/edit` read-only inspection | PASS | fields: full_name, phone_e164*, email*, date_of_birth, state*, bank_account, bank_ifsc; identity form: pan_number, aadhaar_number. **Nothing saved.** |
| 15 | Impersonation banner | PASS | "Admin impersonation — you're viewing as Arovolife Private Limited." + flash "Now impersonating…" |
| 16 | Impersonation audited | PASS | audit_log 3231 `admin.impersonate.start` and 3232 `admin.impersonate.stop`, actor_id 242, subject `user` 2 |
| 17 | Admin surfaces blocked while impersonating | PASS | GET `/admin` → **403 Access denied** while impersonating |
| 18 | Money pages scoped to the impersonated distributor | PASS | `/income` renders under the distributor layout with the impersonation banner; `/wallet` 404 (no such route) |
| 19 | "Stop impersonating" returns to admin | PASS | POST `/admin/impersonate/stop` → back on `/admin` as the developer |
| 20 | `/admin/kyc` queue foots to DB | PASS | "Pending review 284" == `users.status='pending'` 284; "Rejected — awaiting re-upload 0". 239 ms |
| 21 | Document viewer streams the file | PASS (with a gap) | GET `/admin/kyc/33/documents/6` → 302 to a 15-minute presigned S3 URL and wrote audit_log 3233 `admin.kyc.document_viewed`. **But** the inline previews on the review page do NOT use that route — see F34-1 |
| 22 | Approve / reject / terminate / flag controls | PASS | every one has `data-confirm`; reject, terminate and flag all require `reason:textarea`; approve correctly has no reason. Whole group gated `can:kyc.review` |
| 23 | **Approve one pending applicant** (mutation) | PASS | distributor 33 / ADN 678721891 / users.id 34 (`gvmss8@gmail.com`, a seeded test account — not reserved, not a QA login). `users.status` pending → **active**; queue 284 → 283; row disappeared from the queue |
| 24 | Approval purges PII per R-31 | PASS (partial — see F34-2) | `distributors.pan_encrypted` and `aadhaar_encrypted` both nulled; `kyc_documents` rows for `pan` (6) and `aadhaar` (7) deleted with their S3 objects |
| 25 | Approval audit row | PARTIAL | audit_log **3234** `admin.kyc.approved`, actor 242, subject distributor 33, details list `purged_files` / `purged_doc_ids`. **`before_hash` and `after_hash` are NULL** — see F34-3 |
| 26 | Approval notification | PARTIAL | `SendKycApprovedMail` (queued listener) fired; no failed job. But `KycApprovedNotification::via()` is `['mail']` only — no in-app notification row. See F34-6 |
| 27 | **Flag one document** (mutation) | PASS | distributor 34 / ADN 419167932, `kyc_documents.id 16` (address_proof_front): `flagged_at 2026-09-10 23:26:38`, `flagged_reason "QA test 2026-09-10 — please ignore"`, `flagged_by 242`. Flash: "Document flagged. The applicant has been emailed a re-upload link." audit_log **3235** `admin.kyc.document_flagged` |
| 28 | Re-upload notification | PASS (mail leg failed) | `notifications` row `KycDocumentFlaggedNotification` for notifiable_id 35 created; via = `['mail','database']`. The mail leg threw at 23:26:40 — staging SMTP. See F34-9 |
| 29 | `/kyc/reupload/{document}` would now authorise the applicant | PASS (by code) | `authorizeAccess()`: requires `Auth::user()->distributor->id === $document->distributor_id` **and** `$document->isFlagged()`; route also carries `auth` + `signed`. Doc 16 belongs to distributor 34 and is now flagged → authorised. Did not sign in as them |
| 30 | `/admin/distributor-requests` queue | PASS (empty) | 200, `can:distributor.request.handle`; "0 open · 0 approved · 0 rejected", "No requests match these filters." Foots to `distributor_requests` = **0 rows** — T25's uploads failed (F74) so nothing was created. Status + type + search filters render. No row existed to open, so the approve/reject controls could not be read |
| 31 | `/admin/tree` — whole company | PASS | root 444555666, "316 distributors below this node · actual depth 12", 316 member cards. 926 ms / 3.6 MB (see F34-14) |
| 32 | `/admin/tree/{id}` scoping | PASS | `/admin/tree/5` → "Tree — 608628172", 70 cards only |
| 33 | Downline stats follow `genealogy.downline_stats_visible` | PASS | no `settings` row for the key → default `false` (OFF). Every card on both trees reads `Highest Rank —  Current Rank —  Personal BV —` (70/70 on the subtree) |
| 34 | No money on tree cards | PASS | zero `₹` characters in the whole `/admin/tree/5` document |
| 35 | `/admin/tree/search` by ADN | PASS | `?q=444555666` → `{found:true, adn:444555666, id:1, depth:0}`; `?q=678721891` → `{found:true, id:33, depth:6}` — the applicant I approved is placed at depth 6 |
| 36 | Tree search miss handling | PASS | `?q=ZZQQ777777` → `{found:false}` (a bogus-looking `000000000` DOES match — it is a genuine substring of `+918000000000`, not a fallback) |
| 37 | `/admin/tree/suggest` | PASS | `?q=6787` → 1 result with adn/name/email/phone. Also matches a bare 10-digit phone |
| 38 | `/admin/audit-log` shows today's QA rows | PASS | date filter `from=to=2026-09-10` → "Showing 1 to 50 of **64** results"; DB: 64 rows today, ids **3172–3235**, so 3199–3228 are all in range |
| 39 | Audit rows show actor / action / subject / payload | PASS | e.g. "Approved KYC for SRIRAM (678721891) by preetham.pawar@gmail.com · Event key: admin.kyc.approved · Subject: distributor#33 · Payload {…}" |
| 40 | Audit filters | PASS (one gap) | `action` (free text + a counted picker), `subject_type` (distributor/user/settings/system), `from`/`to` dates all work — `action=admin.kyc` → 7 rows, `subject_type=settings` → 6 rows, `from/to=2020-01-01..02` → empty state. **No actor filter** — see F34-10 |
| 41 | Digests never rendered as raw hex | PASS | zero `[0-9a-f]{32,}` runs in the rendered page |
| 42 | No PII in the visible audit rows | PASS | zero `[A-Z]{5}[0-9]{4}[A-Z]` hits. The `\d{12}` hits are `+91…` phone numbers in payloads (e.g. 918000000001), not Aadhaar |
| 43 | `/admin/feature-flags` — registry size and state | PASS | 17 flags rendered to the developer; every one shows the **Deactivate** action, i.e. currently ON. Storage is the Pennant **`features`** table (there is no `feature_flags` table): all 16 registry classes carry a `scope = __laravel_null` row with `value = true` — the UI matches the stored global value exactly. **No flag was toggled.** |
| 44 | Flag ownership / zero-trace for a plain admin | PASS (by code) | `AdminFeatureFlagController::canUse()` = `owner === 'incident' || hasRole('developer')`. Only **`registration.killswitch`** is `owner: 'incident'`; the other 16 are `owner: 'developer'`. Non-owned flags are filtered out of `index()` server-side (no greyed rows, no names) and `toggle()` `abort_unless(...404)` — a 404 identical to an unknown key, so probing cannot confirm they exist |
| 45 | **F98 — is "Feature flags" expected in the admin nav?** | **RESOLVED — yes, by design** | The registration killswitch is deliberately admin-reachable ("A killswitch only one absent person can pull is not a killswitch" — routes/web.php). A plain `admin` opening the page sees exactly **1 of 17** flags |
| 46 | **F98 — is "Plan settings" reachable by admin?** | **RESOLVED — yes, deliberately, read-only** | `/admin/compensation/plan-settings` GET is intentionally ungated ("Viewing the plan is monitoring; EDITING it changes what the platform pays"). Every write route sits behind `role:developer` — a gate `Gate::before` cannot open — and the view renders `<fieldset @disabled(! $canEdit)>` / `@if($canEdit)` so a non-developer gets no editable control. **Not a finding**, but see F34-15 for the doctrine note. `/admin/compensation/payout-settings` IS `role:developer` on the GET as well |
| 47 | `/admin/settings` per-key ownership | PASS (by code) | The developer session sees **117** keys. `userCanRead()` = `owner === 'admin' \|\| hasRole('developer')` plus 6 `ADMIN_READABLE_KEYS`. Computed from the registry: a plain `admin` sees **27 of 117**. `notifications.engine_health_email`, `genealogy.downline_stats_visible`, `payout.gateway`, `payout.razorpay.*`, `payments.gateway.razorpay.enabled` are all developer-only and invisible to `admin` ✓ |
| 48 | Admin-readable-but-not-writable keys | PASS | `commerce.cooling_off.days`, `comp.tds.rate_bp`, `comp.admin_charge.rate_bp`, `payout.min_threshold_paise`, `payout.neft_min_bv_paise`, `comp.gsb.min_bv_paise` — visible read-only so compliance can verify what reaches a bank account |
| 49 | `age-rules` form | PASS | separate POST `/admin/settings/age-rules`; a single JSON `<textarea name="state_age_minimums">` currently holding `{"MH":21}`. `updateStateAgeMinimums()` re-parses and revalidates every entry (2-letter uppercase state code, integer age 16–30) so a typo cannot let a minor register or lock out the country. **Nothing changed.** |
| 50 | **No setting or plan value changed** | PASS | zero POSTs to `/admin/settings/*`, `/admin/feature-flags/*`, `/admin/compensation/plan-settings/*` |
| 51 | `/admin/staff` — stealth developer role | PASS (by code) | `visibleStaffQuery()` adds `whereDoesntHave('roles', name = 'developer')` for any viewer without the developer role, and the role filter/list comes from `User::visibleRoleNames($viewer)`. The current page shows the `developer` role + user 242 **only because the viewer IS the developer**. No staff created or edited |
| 52 | `/admin/help` index + every doc renders | FAIL (1 of 17) | 16 docs return 200 (127–183 ms). **`franchise-programme` → 404** — see F34-4 |
| 53 | Help doc sync — payouts | in sync | see the sync table below |
| 54 | Help doc sync — engine runs / health digest | in sync | see the sync table below |
| 55 | Help doc sync — distributor requests + KYC re-upload | in sync | see the sync table below |
| 56 | Console errors | PASS | no console messages on `/admin/kyc/34` or `/admin/tree` after enabling capture |

## Help-doc sync table
| Doc | In-sync? | Evidence / missing phrase |
|---|---|---|
| `compensation.md` | **in sync** | L11 & L106 "taken at **credit time**"; L106 "admin charge (**3%**, capped **₹25,000**/cycle) and then **TDS** (**5%** of the payable)"; L225 "per-group admin charge (3%, each group capped ₹25,000/cycle) and TDS (5%)"; §"Daily engine-health email" (L248–279) covers the 08:00 IST digest, the three report classes and `compensation:engine-health-digest --always` |
| `payout-operations.md` | **in sync** | L57–58 "repurchase deduction (already taken at **credit time**; the batch only sweeps and reports it) → **admin charge** → **TDS** → net"; L88–90 points at the 08:00 IST engine-health email. *Note only:* it never states the 3% / 5% figures itself — it defers to Settings and `compensation.md`. Not stale, but a reader on this page alone does not learn the rates |
| `admin-actions.md` | **in sync** | L92 lists "plan rates, caps and periods (admin charge, TDS, payout thresholds)" among developer-owned settings; L126 "TDS and admin-charge rates, and the payout thresholds are shown read-only" — matches the `ADMIN_READABLE_KEYS` behaviour verified in check 48 |
| `distributor-requests.md` | **in sync** | Documents the `identity.distributor_requests` flag and its zero-trace behaviour, the five request types, who decides each, and (L41) the hand-off "…through KYC **re-upload** as well" |
| `kyc-review-guide.md` | **in sync** | L14 reject → "the applicant can **re-upload** and resubmit"; L46 "**Flag a single document for re-upload** — when just one document is unclear/wrong… (preferred over a full reject)" — exactly the flow exercised in check 27 |
| `payments.md` | **in sync (out of scope)** | gateway/checkout doc; carries no payout-deduction claims, so nothing to go stale |
| `franchise-programme` | **STALE — the file is gone** | listed in `AdminHelpController::DOCS` but `resources/help/franchise-programme.md` was deleted by 59ef9bf3 ("replace Franchise module with ADC collection-point workflow"). See F34-4 |

## Findings
### F34-1 — KYC document previews bypass the audited stream and embed a 30-minute unauthenticated S3 URL — **High**
`resources/views/admin/kyc/show.blade.php` L94–100 builds `$directUrl = $diskKyc->temporaryUrl($doc->object_storage_key, now()->addMinutes(30))` and puts it straight in `<img src>`. Only the "Open full size →" anchor uses `route('admin.kyc.document', …)`, i.e. `AdminKycController::streamDocument()` — the one place that writes `admin.kyc.document_viewed`.
Consequences: (a) an admin can read every applicant's PAN and Aadhaar scan on the review page and **no audit row is written** for the view that actually happened; my audit trail has a single `admin.kyc.document_viewed` (id 3233) even though six documents were rendered to me on `/admin/kyc/33` and six more on `/admin/kyc/34`; (b) the presigned URL sits in the page source and is valid for 30 minutes **with no session, no role and no logging** — copy it out of view-source, or out of a screen-share, and it is a working link to a raw Aadhaar scan.
Expected: the inline preview should go through the audited route (as it already does on the local disk branch), or the presigned window should be seconds rather than 30 minutes and the generation itself audited.
Repro: open `/admin/kyc/{pending id}` → inspect any document `<img>` → its `src` is `https://arovolife-staging.s3.ap-south-1.amazonaws.com/kyc/…?X-Amz-Signature=…`, not `/admin/kyc/{id}/documents/{docId}`.

### F34-2 — Post-approval PII purge leaves `aadhaar_back` and `cheque` behind — **Medium**
`ApproveKycSubmission::purgeIdNumbersAndFiles()` (L151–180) filters `->whereIn('type', ['pan', 'aadhaar'])`. Verified on distributor 33: after approval `pan_encrypted` and `aadhaar_encrypted` are NULL and documents 6 (`pan`) and 7 (`aadhaar`) are gone — but 8 (`aadhaar_back`), 9 (`cheque`), 10 and 11 (`address_proof_*`) remain in S3 and in the table.
`aadhaar_back` carries the Aadhaar secure-QR and the resident's full address; `cheque` carries the **full bank account number** in plain image form — the very field the rest of the platform is careful to reduce to `bank_ifsc` + last-4. Risk register **R-31** describes the control as "nulls both columns and **purges the uploaded images**", which is only two of the six images.
Expected: either purge the whole document set on approval (the verification is recorded on the row, the image is no longer needed) or amend R-31 to state exactly what is retained and for how long.

### F34-3 — The audit log's tamper-evidence chain is mostly empty — **Medium**
`audit_log` totals at the end of this task: 3235 rows, of which only **19** have a `before_hash` (18 × `order.paid`, 1 × `refund.settled`) and only **1126** have a `row_hash` at all — roughly 2100 rows carry neither a digest nor a chain link. `admin.kyc.approved` (3234), the row for the state change that flips a person from pending to active and destroys their PII, has `before_hash = NULL` and `after_hash = NULL`. So do `admin.kyc.document_flagged` (3235) and both impersonation rows.
This is the direct consequence of call sites using `AuditLog::create([...])` without the digest helper (memory note "audit_log digests are BINARY(32) — always `AuditLog::digest()`"). The before/after evidence a DSR/DPDP audit would ask for is not there for the identity-lifecycle actions.
Expected: `admin.kyc.approved` / `.rejected` / `.terminated`, `admin.distributor.*` and the impersonation pair should all carry before/after digests and a chained `row_hash`.

### F34-4 — "Franchise Programme" help card is a dead link advertising a compliance-blocked feature — **Medium**
`/admin/help` renders 17 cards; clicking **Franchise Programme** → **404**. `AdminHelpController::DOCS` still holds the `'franchise-programme'` entry (L79–82) but `resources/help/franchise-programme.md` was deleted by commit **59ef9bf3** "feat(adc): replace Franchise module with ADC collection-point workflow". The card's description — visible on the index without clicking — reads "…the **3% fulfilment commission**, the approval lifecycle, and the three gates before it goes live", i.e. the admin console is still advertising a payout that memory records as parked and legally blocked pending an opinion.
Expected: drop the registry entry (the module was replaced), or restore the doc rewritten for ADC.

### F34-5 — A flagged document is invisible in the KYC queue, and Approve stays live — **Medium**
After flagging document 16, `/admin/kyc/34` shows it correctly ("Flagged for re-upload on 10 Sep 2026 23:26 · *QA test…* · Awaiting applicant re-upload."). But the queue at `/admin/kyc` still lists ADN 419167932 as an ordinary "Review →" row with `Docs 6`, indistinguishable from the other 282, and the **"Rejected — awaiting re-upload" tile stays at 0** because it counts `users.status='rejected'`, not `kyc_documents.flagged_at`. Meanwhile the **Approve KYC** button on the detail page is still enabled while a flag is unresolved.
Consequence: with 283 rows in the queue no reviewer can see which submissions are blocked on the applicant, and a second reviewer can approve a submission whose document was just rejected as unreadable — which would also silently kill the applicant's re-upload link (`authorizeAccess()` 404s once the flag clears).
Expected: a "flagged / awaiting re-upload" badge and count in the queue, and Approve either disabled or behind an extra confirmation while any document is flagged.

### F34-6 — KYC approval reaches the distributor by email only — **Low**
`KycApprovedNotification::via()` returns `['mail']`, while `KycDocumentFlaggedNotification::via()` returns `['mail','database']`. After approving distributor 33 the `notifications` table holds nothing for user 34 — the single row in the whole table is the flag notification I created. If mail fails (and on staging it does, F34-9) an approved distributor gets no signal at all; the flagged one at least sees it in-app.
Expected: add `database` to the approval notification so the welcome/activation lands on the bell too.

### F34-7 — `/admin/distributors` search does not match phone, `/admin/tree/search` does — **Low**
`AdminDistributorController::index()` searches `distributors.adn`, `users.email`, `users.full_name` only; `?q=9533933130` returns "No distributors found." while `/admin/tree/search?q=9876543219` resolves to ADN 678721891 via `TreeController::buildMatchQuery()`'s digit-normalising phone clause. The placeholder ("Search ADN, email, name…") is honest, so this is a consistency/UX gap rather than a broken promise — but support staff are given a phone number far more often than an email.

### F34-8 — Distributor detail page carries no KYC state and no orders/BV summary — **Low**
`/admin/distributors/1` shows identity, placement, referral link, cooling-off, sponsor, account actions and the audit trail — but no KYC status row and no orders/BV figures; those need a click through to *BV Ledger* / *Compensation →*. For a support screen this is one hop too many, and the KYC state in particular is the first thing anyone asks about.

### F34-9 — Staging SMTP is rejecting mail, so the re-upload email did not go out — **Low (environment)**
`laravel.log` 2026-09-10 23:26:40 — `Symfony\Component\Mailer\Exception\UnexpectedResponseException: Expected response code "250" but got empty code` for the `KycDocumentFlaggedNotification` mail leg. `failed_jobs` has 11 rows, all from 2026-09-05, same SMTP exception. The flash message nonetheless tells the admin "The applicant **has been** emailed a re-upload link", which is not true when the transport fails. Also seen every request: `Ops env check: LOG_SLACK_WEBHOOK_URL is empty — critical alerts will not reach Slack.`

### F34-10 — Audit log has no actor filter — **Low**
Filters are `action`, `subject_type`, `from`, `to`. "Which rows did this staff member write" — the separation-of-duties question the log exists to answer — cannot be asked from the UI.

### F34-11 — Stale per-user Pennant overrides silently disable the HIBP breach check for user 1 — **Low (security)**
`features` holds scope-specific rows alongside the global ones:
`HibpPasswordCheck / User|1 = false`, `User|3 = true`, `User|33 = true` (global `__laravel_null = true`).
`RegistrationKillswitch / User|1 = true` likewise. The feature-flag console now deliberately reads and writes `Feature::for(null)` (fixed for exactly this reason), so these leftovers are invisible in the UI — but any check that resolves against the user scope still sees them, meaning `admin@arovolife.test` (user 1) is exempt from the breach-password check while the console reports it ON. Expected: purge scoped rows for globally-scoped flags.

### F34-12 — `FranchiseFeature` has a stored Pennant row but no registry entry — **Low (housekeeping)**
`features` contains `App\Modules\Shared\Features\FranchiseFeature / __laravel_null / true` — active, yet absent from `AdminFeatureFlagController::registry()`, so it cannot be seen or turned off from the console. Same replaced-module residue as F34-4.

### F34-13 — The self-rooted root distributor is shown as its own sponsor — **Low**
`/admin/distributors/1` renders "SPONSOR · 444555666 · Arovolife Private Limited". The CSV export already handles this (`$sponsorAdn = $d->sponsor_adn === $d->adn ? '' : …`); the detail view does not.

### F34-14 — `/admin/tree` ships 3.6 MB of HTML for 316 nodes — **Low (performance, will not stay low)**
926 ms / 3,637 KB server-rendered for the whole company at 316 distributors — every node fully expanded in the initial document. Under the 3 s bar today, but the payload is linear in the register: at 10,000 distributors this is a ~115 MB document. Worth a lazy/paged expansion before launch.

### F34-15 — Note on "Plan settings" being readable by `admin` (F98 context) — **Info, not a defect**
Read access is explicitly designed ("Viewing the plan is monitoring") and writes are `role:developer`. Flagging only because it sits in tension with the zero-trace doctrine applied elsewhere: an `admin` cannot see that a developer-owned *setting* exists, but can read every GSB slab, rank tier and Fortune level on the plan-settings page. If that asymmetry is intended, it is worth one line in `admin-actions.md`.

### F34-16 — Staging feature-flag state, for the orchestrator's awareness — **Info**
All 16 registry flags are globally ON on staging, including `compensation.gsb_daily_pool_pricing` (R-33 — must not be ON in an environment paying real distributors until the DSA §6.2 notice has run) and `commerce.purchase_offers` (R-47). Correct for a QA box; listing it so nobody promotes this state to production.

## Mutations made on staging
| # | What | Before → After |
|---|---|---|
| 1 | **KYC approval** — distributor **33** (ADN 678721891, users.id **34**, gvmss8@gmail.com) | `users.status` pending → **active**; `distributors.pan_encrypted` / `aadhaar_encrypted` NOT NULL → **NULL**; `kyc_documents` 6 (`pan`) and 7 (`aadhaar`) **deleted** with their S3 objects; docs 8–11 `verified_at` NULL → 2026-09-10 23:25:33. Queue 284 → 283, users active 35 → 36, pending 284 → 283 |
| 2 | **Document flag** — `kyc_documents.id 16` (address_proof_front, distributor **34**, ADN 419167932, users.id 35) | `flagged_at` NULL → **2026-09-10 23:26:38**; `flagged_reason` NULL → "QA test 2026-09-10 — please ignore"; `flagged_by` NULL → 242. Global flagged count 0 → 1 |
| 3 | **Impersonation session** — user 2 (ADN 444555666), started and stopped | no data change |
| 4 | Audit rows written | **3231** admin.impersonate.start · **3232** admin.impersonate.stop · **3233** admin.kyc.document_viewed (kyc_document 6) · **3234** admin.kyc.approved (distributor 33) · **3235** admin.kyc.document_flagged (distributor 34). Plus 2 × `admin.register.exported` (≤3230) from reading the CSV |
| 5 | `notifications` | 1 new row — `KycDocumentFlaggedNotification` for notifiable_id 35 |

Nothing else was written. No flag toggled, no setting or plan value changed, nobody rejected / terminated / frozen / deactivated, nothing deleted, no engine triggered, no staff created or edited. All DB access was read-only `SELECT` over SSH.

## Environment
- Slowest page: **`/admin/tree`, 926 ms** (3.6 MB). Everything else 127–277 ms. Nothing over 3 s.
- Console errors: **none** captured on `/admin/kyc/34` or `/admin/tree`.
- Server log noise on every request: `Ops env check: LOG_SLACK_WEBHOOK_URL is empty — critical alerts will not reach Slack.`

## Notes for the orchestrator
- F98 is resolved and is **not** a defect: "Feature flags" is admin-visible on purpose (1 incident flag of 17) and "Plan settings" is admin-readable on purpose (writes are `role:developer`, view fields disabled).
- Two mutations landed as instructed: approved users.id **34** / distributor 33, flagged kyc_documents.id **16**. Both are seeded test accounts, neither reserved nor a QA login.
- `distributor_requests` is empty, so scope item 3 could only be checked as an empty state — re-run it once F74 (ClamAV) lets uploads through.
- F34-1 and F34-3 are the two worth escalating before launch; both are DPDP/audit-evidence issues, not cosmetics.
