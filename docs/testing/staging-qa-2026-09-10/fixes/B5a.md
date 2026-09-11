# B5a — Distributor UI logic

## F25 / F75 — fixed

- Files: `app/app/Modules/Messaging/Http/Controllers/MessageController.php`, `app/tests/Modules/Messaging/MessagingPolicyTest.php`
- Commit: `8c14c1a9`
- `show()` now aborts 404 unless `MessageService::canMessage()` passes (the same audience rule the send path uses) or a message already exists between the two parties. The second arm keeps a thread readable after a block, or after the audience setting changes.
- Tests: `POL-05c` (stranger → 404), `POL-05d` (walks three stranger ids + a non-existent id; asserts neither name nor email appears, and the viewer's own line still opens), `POL-05e` (blocked-but-existing thread still opens). `POL-05b` had been written against a stranger pair and expected 200 — re-pointed at a sponsor/downline pair, which is what it was actually testing (flash rendering).

## F67 — fixed

- Files: `app/app/Modules/Genealogy/Http/Controllers/TreeController.php`, `app/resources/views/tree/_content.blade.php`, `_binary-node.blade.php`, `_sponsorship-node.blade.php`, `binary.blade.php`, `sponsorship.blade.php`
- Commit: `93ac8412`
- `binary()` / `sponsorship()` now pass `viewerId` (the auth distributor) alongside `self` (the canvas root). `_content` defaults `viewerId` to the root, so the admin canvas — which passes none — is unchanged. The node partials compare each node against `viewerId`, so on a re-rooted canvas no card wears the "You" ribbon (the viewer is an ancestor, not drawn). The binary and sponsorship pages name the root in the heading and the banner: "Showing {name}'s placement and descendants up to N levels deep."
- Test: `TV-04b` in `tests/Modules/Genealogy/TreeViewTest.php` — counts the `>You</p>` ribbon (1 on own tree, 0 re-rooted) and asserts both banner variants.
- Scope note: the sponsorship page had the identical defect from the same shared partial, so it is fixed in the same change. Its `contextNote` ("This page lists everyone you personally introduced…") still reads first-person when re-rooted — left alone, flagged below.

## F78 — [!] not reproducible in code

- Files: `app/tests/Modules/Identity/DistributorRequestTest.php` (test only)
- Commit: `2a54f78a`
- The finding says the store path lacks `withInput()`. It does not: `$request->validate()` flashes input on a validator refusal, `back()->withInput()` covers the service refusal, and `resources/views/my/requests/create.blade.php` reads all nine fields back through `old()` — including in `1afbf26d`, the commit staging was running. Reproduced the exact staging case (scanner-unavailable refusal, file attached, `UploadedFile` stripped from the flashed input) and it retains both the name and the reason.
- Test: `a refused submit keeps everything the distributor typed (F78)` — validator refusal, scanner-unavailable refusal with a file, and a duplicate-open-request service refusal; all three assert `assertSessionHasInput`, and the re-rendered form is asserted to show both values.
- Follow-up for the QA re-run: two candidate causes that are NOT a missing `withInput()` — (a) the type picker is a separate GET form, so changing the request type navigates away and discards anything already typed in the POST form below it; (b) flashed input survives exactly one request, so any second session-carrying GET between the 302 and the render (prefetch, double navigation) consumes it while the error bag is re-rendered from the same flash. Needs a browser repro with the network log before any code change.

## F61 / F62 — fixed

- Files: `app/resources/views/income/genos-bv.blade.php`, `income/genos-ledger.blade.php`, `income/mentorship.blade.php`, `app/app/Modules/Compensation/Services/DTOs/GenosLedgerDay.php`, `Services/GenosBvLedgerService.php`
- Commit: `29da50e1`
- F61: the "Weaker side" cell no longer recomputes `left <= right`. Both labels derive from the stored `power_side_after` (weaker = the side that is not the power side, the same derivation the admin `gsb-calculation` report uses), and the cell shows the stored `weaker_bv_paise` under the label, so the figure the slab was matched on is visible rather than inferred from two leg numbers that exclude carry-forward. Header tooltip rewritten to say the comparison happens after carry-forward.
- F62: "Power CF after" now shows "Left group" / "Right group" under the figure; the Genos Ledger's cut-off row spells out `power (Left)` instead of `power (L)`; the ledger gained a personal-BV top-up line (source `gsb_personal_bv_topups`, plumbed through `GenosLedgerDay::$topups`, which defaults to `[]` so the admin tab that shares the DTO is untouched) and the "No Genos BV added this day" empty line now accounts for it; the Mentorship table gained a Date column (`cutoff_date`) beside its date filter.
- Tests in `tests/Modules/Compensation/IncomeControllerTest.php`: `shows the stored weaker side and a Left/Right power label on the genos bv page, never a recompute (F61/F62)` (fixture is the staging day — Left leg 0, Left the stored power side, so a recompute and the stored result disagree), `gives the personal-BV top-up its own genos ledger line instead of "No Genos BV added this day" (F62)`, `dates every mentorship bonus row on the distributor page (F62)`.

## F63 — fixed

- Files: `app/resources/views/income/wallet.blade.php`, `app/app/Modules/Compensation/Http/Controllers/IncomeController.php`, `Services/IncomeOverviewService.php`
- Commit: `68499ef4`
- Both wallet ledgers are dated by `earned_on ?? bonus_month ?? created_at`, with the write date kept underneath as "credited {date}" / "recorded {date}" only when the two differ. The main ledger gains a "Bonus month" column and a "Paid in batch" column (`swept_by_payout_batch_id` resolved through a new `IncomeOverviewService::payoutBatchLabels()` — one query for the page, keyed by batch id, no N+1). The CSV export carries the same seven columns in the same order.
- Deliberately unchanged: row order and the running balance still follow `created_at`, because the running balance is a projection of the order entries were written. Re-sorting by `earned_on` would print a balance sequence that never existed.
- Test: `dates the wallet ledger by when the money was earned, and names its bonus month and payout batch (F63)` — the staging case (06 Sep cut-off written 00:20 on 07 Sep) plus a swept monthly credit; asserts page and CSV.

## F53 — fixed

- Files: `app/app/Modules/Content/Models/ContentPage.php`, `app/resources/views/income/dashboard.blade.php`, `income/wallet.blade.php`, `dashboard/_income-snapshot.blade.php`, `my-business.blade.php`
- Commit: `c56d428a`
- New `ContentPage::isSlugPublished(string $slug)` — the same `slug + status = published` test `PublicContentPageController` applies. Every distributor surface that stated the cadence now guards on `isSlugPublished('compensation')` and falls back to the already-published wording: "Weekly income is transferred in the Tuesday payout run", "monthly bonuses transfer … in the monthly payout run". Gated spots: income dashboard page note (both GSB on/off variants) + next-weekly and next-monthly tooltips; wallet page note, next-payout tooltip, "Covers earnings through {date}" line, and the payout-history empty state; dashboard income-snapshot next-weekly and next-monthly tooltips plus its "Covers earnings through" line; My Business wallet-balance tooltip.
- Tests: `keeps the payout-week and 8th-of-month cadence off every distributor surface while the compensation page is unpublished (F53/R-75)` — walks all three surfaces with no content row, then with a *draft* row (a draft is not a publication), then published. Two existing tests (`renders income dashboard for a distributor`, `renders wallet page with empty state`, `renders all four my business groups …`) now publish the page first via a new `publishCompensationPage()` helper, since they were asserting the gated copy.
- Note for whoever re-seeds: this is a display gate only. It does not change `ContentPageSeeder` — F17 (B7) covers the seeder publishing `compensation` wholesale.
- Blade trap worth knowing: `@if` / `@else` / `@endif` are only recognised when not preceded by a word character (`\B@`), so `...payout run@if($x)` silently fails to compile and breaks the view. Inline prose conditionals in these views use `{{ $x ? '…' : '…' }}` instead.

## F69 (suggest endpoint only) — fixed

- Files: `app/app/Modules/Genealogy/Http/Controllers/TreeController.php`
- Commit: `0daf1e99`
- `/tree/suggest` returned `adn + id + name + email + phone` for every subtree match, so a partial-email query harvested a whole downline's contact details. The response is now `adn + id + name`. The match predicate is untouched, so a distributor can still find someone by an email or mobile they already know. The eager load narrowed to `user:id,full_name`.
- Per the client decision relayed 2026-09-11, the downline ADN list in the Genos ledger stays as is (F65 = keep); only the suggest endpoint was stripped.
- The shared dropdown JS in `tree/_content.blade.php` renders the contact sub-line only when the payload carries one, so nothing was changed there and the admin typeahead (a different controller) is unaffected.
- Tests: `TSG-01` tightened to `['adn', 'id', 'name']` with explicit `assertDontSee` on both members' email and phone; new `TSG-01b` proves an email query and a bare 10-digit mobile query still find the member without echoing either value.

## Summary

- **Fixed (7 of 8):** F25/F75, F67, F61/F62, F63, F53, F69 (suggest endpoint).
- **[!] 1:** F78 — not reproducible in code. The distributor-request store path already flashes input on both refusal routes and the form reads all nine fields back through `old()`, including in `1afbf26d`, the commit staging was running. Reproduced the exact staging case (scanner-unavailable refusal with a file attached) and the name and reason both survive. Pinned with a regression test rather than changed blind; two non-`withInput()` candidate causes recorded above for the QA re-run (the type-picker GET navigating away; a second session-carrying GET consuming the one-request flash).
- Commits: `8c14c1a9`, `93ac8412`, `2a54f78a`, `29da50e1`, `68499ef4`, `c56d428a`, `0daf1e99`.
- Scope notes for the reviewer: (a) F67's fix also corrects the identical "You" ribbon and banner defect on `/tree/sponsorship`, which shares the partials — its `contextNote` still reads first-person when re-rooted and was left alone; (b) F63 adds a `Bonus Month` / `Paid In Batch` / `Credited On` trio to the wallet CSV export as well as the page, so the two cannot disagree; (c) no admin code, payout service, commerce view or `ProfileController` was touched.
