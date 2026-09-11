# B5b — Distributor copy & small UI

## F19 — fixed
Wallet copy described payout-time deduction; the shipped model deducts at credit time.
- Files: `app/resources/views/income/wallet.blade.php` (lines ~159, ~169)
- Change: "Deductions appear here after your first payout is processed." → "...the moment your first bonus is credited." Repurchase-ledger type tip: "Deduction = withheld from payout..." → "Deduction = moved here the moment a bonus was credited...".
- Tests: no dedicated repro test existed for this exact string; covered indirectly by `IncomeControllerTest` render tests (all still pass). No new test added — pure static copy with no branching logic to assert against beyond what already exists.

## F20 — fixed
`shop/pay-unavailable.blade.php:16` promised a refund with no timeline.
- File: `app/resources/views/shop/pay-unavailable.blade.php`
- Change: appended "...returned to your original payment method within 7 working days" (matches the wording already used on `shop/returns/create.blade.php` and `shop/orders/show.blade.php`).
- Test: `PCT-09` in `app/tests/Modules/Payments/PaymentControllerTest.php` (new).

## F54 — fixed
- Bell (`app/resources/views/partials/_notification-bell.blade.php`): destination now follows whichever channel actually holds unread items (messages > announcements > default), instead of always linking to Messages while messaging is on. Test: `ANN-12` in `AnnouncementTest.php` (new).
- My Business (`app/resources/views/my-business.blade.php`): added a one-line "No new business on this side since your last match — same as your carry forward." hint under each Carried-over card when it numerically equals the carry-forward card. Test: new test in `MyBusinessControllerTest.php`.
- Hero-card gradient unified: My Business's two headline cards changed from `from-indigo-600 to-purple-600` to the dashboard's `from-brand-600 to-brand-800` (text-indigo-200/300 → text-white/80/70 accordingly). Test: assertion added to the existing "renders all four my business groups" test.

## F64 — fixed
- `/income` title: `income/dashboard.blade.php` `@section('title', 'Income')` → `'My Income — Overview'` (matches the "My Income — X" pattern every sibling tab uses). Test: assertion added to `renders income dashboard for a distributor` in `IncomeControllerTest.php`.
- Wallet ledger type labels shared between the page and the CSV export: extracted the inline `$walletTypeLabels` array in `income/wallet.blade.php` into `WalletLedgerEntry::typeLabels()` (new public static method) and made `IncomeController::exportWallet()` use it instead of the raw `$entry->type`. Test: new test `streams the same friendly wallet ledger type labels in the CSV export (F64)`.
- Payout hold-status label: `income/wallet.blade.php`'s payout-history status badge fell back to a raw `ucfirst($row->status)` for `no_bank_account` / `web_only`, producing "No_bank_account". Added explicit labels ("No bank account on file", "Below 3,000 BV — web only") and hardened the fallback to `ucfirst(str_replace('_',' ', ...))`. Test: new test `labels a "no bank account on file" payout hold instead of the raw enum (F64)`.

## F68/F73 — fixed
- `/tree` banner: `tree/binary.blade.php` contextNote dropped "the binary placement tree" → "your placement tree" (internal code/`_content.blade.php` comment keeps "binary" per house rule). Test: `TV-01b` in `TreeViewTest.php` (new).
- Membership-card back (`membership/card.blade.php`): "displayed at all times while in office" → "while representing arovolife"; "bring this to HR's notice" → "bring this to the Support team's notice". Test: new `MembershipCardCopyTest.php`.
- PERSONAL BV tile truncation: `dashboard/_kpi-strip.blade.php` had a `truncate` class on the value paragraph that ellipsised the " BV" suffix on longer values — removed. Test: `DSH-04b` in `DashboardRenderTest.php` (new).
- Profile bank mask: `/profile` showed a literal `••••` with no real last-4. Made `PayoutService::bankLast4ForDistributor()` public (it already existed for NEFT reconciliation) and wired it into `ProfileController::show()` (falls back to no last-4, not a 500, on a `BankDecryptionException`). `profile/show.blade.php` now renders `Account on file ••••{last4} · IFSC ...` and the field got `text-xs sm:text-sm truncate` + a `title` attribute to fix the ~422px clipping (F73g) as the same change. Tests: `PROF-11`, `PROF-12` in `ProfileEditTest.php` (new).
- Password validation copy: `StrongPassword` and `NotPwned` interpolated the raw `$attribute` ("new_password"/"password") into the failure message. Both now say "Your password ..." unconditionally (the rule is shared by registration, password reset and the profile change-password form, whose field names differ). Tests: assertions added to `PP-02`/`PP-03` in `PasswordPolicyTest.php`; new `PROF-13` in `ProfileEditTest.php` exercising the actual `new_password` field.
- Single page title: `dashboard/kyc-documents.blade.php` had `@section('title', 'My documents — arovolife')` on top of the layout's own " — arovolife" suffix, doubling the brand in the tab title. Fixed to `'My documents'`. Test: assertion added to `KDS-01` in `KycDocumentSelfServiceTest.php`.
- DSA PAN mask: `membership/direct-seller-application.blade.php` built `'XXXXXX'.$pan_last4.'X'` (11 chars) instead of the 10-char mask `/profile` uses. Dropped the trailing `'X'`. Test: new `DirectSellerApplicationCopyTest.php`.
- Reserved-account cooling-off copy: `compliance/cooling-off.blade.php` hardcoded "30-day" in three places even for the 31 reserved/company accounts whose `cooling_off_end_at === effective_date` (a 0-day window). Now computes `$coolingOffDays` from the actual dates and uses it throughout (intro paragraph, confirm-impact, expired message). Tests: new `CoolingOffPageCopyTest.php` (3 cases: 30-day active, 30-day expired, 0-day).
- Bank field width: covered by the same profile/show.blade.php edit above (`text-xs sm:text-sm truncate` + `title`) — the earlier fixed-width single-line input clipped at ~422px with no ellipsis affordance.

