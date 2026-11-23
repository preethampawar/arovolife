# Partner subtree erasure — Implementation Plan

Status: PLAN, awaiting user approval (2026-11-23). Decisions are recorded in memory
`partner_subtree_erasure_decisions.md`. Do not re-ask them.

## Goal

A developer-only action erases one APN (ADN) **and every Genos (binary placement)
descendant** from the platform. Personal data is removed. Money and tax rows stay,
with PII stripped. The erased nodes disappear from every tree, list, count and lookup,
and the parent's Left/Right slot becomes free for new joiners. Upline history (group
BV, ranks, frozen pools, paid bonuses) is untouched. The erase writes **no**
audit_log row, dispatches **no** domain event and writes **no** log line. The
deleted users' *existing* audit_log rows are kept, so the hash chain stays intact.

## Non-goals

- Deleting money/tax rows (orders, invoices, wallet/ledger, payouts, bonus results, TDS).
- Removing existing audit_log rows, or re-chaining audit_log.
- Touching upline BV/ranks/pools, recomputing anything, or reversing paid money.
- Editing past payout bank files (encrypted multi-person files stay as generated).
- Scrubbing DB backups, Laravel log files or third-party systems (Razorpay, Shiprocket, SMS).
- Admin help docs (the developer role is hidden, see `developer_role_stealth_rbac`).
- Undo.

## Architecture decisions

1. **Tombstone, not hard DELETE, for `users` and `distributors`.** FKs CASCADE from
   `distributors` into `wallet_ledger_entries`, `payout_line_items`, every
   `*_results` table and `mentorship_bonus_results.sponsee_id`. That last one is
   the *upline's* income history. A hard delete would destroy rows we chose to keep.
   The rows stay, anonymised, with `distributors.erased_at` set.
2. **The node leaves the tree by rewriting its own pointers.** Set `sponsor_id = id`,
   `placement_parent_id = id`, `placement_side = NULL`, `depth = 0`, and delete every
   `genealogy_closure` row whose descendant is erased. The slot index
   `uniq_distributors_slot(placement_parent_id, placement_side)` frees the parent's slot,
   because `PlacementEngine::resolveSlot()` only counts `whereIn('placement_side',['L','R'])`.
   `TeamStatsService::scopedQuery()` reads closure + sponsorship, so every upline count
   follows automatically. There is no genealogy cache to clear.
3. **APN rewritten to `ERASED-{id}`** (fits `varchar(16)`, stays unique). The 24 ADN lookup
   sites (login, referral link, registration, admin search…) then miss without being
   edited. The old number returns to the random pool (900M space, so reuse is practically
   impossible). The `payout_bank_file_rows.adn` snapshots keep the old number for bank
   reconciliation.
4. **Synchronous in-request**, wrapped in `DatabaseBackupService::exclusively()`.
   On production, first `DatabaseBackupService::create('manual')`. The `manual` trigger
   is used so the backup filename does not reveal an erase. Backups write no audit row.
   If the backup throws, the erase does not run. `set_time_limit(600)`.
5. **Preview fingerprint.** Preview computes the erase set S and
   `fingerprint = sha256(implode(',', sorted S ids))`. Execute recomputes under row
   locks and refuses on mismatch ("The tree changed since the preview — review again").
6. **POST for preview too**, so the APN never lands in nginx access-log query strings.
7. **Blockers refuse; they are not auto-handled** (user decision). Unpaid wallet balance
   is forfeited: the rows stay, and the payout roster excludes erased distributors.
8. **Coverage test** (pattern: `PlatformResetCoverageTest`). Every table having a
   person column must be classified DELETE / ANONYMISE / KEEP in
   `PartnerErasureTableMap`, so a future table can't silently keep erased PII.

## Permission matrix

