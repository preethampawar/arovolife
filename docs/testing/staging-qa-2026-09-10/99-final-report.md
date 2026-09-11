# Staging QA 2026-09-10/11 — consolidated report

Scope: every bonus engine, engine run, cron/scheduler, report, stat, payout, confirmation, plus the ~70 commits shipped in the week to 2026-09-10 (deployed `6f114500`). 30 tasks, 27 result files under `results/`, 123 findings in `00-plan.md`. Reusable playbook: `docs/testing/staging-qa-playbook.md`.

## 1. Verdict

**Engines and money maths are sound. Operations around them are not launch-ready.**

- Every engine ran or re-ran correctly on closed periods, the scheduler fired unattended overnight at the exact minutes (repurchase 00:05, GSB cut-off 00:10, digest 08:00), the queue drained every job, and all 17 compensation reports and every income page foot to the paise against the database.
- The commerce loop (order → ship → deliver → return → refund → BV reversal to the originally-credited upline, no cash leakage) completed end to end.
- What fails is the operational and compliance layer: wrong analytics numbers, a company centre paid an ADC bonus, payout holds never re-evaluated, no maker-checker on payout approval, failed engine runs with no recorded reason, KYC previews that bypass the audit trail, an income-projection guard that is a substring match, and a regulator-facing export that crashes.

## 2. Task results

| Phase | Tasks | Outcome |
|---|---|---|
| 0 Setup | T01, T02 | done; QA logins + bank details + KYC seeded; product BV values are test data (F02) |
| 1 Engines (DB) | T10–T18 | all verified; defects F05, F30/F86 (premature freeze 5 Sept), F39/F40/F43/F50/F81 (failed runs invisible), F47/F48, F21 |
| 2 Distributor (Chrome) | T20–T26 | all pass-with-notes; F55 (price shown ≠ charged), F61 (weaker-side label), F52/F89 (income tile), F67/F68, F70–F73 (bank path, consent copy), F74–F80 (uploads blocked, name enumeration) |
| 3 Admin (Chrome) | T30–T36 | T35 and T36 FAIL; F81/F82 (engine runs), F93/F94 (payout holds, maker-checker), F95/F96 (NEFT), F106/F107 (KYC PII), F113–F116 (income-projection guard, missing permission, publish rule), F119/F120 (analytics ×100, company centre bonus) |
| 4 Cross-cutting | T40, T41, T42, T43 | copy + security sweeps done; log sweep clean except F122 (report export 500); playbook written |

## 3. Findings that block launch (Critical / High), grouped

**Money and compensation**
- F120 Critical — company Arete Centre (id 1) has an assigned distributor and was paid ₹10,500 ADC bonus for September; engine gates on `assigned_distributor_id` only, not `centre_type`.
- F86/F30 High — 5 Sept pools frozen at 14:03: MSB short ₹7,488; ~2.5 lakh BV missing from September's monthly pools. Cleanup = F10 windowed recompute (destructive, needs your go-ahead).
- F93 High — payout hold reasons frozen at batch creation; d1–d3 now have bank + KYC but stay "No bank account"; approved batch 3 records the stale reason.
- F94 High — no maker-checker: approve/reconcile/retry all `finance.record`; no `finance.approve`; no `created_by`.
- F95/F44 High — NEFT export has no account number / IFSC, downloads on unapproved batches, no permission gate.
- F47/F48 High — `payout:monthly-run` ungated and defaults to the current month; monthly batch has no earning window.
- F05 High — monthly-close resume/gate ignores run timing.
- F70 High — no self-service bank-details path; skippers are held forever.
- F119 Critical — `/admin/analytics` BV under-reported 100× (double division).

**Observability and operations**
- F81 High — failed engine runs never write `error`; reasons exist only in laravel.log (run 43 not even there).
- F74 High (staging) — no ClamAV: every upload refused (requests, grievance evidence, KYC re-upload untestable).
- F122 High — grievance compliance-report CSV export 500s on a fractional median (`Csv::safe()` rejects floats).
- F114 High — `messaging.moderate` permission missing on staging (seeder additive; needs a re-seed).

