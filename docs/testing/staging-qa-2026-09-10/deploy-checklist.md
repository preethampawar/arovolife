# Deploy checklist — branch `fix/staging-qa-2026-09-10` → staging

> **2026-09-11 23:50 IST:** main fast-forwarded to 6cfe9075 and pushed; staging pulled, 7 migrations applied, build synced, caches cleared, workers restarted; `CLAMAV_ENABLED=false` added; seeder run (`finance.approve`, `content.publish` present); consent backfill created 8 agreements + 4 supersession links; `kyc:encrypt-documents` converted 1,627 objects; the three cleanup rows deleted. Health 200. F10 recompute awaits the 5-point go-ahead.

Every step below is in order. Steps marked **(go-ahead)** are run only after the user says yes in chat; steps marked **(user)** are done by the user in a panel.

## 0. Before deploy (local)
- [x] Full suite green on SQLite and on MySQL (`arovolife_test` in `arovolife-app`); Larastan clean; `pint --test` clean.
- [x] Compliance review of the branch diff (compliance-officer agent) — covers every commit that touches money/KYC/consent/tree/copy, including the ones whose messages lost their `Compliance-Review` trailer in the shared-tree race (bank details B10, MSB/company-centre B11, maker-checker/NEFT B9, F33 inside 48cc6dbd).
- [x] `npm run build` locally (server Node 20.5.1 cannot build; Trix is now a Vite entry — the content editor throws `ViteException` without a fresh `public/build`).
- [x] Merge to `main` locally (solo-dev workflow) — **confirm with user before pushing**.

## 1. Deploy (go-ahead)
- [x] Push `main`; Cloudways `git_pull` recipe (memory: cloudways_staging_deploy).
- [x] rsync `public/build` to the server.
- [x] `php artisan migrate --force` — 7+ forward-only migrations (mentorship deduction columns, UTR unique index, rank pools backfill, repurchase re-date, payout `created_by`, bank beneficiary name, `kyc_documents.encrypted_at`) + any from B13.
- [x] `php artisan config:clear && php artisan route:clear && php artisan view:clear`; restart queue workers + scheduler (memory: engine_runs_admin_page).

## 2. Post-deploy on staging (go-ahead each)
- [x] `.env`: add `CLAMAV_ENABLED=false` (client decision Q20; R-83). **(.env change — explicit yes required)**
- [x] `php artisan db:seed --class=RolesAndPermissionsSeeder --force` — adds `content.publish` and `finance.approve` (additive; without it **no payout batch can be approved**).
- [x] `php artisan consent:backfill-agreements --dry-run`, then without `--dry-run`.
- [ ] `php artisan content:publish returns` — ONLY after the DSA §5.4 / §10 contradiction is reconciled (B6 note); until then the page stays a draft.
- [x] `php artisan kyc:encrypt-documents` — converts the KYC scans already in the bucket to vault ciphertext (idempotent; F107/Q22).
- ~~Cloudways panel → Supervisor `--timeout=7200`~~ — Cloudways caps it at 999 s; not needed, job-level `$timeout` overrides the flag (F11 closed).
- [ ] Verify: Engine Runs page shows the red gate banner; `/admin/messaging/reports` opens for admin-operations; payout batch page shows Created by / Approved by.

