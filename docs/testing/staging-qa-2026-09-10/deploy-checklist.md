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
- [ ] **F10 windowed recompute from 2026-09-01** via Engine Runs page (deletes Sept derived rows: pools, wallet rows, payout batches 1–3, orphan invoices 17/18, rank_qualifications 5; rebuilds with the fixed engines — company centre excluded, MSB deduction applied, ADC bonus_month stamped). Present the 5-point warning first.
- [x] Delete Pennant `features` row `App\Modules\Shared\Features\FranchiseFeature` (orphan; class deleted).
- [x] Delete Pennant `features` row `HibpPasswordCheck / User 1 = false` (F112 stale per-user override).
- [x] Delete `settings` row `payments.cod.enabled` (F56, client: COD off).
- [ ] Decide the 7th shop category "Food and Beverages" (delete + re-home its product, or add to the homepage grid) — client/catalogue.
- [ ] Replace the Immunity Booster listing art (catalogue upload) — folds into F01 real BV values.
- [ ] Residue from the QA run (orders 17/18, tickets GRV-260910-*, content_pages 9, announcements 1–2, message 12, users.id 34 KYC state) — harmless; leave unless the client wants a clean slate.

## 4. Re-verify after recompute
- [ ] 5 Sept GSB/MSB pool: company BV 8,09,400; MSB point value ≈ ₹622; distributor 1 MSB ₹24,258.
- [ ] `adc_bonus_results` has no row for centre 1 (company); distributor 33 has no `adc_credit`.
- [ ] MSB credits carry `repurchase_deduction` entries where a repurchase debt exists.
- [ ] Payout batches rebuilt with `created_by NULL`, `earnings_through` stamped on the monthly batch; approve requires an `admin` user.
- [ ] `/admin/analytics` BV Generated ≈ 20,39,999 BV (not 20,399).
- [ ] `/admin/grievances/report/export` returns 200 with a fractional median.
- [ ] Re-run the playbook phases 1–3 spot checks listed in `docs/testing/staging-qa-playbook.md` §6.

## 5. Production notes (for launch, not now)
- `COMP_RECOMPUTE_ENABLED` must be unset (F82).
- `CLAMAV_ENABLED=false` per client (R-83) — revisit at the Phase 12 gate.
- Seeder + `consent:backfill-agreements` + `content:publish compensation` on first install (B7 runbook step 9).
- NEFT narration format `arovolife <ADN> B<batch id>` — confirm with the client before the first live batch.
- Payout day needs an `admin`-role approver (admin-finance can no longer approve).
- Lifetime Award cash credits released by hand in month M now pay in M's batch on the 8th of M+1.
