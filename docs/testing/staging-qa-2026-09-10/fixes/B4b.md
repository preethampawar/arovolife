# B4b — Admin KYC, content, messaging

Branch `fix/staging-qa-2026-09-10`.

## F106 — fixed

KYC document previews go through the audited streaming route.

- `app/resources/views/admin/kyc/show.blade.php` — dropped the per-document
  `Storage::disk('kyc')->temporaryUrl(..., 30 min)` block; `<img src>` is now
  `route('admin.kyc.document', [...])`, the same route the "Open full size"
  link already used. Every render is therefore re-checked against the admin
  session + `kyc.review` and writes `admin.kyc.document_viewed`.
- The route's S3 branch (302 to a 15-minute signed URL, kept because streaming
  fails on Cloudways PHP-FPM) is unchanged — but the URL is now issued per
  audited request rather than embedded in the page.
- Tests: `AKR-07` (img src is the audited route, no `X-Amz-Signature` on the
  page, a view writes exactly one audit row for the right actor), `AKR-08`
  (guest GET redirects to login and writes no audit row).
- Commit `7d7377d4`.

## F110 — fixed

- `AdminKycController::index()` — third queue tab `?tab=flagged` +
  `$flaggedCount`, counting distributors with `kyc_documents.flagged_at IS NOT
  NULL` (the re-upload clears the column, so non-null *is* unresolved). Tab
  validation now whitelists the three tabs. `$flaggedIds` drives an "Awaiting
  re-upload" badge on rows in the pending tab too.
- `resources/views/admin/kyc/index.blade.php` — new tab, badge, empty state;
  the old tab relabelled "Rejected — awaiting resubmission" (it counts
  `users.status='rejected'`, which is a different thing from a flag).
- `AdminKycController::show()` — passes `$hasFlaggedDocument` (self + spouse);
  the Approve form is replaced by an "Approval on hold" panel while true.
- `ApproveKycSubmission` — throws the new `KycHasFlaggedDocumentsError` when any
  document in the couple unit is flagged. The check sits after the existing
  `lockForUpdate()` fetch, so a concurrent flag either lands before it or waits.
  `purgeIdNumbersAndFiles()` untouched. Controller renders the reason via
  `withErrors('kyc')`.
- Tests: `AKR-09` (flagged tab lists only the parked submission; pending tab
  shows the badge), `AKR-10` (service throws, nothing activated/verified/purged,
  POST /approve returns a `kyc` error, page shows "Approval on hold" and no
  approve form).
- Help: `resources/help/kyc-review-guide.md` — the unresolved-flag rules and the
  "every view is logged / preview goes through the audited route" note.
- Commit `7d7377d4`.

## F117 — fixed

- `ContentPageRequest::prepareForValidation()` — a submitted-but-blank
  `sort_order` normalises to 0 before validation. The column is
  `unsignedSmallInteger default 0` and NOT NULL, so `nullable` was passing the
  empty string through as NULL and the insert 500'd. Guarded with
  `$this->has()` so a form that doesn't render the FAQ fields (library flag
  off) cannot reset a stored order to 0. A non-numeric value still 422s.
- Trix vendored: `npm i trix` (2.1.19), new Vite entry `resources/js/trix.js`
  (`import 'trix'` + `trix/dist/trix.css`), added to `vite.config.js` input.
  `resources/views/admin/content/_form.blade.php` now `@vite`s it instead of
  the two unpkg tags, and carries a boot check — module scripts run before
  `DOMContentLoaded`, so if `customElements.get('trix-editor')` is still
  undefined by then the page fills in a red notice under the Body field.
  Build verified (`npm run build`, separate 195 kB chunk). `public/build` is
  gitignored, so no build output committed.
- Tests: `FAQ-11` (blank order saves as 0 with no errors — verified failing
  before the fix; `'first'` still errors), `FAQ-12` (editor page has no
  `unpkg.com`, does reference `/build/assets/trix`, and carries the error
  element).