**PII, security, compliance copy**
- F106 High — KYC document previews use 30-minute presigned S3 URLs; views are not audited and the URL works without a session.
- F107 Medium — approval purge covers only PAN + Aadhaar images; `aadhaar_back` and `cheque` (full account number) survive.
- F113 High — `NoIncomeProjection` is a literal substring list; "earn ₹50,000 per month guaranteed" is accepted.
- F25/F75 High — `GET /messages/{id}` enumerates users by name.
- F17/F53, F18 High — payout-week cadence and messaging reporting visible before the §6.2 / privacy notices.
- F55 High — product page shows the distributor price while checkout charges the sale price.

Medium and Low items (≈78) are in the register with repro steps; the playbook appendix lists every open one for the next run.

## 4. Decisions only you can make

1. **F10 windowed recompute on staging from 2026-09-01** — DONE 2026-09-12 00:06 IST after the 5-point warning and the user's yes (audit 3272/3307): 2,473 rows removed, 12 days replayed, all 10 engine runs succeeded; September re-verified in `deploy-checklist.md` §4. **FULL recompute** then run 2026-09-12 00:24 IST on your request (audit 3313/3339): mode full, window 2026-09-04 → 2026-09-12 (first paid order → today), 4,055 rows removed, 9 days replayed, 16 orders propagated, 2.5 s, no warnings; all 28 rebuilt engine runs succeeded, `jobs` 0, no failed engine run left (run 95 gone). Pools, ledger pairing, batches and all 24 admin report pages verified — `deploy-checklist.md` §4a. Three counts moved against the windowed run and each is explained there (all three are August-period artefacts the windowed wipe from 1 Sept had preserved; nothing September-earned changed). One new finding **F125**: the replay cuts off day D at D 00:10 and *today* at real now, the scheduler cuts off D at D+1 00:10 — a recompute inside the 00:00–00:10 gap makes the next scheduled cut-off fail on the carry-forward guard (staging run 95, 12 Sept 00:10). Fix is a calendar change in `EngineReplayService`, figures unchanged; ops rule until then: no recompute between 00:00 and 00:10 IST.
2. **F120** — is the company centre allowed an assigned distributor? If not, the engine must exclude `centre_type='company'` and the ₹10,500 credit must be reversed.
3. **F94** — introduce `finance.approve` (maker-checker) before launch, or accept single-role approval for now?
4. **F70** — add a self-service bank-details page (with confirmation + beneficiary name, F28) or keep bank capture admin-only?
5. **F44/F95** — NEFT export shape: which columns does the bank need (account, IFSC, beneficiary name)?
6. **F33** — should MSB be a repurchase-deduction source? Code excludes it; plan doc lists 5 bonuses.
7. **F55** — which price do members pay: the PDP distributor price or the sale price?
8. **F115** — is "publish one announcement at a time" a real rule, or is the pin limit (3) the intended constraint?
9. **F71/F72** — withdraw-consent copy for never-consented accounts, and populating the `agreements` registry.
10. **F107** — should `aadhaar_back` and `cheque` images be purged on approval too (R-31 wording says all images)?
11. **F82** — keep `COMP_RECOMPUTE_ENABLED=true` on staging (lifts the closed-period guard by design)?
12. **F114** — approve `php artisan db:seed --class=RolesAndPermissionsSeeder` on staging (audited strictly additive).
13. **F77** — helpline hours: `/help` says 10:00–18:00 Mon–Sat; footer says 9:30–17:30 daily except Sunday.
14. **F36** — guest browsing vs members-only storefront; **F65** — downline ADNs in the Genos ledger / suggest endpoint under R-65.
15. **F04** — confirmed by T42: the 08:00 digest ran with `failures=2`; please confirm the email arrived in the configured mailbox.

## 5. Staging residue from this run (cleanup list — nothing deleted yet)