## 3. Staging data cleanup (go-ahead each; the 5-point warning applies to F10)
- [x] **F10 windowed recompute from 2026-09-01** via Engine Runs page — run 2026-09-12 00:06:52 IST by the developer account (audit 3272/3307): windowed, 12 days replayed, 16 orders propagated, 2,473 rows removed, 10 engine runs succeeded, 3.1 s, no warnings. (deletes Sept derived rows: pools, wallet rows, payout batches 1–3, orphan invoices 17/18, rank_qualifications 5; rebuilds with the fixed engines — company centre excluded, MSB deduction applied, ADC bonus_month stamped). Present the 5-point warning first.
- [x] **FULL recompute** (user's request, 5-point warning + yes) — run 2026-09-12 00:24:33–36 IST by the developer account (audit 3313/3339): mode full, window 2026-09-04 → 2026-09-12, 4,055 rows removed (gsb_cutoff_results 3,804, engine_runs 55, wallet rows 54, pools 12+12, rank pools 18, …), 9 days replayed, 16 orders propagated, 2.5 s, warnings none. Verified in §4a.
- [x] Delete Pennant `features` row `App\Modules\Shared\Features\FranchiseFeature` (orphan; class deleted).
- [x] Delete Pennant `features` row `HibpPasswordCheck / User 1 = false` (F112 stale per-user override).
- [x] Delete `settings` row `payments.cod.enabled` (F56, client: COD off).
- [ ] Decide the 7th shop category "Food and Beverages" (delete + re-home its product, or add to the homepage grid) — client/catalogue.
- [ ] Replace the Immunity Booster listing art (catalogue upload) — folds into F01 real BV values.
- [ ] Residue from the QA run (orders 17/18, tickets GRV-260910-*, content_pages 9, announcements 1–2, message 12, users.id 34 KYC state) — harmless; leave unless the client wants a clean slate.

## 4. Re-verify after recompute
- [x] 5 Sept GSB/MSB pool: company BV 8,09,400; MSB point value ≈ ₹622; distributor 1 MSB ₹24,258. — verified: 8,09,400 BV, 39 points, ₹622 point value, distributor 1 MSB 11,196 + 13,062 = ₹24,258.
- [x] `adc_bonus_results` has no row for centre 1 (company); distributor 33 has no `adc_credit`. — verified (0 / 0).
- [x] MSB credits carry `repurchase_deduction` entries where a repurchase debt exists. — verified: every `mb_credit`/`gsb_credit`/`rank_credit` is paired with a `repurchase_transfer` + `repurchase_deduction` (14 pairs in September).
- [x] Payout batches rebuilt with `created_by NULL`, `earnings_through` stamped on the monthly batch; approve requires an `admin` user. — verified: batches 4 (weekly 01 Sep → 25 Aug), 5 (weekly 08 Sep → 01 Sep), 6 (monthly 01 Sep → 30 Sep, ₹42,421.99 gross / ₹35,536.20 net, 4 distributors), all pending, created_by NULL. Approval not re-tested (needs an admin-role user on payout day).
- [x] `/admin/analytics` BV Generated ≈ 20,39,999 BV (not 20,399). — verified: 20,39,999 BV.
- [x] `/admin/grievances/report/export` returns 200 with a fractional median. — verified: 200, `median_resolution_days` 0.50 for 2026-09.
- [x] Re-run the playbook phases 1–3 spot checks listed in `docs/testing/staging-qa-playbook.md` §6. — SQL foot-checks above cover daily cut-off, payout batch, monthly engines, income pages, analytics and grievance SLA; the only non-succeeded engine run is 37 (August monthly-close, QA-triggered 10 Sept, outside the window).

## 4a. Re-verify after the FULL recompute (2026-09-12 00:24 IST)

Same checks as §4, run against the rebuilt state, plus every admin report page fetched with the signed-in developer session (all HTTP 200, figures read from the rendered page).

| Check | Windowed run (00:06) | Full run (00:24) | Verdict |
|---|---|---|---|
| Engine runs | 10 new, all succeeded; run 95 (11 Sept cut-off, scheduler 00:10:03) FAILED afterwards — F125 | 28 total, all succeeded, `trigger=console`; daily 04–12 Sept (repurchase.evaluate 00:05 + gsb.daily-cutoff 00:10 each day), weekly payout 08 Sept 03:00, monthly engines for 2026-09 at 00:24:34, no failed row | PASS |
| Queue | jobs 0 | jobs 0; failed_jobs 11 unchanged (pre-existing SMTP/Aug rows) | PASS |
| 5 Sept pool | 8,09,400 BV / 39 pts / ₹622 / D1 MSB ₹24,258 | identical: MSB pool ₹24,282, 39 points, ₹622.00 point value, payout ₹24,258; D1 MSB 11,196 + 13,062 = ₹24,258; 4 Sept 12,29,400 BV / 36 pts / ₹1,024; GSB pools 4–5 Sept ₹15,000 variable + ₹8,000/₹6,000 fixed | PASS |
| gsb_cutoff_results | 3,804 (12 cut-off days) | 2,853 = 9 days × 317 distributors | PASS |
| Ledger pairing | 14 pairs | 13 credits (6 gsb, 4 mb, 3 rank) each paired with a `repurchase_transfer` + `repurchase_deduction` (13/13, ₹14,254 each side); 2 `repurchase_wallet_used` preserved; 3 × (admin_charge, tds, payout) debits from the monthly batch | PASS |
| Rank | Sept: 4 qualifications (D1 R1+R2, D2 R1, D3 R1), 3 results; plus 1 Aug artefact qualification (F41) and 2 Aug results / 1 AO-GO | Sept identical (4 / 3): R1 pool ₹28,559.99 ÷ 20 pts = ₹1,427/pt → D2, D3 ₹14,270 each; R2 pool ₹13,871.99 → D1; company turnover 20,39,999 BV. August rows gone (no BV before 4 Sept — correct under hard rule 2) | PASS |
| Monthly batch | batch 6: ₹42,421.99 gross / ₹35,536.20 net / 4 lines | batch 2 (01 Sep → 30 Sep): ₹42,411.99 gross / ₹35,527.93 net / 3 lines (D2 ₹14,270 → ₹11,794.15, D3 same, D1 ₹13,871.99 → ₹11,939.63), pending, created_by NULL. The missing ₹10.00 line was the August-period rank credit the windowed wipe (from 1 Sept) had preserved; nothing September-earned changed | PASS (deviation explained) |
| Weekly batches | 4 (01 Sep) and 5 (08 Sep) | only 08 Sep (earnings through 01 Sep, ₹0, 0 lines) — 01 Sep is before the 4 Sept window start; GSB/MSB credits of 4–5 Sept wait for the 15 Sept Tuesday batch | PASS |
| Offers | 3 half-price grants | 4 grants (D4 5,00,000; D5 6,00,599; D6 2,65,000; D7 3,65,000 BV). D7 is new: `PurchaseOfferService::runForMonth` skips anyone with a `qualified` rank row in any month, and D7's zero-BV August qualification (F41 artefact) is gone | PASS (deviation explained) |
| GBB / Fortune / ADC / AW&RW | — | GBB 2026-09 pool ₹1,01,999.95, D2 10 AGP + D3 17 AGP both `repurchase_wallet_blocked` (wallet ≠ 0 gate), 1 pool row; Fortune 1 pool, 0 participants; ADC 0 rows; Lifetime Award 3 pending milestones (2 Silver, 1 Pearl) | PASS |
| `distributors.gsb_frozen_at` | reset | 0 frozen | PASS |
| Reports (24 pages) | — | engine-runs, daily-cutoffs (+ 2026-09-05), personal-bv-topups, msb-input-output, rb-calculation, rb-input-output, rank-bonus/2026-09, gbb-calculation, gbb-input-output, gbb/2026-09, fb-calculation, fortune-bonus/2026-09, adc-calculation, adc-bonus/2026-09, aw-rw-calculation, weekly-payouts (+ /1), monthly-payouts (+ /2), analytics (BV 20,39,999), grievances/report, grievances/report/export (200, 13 CSV lines), lifetime-awards — all 200, figures match the tables above | PASS |
| laravel.log 00:24–00:25 | — | no warning / error / exception / premature-freeze line | PASS |

Still open from this run: **F125** (replay calendar) — check that the 13 Sept 00:10 scheduler cut-off succeeds (it will replace the 12 Sept pool the replay froze at 00:24:34 via the premature-freeze self-heal).

## 5. Production notes (for launch, not now)
- `COMP_RECOMPUTE_ENABLED` must be unset (F82).
- `CLAMAV_ENABLED=false` per client (R-83) — revisit at the Phase 12 gate.
- Seeder + `consent:backfill-agreements` + `content:publish compensation` on first install (B7 runbook step 9).
- NEFT narration format `arovolife <ADN> B<batch id>` — confirm with the client before the first live batch.
- Payout day needs an `admin`-role approver (admin-finance can no longer approve).
- Lifetime Award cash credits released by hand in month M now pay in M's batch on the 8th of M+1.
- Never trigger `compensation:recompute-all` between 00:00 and 00:10 IST (F125) — the scheduled cut-off that follows fails on the carry-forward guard; testing-only tool, unset in production anyway.