- Help: `distributor-communications.md` — blank sort order means 0.
- Commit `19913b74`.
- **Follow-up (not in scope):** `resources/views/admin/catalog/products/form.blade.php`
  still loads Trix 2.1.15 JS + CSS from unpkg with the same failure mode. It is
  commerce code, excluded from this batch. Switching it is now a two-line change
  (`@vite('resources/js/trix.js')`), but its Trix-attachment upload handler
  should be re-tested when someone does it.

## F116 — fixed

- `AdminMessageReportController` — eager-loads `reporter.distributor` and
  `message.fromUser.distributor` (index, selected columns) and the same plus
  `message.toUser.distributor` on show, so no N+1 and no extra queries per row.
- New shared component `resources/views/components/message-party.blade.php`:
  name + ADN, linked to `admin.distributors.show`. Staff accounts have no
  distributor row and render as the name alone; a null user renders `—`.
  Used in both the queue table and the report detail.
  `/admin/distributors/{id}` is open to the whole admin family, the same group
  `messaging.moderate` sits in, so the link never dead-ends in a 403.
- Test: `POL-16` — both parties given the same `full_name`, asserts both ADNs
  and both profile URLs appear on the detail page, and both ADNs in the queue.
- Help: `distributor-communications.md` — the moderator step list now mentions
  the ADN and the profile link.
- Commit `e7cab538`.

## F118 — fixed

- `AdminContentPageController::update()` — a status transition now audits as
  `content_page.published` / `content_page.archived`; a save that leaves the
  status alone stays `content_page.updated`. Both transition rows (and
  `destroy()`) carry `before_hash`/`after_hash` digests of the status plus
  `previous_status` in details.
- Announcements already logged `announcement.published` /
  `announcement.archived` with digests (`AdminAnnouncementController::transition`)
  — no change needed there.
- Test: `FAQ-13` — publish, copy-edit, archive produce exactly
  `[published, updated, archived]`, and the publish row has both digests.
  Also run on MySQL in the container (BINARY(32) digest columns).
- Commit `9f12d92a`.

## F109 — fixed

- `AdminHelpController::DOCS` — removed the `franchise-programme` entry. Its
  markdown was deleted by 59ef9bf3, so the card 404'd, and the card text still
  advertised a "3% fulfilment commission" for a programme that is parked
  (Phase 4) and whose payout is blocked pending a legal opinion.
- Test `AH-08` walks the whole `DOCS` allow-list and asserts every `file`
  exists, so the next deleted doc fails CI instead of shipping a dead card;
  also asserts the index no longer says "Franchise" or "fulfilment commission".
- Commit `bdd9028e`.
- **Staging cleanup (no code change possible):** the Pennant `features` table on
  staging still holds `App\Modules\Shared\Features\FranchiseFeature /
  __laravel_null / true`. The class no longer exists in the repo (only the
  `create_franchises_table` migration remains), so the flag console cannot show
  or clear it. It needs a manual delete of that row on staging — as does the
  scoped-row residue in F34-4. Not actionable from this branch.

## F111 — fixed

Six items, all committed together as `79e604b2`:

1. `KycApprovedNotification::via()` → `['mail', 'database']` with a `toArray()`
   payload (`kind: kyc.approved`), matching `KycDocumentFlaggedNotification`.
   Test `AKR-11`.
2. `AdminDistributorController::index()` — `q` now also matches
   `users.phone_e164` on digits only, so "9876543210", "98765 43210" and
   "+919876543210" all find the stored form. Same normalisation as
   `/admin/tree/search`; `$phoneDigits` is `\D`-stripped so it carries no LIKE
   metacharacters. Test `LF-06`.
3. `AdminDistributorController::show()` + `admin/distributors/show.blade.php` —
   a KYC card: total / verified / flagged counts with three states (no
   documents, flagged and waiting on the applicant, all verified, part
   verified), and an "Open KYC review" link shown only to holders of
   `kyc.review`. Test `LF-07`.