Orders 17 (returned + refunded ₹1,497, `RMA-1SKZGBQL9R`, refund_intents 1) and 18 (delivered); tickets `GRV-260910-2PF6K` (escalated, open) and `GRV-260910-39CCK` (closed); content_pages 9 and announcements 1–2 (archived); message 12 + message_reports 1 (reviewed); users.id 34 KYC-approved, kyc_documents 16 flagged; payout batch 3 approved (audit 3218); engine_runs 34–49 manual/QA rows (50–51 are real scheduled runs); audit_log 3199–3271; QA passwords and dummy bank details for d1–d7. All harmless; the derived compensation state is only cleanly reset by F10.

## 6. Verified working (so nobody re-tests it needlessly)

Idempotent manual engine trigger through the queue (run 49, zero ledger change); overnight scheduler + digest; GSB pool priced the T33 reversal correctly (59,900 paise net); OTP-gated mobile change, password round-trip, HIBP refusal; PAN/Aadhaar guards in messaging; block/unblock/report; grievance SLA stamps exactly 48 h / 5 working days / 30 days; public tracking; KYC resubmit/re-upload guards; return → refund → BV reversal with wallet untouched (R-60); Payout Settings and developer surfaces hidden from `admin`; all exports ungrouped with PII limited to ADN + name; 0 PII hits in logs; console clean on every page; slowest page 926 ms (`/admin/tree`), everything else under 600 ms.

## 7. Fix run — 2026-09-11 (addendum)

Branch `fix/staging-qa-2026-09-10` from `main` @ 6f114500. 14 implementer batches (B1–B14), one atomic commit per finding where the shared tree allowed it (see `fix-plan.md` §A for the finding → commit map and `fixes/B*.md` for detail).

**Outcome**
- 87 findings fixed and committed; 2 partly (F78 not reproducible in code, regression test added; F37 has two staging-data items left).
- 21 client decisions received and applied (`fix-plan.md` §B). Still awaiting the client: F107 (Aadhaar images — compliance stop, alternative offered) and F124 (consumed purchase-offer grants re-granted by a windowed recompute).
- Closed by decision: F36 guest browsing, F115 several live announcements, F65 downline ADNs, F04 digest arrived.
- Not code (ops/data): F10 recompute, F11 supervisor timeout, F74 `CLAMAV_ENABLED=false`, F114 seeder (run on staging 12:50 IST; re-run after deploy), F112/F109 Pennant rows, F56 settings row, F01 BV values, F03 mail limit.

**Verification on the final tree**
- SQLite full suite: 2,302 passed, 1 skipped, 0 failed.
- MySQL full suite (arovolife_test, container): 1 skipped, 2302 passed (12491 assertions).
- Pint: clean. Larastan: 0 errors after regenerating the baseline (new entries are test-file Pest false positives only; no application path added).
- Compliance spot-review (orchestrator): no decrypted account/PAN/Aadhaar in logs, flashes or audit details; bank OTP payload holds ciphertext; NEFT file, approve, KYC preview, recompute routes gated as intended; company centre excluded from ADC; MSB deduction routed through the shared wallet method; income-projection guard pattern-based with the plan's cap statements still allowed. One extra fix applied by the orchestrator: the S3 branch of the KYC preview route still redirected to a presigned URL — now serves bytes (1931a560).
- Six forward-only migrations applied to the local dev DB; none touched staging.

**Behaviour changes to tell the client** (also in `deploy-checklist.md` §5): members pay the distributor price; MSB credits carry the repurchase deduction; approving a payout batch needs the `admin` role and cannot be done by its creator; the NEFT download is a real bank file (narration `arovolife <ADN> B<batch id>` — confirm); Lifetime Award cash released in month M pays on the 8th of M+1; malware scanning is off by client decision (R-83); helpline hours 10:00–18:00 Mon–Sat everywhere.

**Process notes**: the shared working tree caused commit-attribution collisions (six commits carry other batches' files; content verified, history not rewritten); two session-limit kills (12:40, 17:40 IST) — playbook §10 records the mitigations.