| Capability | distributor | admin-operations / finance / compliance | admin | developer |
|---|---|---|---|---|
| See "Delete" action in Distributors list rows | ✗ | ✗ | ✗ (hidden) | ✓ |
| GET `admin.distributors.erase.form` | 404 | 404 | 404 | 200 |
| POST `admin.distributors.erase.preview` | 404 | 404 | 404 | 200 |
| POST `admin.distributors.erase.destroy` | 404 | 404 | 404 | 302 (done / refused) |
| `PartnerErasureService::execute()` with non-developer actor | throws `AuthorizationException` | same | same | runs |

404 rather than 403 follows `AdminDatabaseBackupController::ensureDeveloper()`, so the
hidden role is never confirmed. `Gate::before` lets admin pass every Gate, so the check
is `hasRole('developer')`, never `can()`.

## The erase set and the table map

**Sets** (computed in `PartnerErasureService::resolveSet()`):
- `S` = target distributor id + every `genealogy_closure.descendant_id` where `ancestor_id = target`,
  plus every `spouse_distributor_id` of a member, and members whose `spouse_distributor_id` is in S
  (couples go together). Repeat until stable.
- `U` = `distributors.user_id` for S.
- `C` = `customers.id` where `user_id ∈ U` (the partner's own shopper identity).
- `O` = `orders.id` where `customer_id ∈ C`.

**Blockers** (all computed and shown; execute refuses if any exist):
1. Target not found, or already erased (`erased_at` not null).
2. Target or any S member is reserved: `ReservedAdns::isReserved($adn)`, or it is the root (`placement_parent_id = id` and `erased_at` null).
3. `EngineScheduleWindow::isActiveNow()`.
4. Open orders in O: status ∈ {placed, paid, ready_to_ship, shipped, awaiting_collection, refund_requested, refund_inspection, refund_approved} (`Order::STATUS_*`).
5. ADC owner: `arete_centers.assigned_distributor_id ∈ S`.
6. Money in flight: `payout_line_items.distributor_id ∈ S` with `status = 'pending'` whose `payout_batches.status ∈ {pending, processing, approved, dispatched}`. Held / below-minimum lines are NOT blockers (they are forfeited).
7. Pending `line_change_requests` (status pending) by a distributor ∉ S whose `from_placement_parent_id` or `to_placement_parent_id ∈ S`.
8. `sponsorship` rows with `sponsor_id ∈ S` and `distributor_id ∉ S` (someone sponsored by a member but placed outside the subtree after a line change). Listed by APN.

Each blocker in the preview names the APN(s) and the record (order no, centre name, batch id, request id).

**DELETE** (rows; files first collected, deleted after commit):

| Table | Where |
|---|---|
| distributor_profiles, distributor_nominees, distributor_family_members, consents, orientation_views, cooling_off_events, plan_change_notices, attribution_touches, shared_carts, line_change_requests, gsb_reversal_requests | distributor_id ∈ S |
| kyc_documents | distributor_id ∈ S; files: whole prefix `user_{uid}/` on disk `kyc` for each uid ∈ U |
| (id photo) | prefix `user_{uid}/` on disk `s3` (see `IdPhotoStorage`) |
| distributor_request_documents → distributor_requests | request.distributor_id ∈ S; files `object_storage_key` on `DistributorRequestService::DISK` |
| arete_center_application_documents, arete_center_application_declarations → arete_center_applications | application.distributor_id ∈ S; files on `AreteCenterApplicationService::DISK` |
| arete_center_members | distributor_id ∈ S |
| bv_ledger_entries, bv_propagation_log, group_bv_daily, group_bv_debts, gsb_carryforward, gsb_cutoff_deferrals, gsb_personal_bv_topups, rank_qualifications, rank_provisional_standings, rank_aogo_grants, lifetime_award_milestones, repurchase_cycles, purchase_offer_grants, redeem_point_entries, fortune_bonus_participants | distributor_id ∈ S |
| group_bv_credits, group_bv_reversals | ancestor_id ∈ S (rows with an upline ancestor are kept) |
| genealogy_closure | descendant_id ∈ S (this also covers ancestor ∈ S) |
| sponsorship | distributor_id ∈ S |
| cart_items → carts, customer_addresses | carts.customer_id ∈ C / customer_id ∈ C |
| ticket_attachments (files: `disk`,`path`), ticket_events → tickets | tickets.distributor_id ∈ S or customer_id ∈ C |
| messages | from_user_id ∈ U or to_user_id ∈ U |
| message_blocks | blocker or blocked ∈ U |
| message_reports | reported_by_user_id ∈ U |
| notifications | notifiable_type = User morph and notifiable_id ∈ U |
| announcement_reads, sessions | user_id ∈ U |
| password_reset_tokens | email ∈ original emails of U (read before anonymising) |
| model_has_roles, model_has_permissions | model_type = User morph, model_id ∈ U |

**ANONYMISE** (row kept):

| Table | Change |
|---|---|
| users (U) | email `erased-{id}@erased.invalid`; phone_e164 `+0` + id zero-padded to 14 digits; password_hash `Hash::make(Str::random(64))`; full_name `Erased partner`; address, date_of_birth, id_photo_path, mfa_secret_enc, mfa_enabled_at, remember_token, email_verified_at, last_login_at → null; status `terminated`; closure_type `erased` |
| distributors (S) | adn `ERASED-{id}`; pan_hash, pan_last4, pan_encrypted, aadhaar_ref, aadhaar_last4, aadhaar_encrypted, bank_account_enc, bank_beneficiary_name_enc, bank_ifsc, bank_name, razorpay_contact_id, declared_*, declarations_made_at, placement_id_at_registration, spouse_distributor_id, inactivity_notice_at, inactivity_notice_expires_at, termination_reason → null; sponsor_id = id; placement_parent_id = id; placement_side null; depth 0; is_primary_couple 0; status `inactive`; erased_at now. (`state` NOT NULL, kept: GST place of supply.) |
| customers (C) | display_name `Erased partner`; email_hash, email_enc, phone_hash, phone_enc, phone_last4 → null; marketing_opt_in false |
| orders (O) | ship_name `Erased`, ship_phone_e164, ship_line1, ship_line2, ship_city, ship_pincode → null (use `''` where NOT NULL; check the column). Keep ship_state, buyer_gstin, buyer_legal_name (tax invoice fields). |
| offline_payments (distributor_id ∈ S or order_id ∈ O) | payer_name, notes, confirmation_note, rejection_reason, proof_original_name → null/`''`; delete proof file (`proof_storage_key` on `OfflinePayment::DISK`); proof_purged_at now |
| payment_events.payload, payment_intents.raw_payload, shipment_events.payload (order_id ∈ O) | recursive redaction: any key (case-insensitive) in {email, contact, phone, mobile, name, first_name, last_name, customer_name, address, line1, line2, city, pincode, zip, postal_code, vpa, card, bank_account, account_number, notes, customer_details, shipping_address, billing_address} → `"[erased]"` |
| return_requests (order_id ∈ O) | notes, receipt_note → null |
| payout_line_items (distributor_id ∈ S) | bank_account_last4 → null |

**KEEP untouched**: invoices, invoice_lines, order_items, order_cooling_off, coupon_redemptions,
refund_intents, buyback_decisions, wallet_ledger_entries, ledger_tx, ledger_entries,
payout_line_items (other cols), payout_batches, payout_bank_files, payout_bank_file_rows,
payout_gateway_events, payout_build_failures, gsb_cutoff_results, gbb_monthly_results,
rank_bonus_results, fortune_bonus_results, mentorship_bonus_results, adc_bonus_results, all
`*_pools` / `*_passes` / `*_pool_levels`, audit_log, engine_runs, shipments, retail customers
attributed via `customers.distributor_id` / `orders.attributed_distributor_id` (third parties).

## File changes

| # | Path (under `app/`) | New/Mod | Summary |
|---|---|---|---|
| 1 | `app/Modules/Identity/Database/Migrations/2026_11_23_000001_add_erased_at_to_distributors.php` | New | `datetime('erased_at', 3)->nullable()->after('reregistration_allowed_from')->index()` |
| 2 | `app/Modules/Identity/Database/Migrations/2026_11_23_000002_add_erased_to_users_closure_type.php` | New | MySQL: `ALTER TABLE users MODIFY closure_type ENUM('cooling_off_cancellation','admin_termination','consent_withdrawn','erased') NULL`; down() reverts (refuse if rows have 'erased'). Skip on sqlite. |
| 3 | `app/Modules/Identity/Models/Distributor.php` | Mod | `erased_at` → `'datetime'` cast + `@property`; add `isErased(): bool`. Not fillable. |
| 4 | `app/Modules/Admin/Services/Erasure/PartnerErasureTableMap.php` | New | `final class` with `public const DELETE`, `ANONYMISE`, `KEEP` (table-name lists exactly per the tables above). Single source for the coverage test. |
| 5 | `app/Modules/Admin/Services/Erasure/PartnerErasurePreview.php` | New | readonly DTO: `targetId`, `targetAdn`, `targetName`, `members` (list of `{id, adn, name, depth, leg:'L'|'R'|null (which leg of the TARGET), side:'L'|'R'|null (own side under its parent), parentAdn, isSpouse}`), `leftAdns`, `rightAdns`, `blockers` (list of `{code, message}`), `counts` (orders, invoices, kyc docs, wallet entries), `fingerprint`, `canErase(): bool`. |
| 6 | `app/Modules/Admin/Services/Erasure/PartnerErasureService.php` | New | `preview(string $adn): PartnerErasurePreview`; `execute(string $adn, string $fingerprint, User $actor): PartnerErasureResult` (see detail). |
| 7 | `app/Modules/Admin/Services/Erasure/PartnerErasureResult.php` | New | readonly DTO: `erasedCount`, `filesDeleted`, `filesFailed`, `backupFile ?string`. |
| 8 | `app/Modules/Admin/Services/Erasure/PayloadRedactor.php` | New | `redact(array): array`, recursive, key list above. |
| 9 | `app/Modules/Genealogy/Services/PlacementEngine.php` | Mod | Inside the locked section, after the actual `$parentId` is resolved (spillover included) and before the insert: `SELECT id FROM distributors WHERE id IN (?,?) AND erased_at IS NOT NULL FOR SHARE` on `[$parentId, $in->sponsorId]`. If any are found, throw `CrossLinePlacementError` ("placement or sponsor no longer exists"). |
| 10 | `app/Modules/Compensation/Services/PayoutService.php` | Mod | Both roster queries (weekly ~L220, monthly ~L347): add `->whereNotIn('distributor_id', fn ($q) => $q->select('id')->from('distributors')->whereNotNull('erased_at'))`. |
| 11 | `app/Modules/Admin/Http/Controllers/AdminPartnerErasureController.php` | New | `form`, `preview`, `destroy` (see detail). |
| 12 | `app/routes/web.php` | Mod | Directly after line 309 (`distributors.index`): `Route::get('/distributors/erase', …form)->name('distributors.erase.form'); Route::post('/distributors/erase/preview', …preview)->name('distributors.erase.preview')->middleware('throttle:20,1'); Route::post('/distributors/erase', …destroy)->name('distributors.erase.destroy')->middleware('throttle:10,1');` No `role:developer` middleware (it would 403); the controller 404s. |
| 13 | `app/Modules/Admin/Http/Controllers/AdminDistributorController.php` | Mod | `index()`: add `->whereNull('distributors.erased_at')` to the register query and to `$statusCounts`; same in the Rule 3(g) export query and in `show()`/`edit()` lookups (erased → 404). Change only those query builders. |
| 14 | `resources/views/admin/distributors/index.blade.php` | Mod | In the last `<td>` (actions, ~L114), inside `@developer … @enddeveloper`: a "Delete" link to `route('admin.distributors.erase.form', ['adn' => $d->adn])` (GET prefill only, harmless; text-red, `<x-lucide-trash-2>`). Plus a page-header link "Delete a partner" inside `@developer`. |
| 15 | `resources/views/admin/distributors/erase/form.blade.php` | New | Step 1: APN input (`old('adn', request('adn'))`), POST to preview. Warning box: permanent, the whole downline, no undo, no record. |
| 16 | `resources/views/admin/distributors/erase/preview.blade.php` | New | Step 2: target card; summary "1 + N partners"; two columns **Left Genos (n)** / **Right Genos (n)** listing every APN with depth + own side + parent APN; spouse rows marked; record counts; blockers in a red box (button disabled if any). The form posts `adn` (hidden), `fingerprint` (hidden) and `confirm_adn` (text input "Type the APN again to confirm"). Uses `<x-confirm-modal>` with `data-confirm-title="Permanently delete APN {adn}?"`, `data-confirm` = "APN {adn} and its {N} children will be permanently deleted. Left: {first 20 APNs…(+k more)}. Right: {…}", `data-confirm-impact` = "Personal data, KYC, BV and ranks are removed. Orders, invoices and wallet rows stay without personal details. This cannot be undone." |
| 17 | `tests/Feature/Admin/PartnerErasureServiceTest.php` | New | See test plan. |
| 18 | `tests/Feature/Admin/PartnerErasureTableMapCoverageTest.php` | New | Coverage test. |
| 19 | `tests/Feature/Admin/PartnerErasureControllerTest.php` | New | Permission matrix plus the flow. |
| 20 | `tests/Browser/partner-erasure.spec.js` | New | Playwright. |
| 21 | `tests/Feature/Genealogy/…PlacementEngine…` (existing file) | Mod | Add one case: placement under an erased parent throws. |

### Detail: `PartnerErasureService`

```php
final class PartnerErasureService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DatabaseBackupService $backups,
        private readonly PayloadRedactor $redactor,
    ) {}

    public function preview(string $adn): PartnerErasurePreview;          // read-only, no locks
    public function execute(string $adn, string $fingerprint, User $actor): PartnerErasureResult;
}
```

`execute()`:
1. `if (! $actor->hasRole('developer')) throw new AuthorizationException;`
2. `if (EngineScheduleWindow::isActiveNow())` → `PartnerErasureRefused` (new exception in the same folder, carrying the blocker list).
3. `return $this->backups->exclusively(function () {...})`. Inside:
   a. `if (app()->isProduction()) $backup = $this->backups->create('manual');` (throws → abort, nothing erased).
   b. `$this->db->transaction(function () {...})`:
      - resolve target by adn; `lockForUpdate()` on all S distributor rows, ordered by id; recompute S; compare fingerprint (`hash_equals`), else refuse "tree changed".
      - recompute blockers; refuse if any.
      - collect file keys (kyc prefixes, s3 prefixes, request/ADC/ticket/offline-proof keys) into an array.
      - capture original emails of U (for password_reset_tokens).
      - run DELETE rows (children before parents, chunk `whereIn` by 1000 ids), then ANONYMISE updates; JSON redaction via chunked `select id, payload` → `update`.
      - final guard: `distributors where placement_parent_id in S and id not in S` must be empty, and `genealogy_closure where descendant_id in S` must be empty, else throw (rollback) "Someone joined under this partner during the delete — try again".
   c. After commit: delete the collected files, best-effort; count deleted/failed. **No `Log::`, no `AuditLog`, no events.**
4. Return the result.

Leg computation (preview): for each member m ≠ target, `leg` = side of the target's direct child on m's path: join `genealogy_closure gc` (ancestor = direct L/R child of target, descendant = m). The direct children come from `distributors where placement_parent_id = target and placement_side in (L,R)`. Spouse rows not in the tree get `leg = null` and are listed under "Couple partner".

### Detail: `AdminPartnerErasureController`

- `private function ensureDeveloper(Request $r): void { abort_unless($r->user()?->hasRole('developer') === true, 404); }` as the first line of each action.
- `form(Request)` → view `admin.distributors.erase.form`.
- `preview(Request)`: validate `adn` `required|string|max:16|regex:/^\d{9}$/`; `$service->preview()`; not found → back with error "No partner with APN {adn}". → view preview.
- `destroy(Request)`: validate `adn` (same rule), `fingerprint` `required|string|size:64`, `confirm_adn` `required|same:adn` (message "The APN you typed again does not match."); `set_time_limit(600)`; call `execute`; on `PartnerErasureRefused` → back to preview (re-render) with the messages; success → redirect `admin.distributors.index` with flash "APN {adn} and {n-1} partners below it were deleted." (+ "{k} files could not be removed from storage" if `filesFailed > 0`).

## Slice 1 outcome (2026-11-23) → adjustments for Slice 2

- `preview()` and `execute()` throw `PartnerErasureRefused` (codes `not_found`, `already_erased`) instead of returning a DTO for an unknown or erased APN. The controller catches it in `preview()` and returns to the form with the first message as the `adn` error. In `destroy()`, re-render the preview page when the target still resolves, else go back to the form with the messages.
- `sessions` is not a DB table (sessions live in Redis), so the erase cannot delete them. **Slice 2 adds** `\Illuminate\Session\Middleware\AuthenticateSession::class` to the web group in `bootstrap/app.php` (`$middleware->web(append: [SecurityHeaders::class, AuthenticateSession::class])`). The erase rewrites `password_hash`, so any live session of an erased user is logged out on its next request. Verify existing auth/login tests still pass; if `AuthenticateSession` breaks them (e.g. the `password_hash` column vs `getAuthPassword()`), STOP and report.
- `set_time_limit(600)` belongs in the controller `destroy()`.
- Dev DB: the two new migrations ran via `--path`. The unrelated pending `2026_11_21_100000_drop_pulse_tables` is left unapplied. Do NOT run a plain `migrate` on dev.

## Slices

| Slice | Title | Files | Depends on | Model |
|---|---|---|---|---|
| 1 | Schema, service, engine guards, service tests | 1–10, 17, 18, 21 | — | implementer **opus** (data erasure on live data, concurrency) |
| 2 | Controller, routes, views, list filter, controller tests | 11–16, 19 | 1 | implementer **sonnet** |
| 3 | Playwright + affected-suite run | 20 + run | 2 | qa **sonnet**, effort medium |
| — | Compliance-officer review of the diff; main session diff review | — | 3 | compliance-officer |

## Test plan

**Mandatory in every test:** `Storage::fake('kyc')`, `Storage::fake('s3')` and the
request/ADC/ticket/offline disks (see memory `tests_hit_real_s3_kyc_wipe_2026_10_18`).
Run against `arovolife_test` only, with the `-e` overrides from `docs/local-dev-environment.md`.

Service (`PartnerErasureServiceTest`). Fixture: root → A (L) → A's children B (L), C (R) → B's child D; a sibling X (R of root), sponsor of A = root.
1. Erase A: S = {A,B,C,D}; preview legs B=L, C=R, D=L; fingerprint stable.
2. After execute: no closure rows for S; root's L slot free (a new placement under root L succeeds); root's team counts exclude S; X untouched.
3. Every DELETE table has zero rows for S/U/C; every ANONYMISE row has the placeholders; KYC files gone from the fake disk; `users` login impossible (email changed, `Hash::check` old password fails).
4. Kept: wallet_ledger_entries, payout_line_items, invoices, orders rows still exist (same count); root's `group_bv_credits` and `mentorship_bonus_results` (sponsee = A) still exist.
5. audit_log row count before == after; `compliance:verify-audit-log` exit 0.
6. Each blocker (1–8) refuses and erases nothing.
7. Fingerprint mismatch (add a child to D after preview) refuses.
8. Non-developer actor → `AuthorizationException`.
9. Couple: erasing the primary also erases the spouse row.
10. Production branch: mock `DatabaseBackupService::create` throwing → nothing erased.
11. PayoutService weekly roster excludes an erased distributor that has unswept positive wallet entries.
12. PlacementEngine: placement under an erased parent throws `CrossLinePlacementError`.

Coverage (`PartnerErasureTableMapCoverageTest`): every table that has a column matching
`distributor_id|user_id|customer_id|order_id|ancestor_id|descendant_id|sponsor_id|notifiable_id|model_id`
is in exactly one of DELETE / ANONYMISE / KEEP. (The KEEP list includes admin-actor-only tables
like `stock_movements`.)

Controller (`PartnerErasureControllerTest`): each matrix row in both directions
(developer 200/302, admin/admin-finance/distributor 404 on all three routes); `confirm_adn`
mismatch → validation error, nothing erased; the Delete link renders only for the developer.

Playwright (`partner-erasure.spec.js`, E2E stack `:8085`, `ARV_E2E=1`, workers 1):
developer types the APN → sees Left/Right lists with child APNs → retypes the APN → modal shows
the APN and children → confirms → list no longer shows them. Admin: no Delete link; direct URL 404.
Blocker case: open order → button disabled, blocker text shown.

## Acceptance criteria

- [ ] `php artisan migrate` (dev, forward-only) applies both migrations.
- [ ] All tests above pass on `arovolife_test`: `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db -e DB_PORT=3306 -e DB_USERNAME=arovolife -e DB_PASSWORD=secret arovolife-app php artisan test --compact tests/Feature/Admin/PartnerErasure* tests/Feature/Genealogy tests/Feature/Compensation/Payout*`
- [ ] Existing suites touching PlacementEngine, PayoutService and AdminDistributorController pass.
- [ ] `./vendor/bin/pint --dirty` clean; Larastan level 7 clean.
- [ ] `npm run build`; Playwright spec green.
- [ ] grep of the new code: no `AuditLog`, `Log::`, `event(`, `::dispatch(`.
- [ ] Compliance-officer review recorded; commit trailer `Compliance-Review: compliance-officer`.
- [ ] Not deployed to staging/prod without explicit approval.

## Known residuals (tell the client)

- **PAN is erased even when TDS was deducted** (user decision 2026-11-23, after warning): a later quarterly TDS return can't name these partners' PAN; no pre-erase tax export is built.
- DB backups and Cloudways snapshots keep the data until they expire.
- Past payout bank files (encrypted) still hold name/account for past payouts.
- Razorpay/Shiprocket/SMS-provider records live outside the platform.
- A staging recompute replay after an erase rebuilds upline BV without the erased BV (prod has no recompute).
- `compliance:verify-audit-log` stays clean, because no audit rows are touched. The erased people's old audit rows remain, as the user decided.

## Outcome (2026-11-23)

Built on `feat/partner-subtree-erasure`: developer-only preview → retype APN → erase, with blockers, tombstones, anonymised money/tax rows, S3 folder sweep, production pre-erase backup, and a "Release stuck lock" button on Database Backups.

Changes after compliance review:
- New blockers: `cooling_off_open` (order inside its 30-day window) and `refund_unpaid` (refund `created` or `failed`).
- Abuse reports filed by outsiders are kept with the reported message, as evidence.
- Bucket guard: local may delete from `-local` or `-staging`; staging only `-staging`; production only `-prod`.

Owner-accepted risks: R-129 (PAN vs TDS), R-130 (no audit row; grievance tickets deleted), R-131 (immediate re-registration), R-132 (local deletes from staging bucket).

**Before erasing anyone in production who had TDS deducted:** confirm the current quarter's 26Q has been filed, or export their PAN and TDS rows to the CA first (R-129).

Residuals: database backups and already-generated bank files still contain the erased data until they age out; prod S3 keeps deleted files as noncurrent versions for 30 days (`expire-noncurrent-versions-30d`); a plain partner gets 404 (not 403) on the erase routes by design.