4. `AdminAuditLogController` — new `?actor=` filter matching actor email or
   full name, plus a filter box in the view. For a viewer who is not the
   platform-configuration role the filter excludes that role's actor ids
   entirely: those rows already render with the actor blanked, and a hit on one
   would confirm the account exists, which is what `maskPrivilegedActors`
   exists to prevent. Test `DEV-12` covers both halves.
5. Root sponsor: `show()` no longer resolves a sponsor when `sponsor_id === $id`
   (the root's self-referencing sentinel), mirroring the existing
   `placement_parent_id` guard. Test `LF-07`.
6. Flash honesty — `AdminKycController`: the flag notification is now sent
   *after* the transaction commits (a refused transport can no longer roll back
   the reviewer's decision) inside a try/catch that logs and switches the flash
   to "the re-upload notice could not be sent … contact the applicant directly".
   The success wording, and the reject/terminate wording, say the notice is
   *queued* — which is the truth for a `ShouldQueue` notification.
- Help: `kyc-review-guide.md` (queued-vs-delivered wording) and
  `admin-actions.md` (a "Reading the audit log" section for the actor filter).
- **Out of scope, same defect class:** `AdminDistributorRequestController`
  (×2), `AdminLineChangeController` and `AdminDistributorCreateController` all
  still flash "has been emailed" for queued mail. Not named in F111 and they
  belong to other batches' files.
- Not done (explicitly not in the B4b line of the fix plan): the orders/BV
  summary on the distributor record, and the `/admin/tree` 3.6 MB / 926 ms page
  weight. Both are still open parts of the F111 row.

## Summary

| Finding | State |
|---|---|
| F106 KYC previews through the audited route | fixed — `7d7377d4` |
| F110 flagged documents visible, Approve refused | fixed — `7d7377d4` |
| F117 Trix vendored + blank `sort_order` | fixed — `19913b74` |
| F116 report shows ADN + profile links | fixed — `e7cab538` |
| F118 publish/archive audit actions | fixed — `9f12d92a` |
| F109 Franchise help card removed | fixed — `bdd9028e` |
| F111 six console gaps | fixed — `79e604b2` |

7 of 7 findings fixed, 0 blocked, 0 skipped. F107 was excluded from this batch
by instruction (compliance decision pending); `ApproveKycSubmission::purgeIdNumbersAndFiles()`,
`routes/web.php`, `MessageController` and all compensation/payout/commerce code
were not touched. No new routes were added.

New/changed tests, all green on SQLite; the digest-writing path (F118) also run
on MySQL in the container:
`AKR-07`, `AKR-08`, `AKR-09`, `AKR-10`, `AKR-11`, `FAQ-11`, `FAQ-12`, `FAQ-13`,
`POL-16`, `AH-08`, `LF-06`, `LF-07`, `DEV-12`.

### `[!]` notes for the orchestrator

- **`[!]` Staging DB cleanup, not code:** the orphan `FranchiseFeature` row in
  the Pennant `features` table on staging has to be deleted by hand — see F109.
- **`[!]` Vite build required on deploy:** Trix is now a bundled asset
  (`resources/js/trix.js`). `public/build` is gitignored, so the content editor
  will 500 on `ViteException` until `npm run build` has run — and per the
  Cloudways note the server's Node is too old, so the build must be made locally
  and `public/build` rsynced.
- **`[!]` Two files were reformatted by `vendor/bin/pint --dirty` while other
  implementers had them dirty:** `app/Modules/Content/Models/ContentPage.php`
  (`self_static_accessor`) and `tests/Feature/Compensation/PayoutAdminPagesTest.php`
  (`fully_qualified_strict_types`, `ordered_imports`). Both are style-only and
  match the project's own Pint config; neither is committed by me and both are
  still in those batches' working trees. I switched to per-file `pint <path>`
  afterwards.