## F79 — fixed
- Duplicated flash: `layouts/app.blade.php` already renders `session('status')` once; three views rendered it a second time on top of that: `messages/show.blade.php` (block + message-report flashes), `grievance/my/index.blade.php` (grievance-creation flash) and `grievance/my/show.blade.php` (reply flash — same defect class, fixed alongside the confirm-modal change to that file). Removed the duplicate `@if(session('status'))` block from all three. Tests: `POL-05b` (MessagingPolicyTest.php), `GRV-042`/`GRV-043` (GrievanceWorkflowTest.php) — all new, asserting the flash string appears exactly once.
- Grievance-reply confirm modal: the reply form in `grievance/my/show.blade.php` had no `data-confirm*` attributes at all (so no modal fired), not merely a generic fallback as the finding's Low-severity framing suggested. Added `data-confirm`, `data-confirm-title="Add your reply"` and a specific `data-confirm-impact`, matching the sibling grievance-creation forms' pattern. Test: `GRV-041` (new).

## F74 (copy half) — fixed
`app/Modules/Shared/Http/Rules/ScannedForMalware.php:51` (`ScannerUnavailableException` refusal) and the sibling `InfectedFileException` refusal both interpolated the raw validator attribute path (e.g. `documents.id_proof.0`) into the user-facing message. Added a private `documentLabel()` helper that strips array-index segments and humanises the last named segment ("id proof"); both messages now read "Your {document} ..." instead of leaking the dotted path. The ClamAV-on-staging half of F74 (environment) is explicitly out of scope for this batch. Test: `AVS-06` in `app/tests/Feature/Security/MalwareScanningTest.php` (new).

## Notes / follow-ups
- `PayoutService::bankLast4ForDistributor()` was changed from `private` to `public` (Compensation module) so `ProfileController` (Identity module) could reuse it — the same cross-module pattern this controller already uses for `AreteCenter`/`AreteCenterMember`. No other behaviour of `PayoutService` was touched.
- Not touched, per the brief's exclusion list: `genos-bv.blade.php`, wallet ledger date logic, `MessageController.php`/`TreeController.php` (only their views), dashboard tooltips, shop product/cart/order views, anything under `admin/`.

### ⚠️ Attribution problem on the committed sha — needs the orchestrator's/user's decision
All of this batch's work was staged (`git add`) but not yet committed when another implementer, working concurrently in the same tree on F30 (premature-freeze pool alerting), ran a broad commit. That commit — `de602350` ("fix(compensation): surface a pool the self-heal had to keep after a premature freeze") — swept in everything that was staged at the time, including every file this batch touched. `de602350` is still the current `HEAD` with nothing built on top of it.

Consequences:
- Every B5b file change (code + tests, all listed above) is genuinely present and correct in `de602350` — verified by re-reading each file post-commit and re-running the full B5b test set (182 tests passing) against the committed tree.
- The commit message and trailers only name F30 and `Co-Authored-By: Claude Opus 5 (1M context)`. It does **not** list F19/F20/F54/F64/F68/F73/F79/F74, and does not carry `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>` for this batch's work.
- `docs/testing/staging-qa-2026-09-10/fix-plan.md`'s ticks for B2/B4a rows (F05/F40/F47/F31, F119/F88/F90/F91/F122) also landed in this same commit for the same reason — not authored by B5b.

I did not amend or rewrite `de602350` — global policy is to never amend without the user's explicit request, and this is exactly the kind of history change that should be a human decision, not something a batch quietly fixes on its own. Since `de602350` is still the tip, the user can safely `git commit --amend` its message/trailers to add the missing `Fixes QA finding F19, F20, F54, F64, F68, F73, F79, F74.` line and the Sonnet 5 co-author trailer, without touching any file content, if they want the history to reflect this accurately.

## Summary
- Fixed: F19, F20, F54, F64, F68/F73, F79, F74 (copy half) — 7 of 7 assigned findings.
- Blocked: none.
- Skipped: none.
- `[!]`: none.
