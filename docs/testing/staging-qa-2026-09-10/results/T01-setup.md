# T01 — QA logins, bank details, KYC state for the 7 earning reserved accounts + probe job cleanup

Verdict: **PASS-with-notes**

Environment: staging `ahdhesuhty` (139.59.92.229), app `/home/master/applications/ahdhesuhty/public_html/app`, commit 6f114500.
Method: SSH + `php artisan tinker --execute` + read-only MySQL. No browser. No file was written on the server.

## Checks

| # | Check | Result | Evidence |
|---|-------|--------|----------|
| 1 | Password storage path identified | PASS | `users.password_hash` (+ `users.password_set_at`). App writes it with `Hash::make()` — `ProfileController.php:403`, `ResetPassword.php:62`, `RegistrationWizardController.php:426`. `User::getAuthPassword()` returns `password_hash` (`User.php:271-277`). No raw SQL used; all writes went through `Hash::make()` in tinker. |
| 2 | `QaPass!2026` set on d1 / d5 / d2 and verified | PASS | Tinker output: `d1 adn=444555666 user=2 before=$2y$12$hTmETCcg after=$2y$12$oi4aITY3 check=OK` · `d5 adn=608628172 user=6 before=$2y$12$wUZ51wCy after=$2y$12$rc6X/0ID check=OK` · `d2 adn=973708897 user=3 before=$2y$12$wnPkm5tm after=$2y$12$SAOoo5y0 check=OK`. `check=OK` is `Hash::check('QaPass!2026', $storedHash)` re-read from the DB after the update. |
| 3 | No login blockers on the 3 QA users | PASS | `SELECT id,status,closure_type,mfa_enabled_at,activated_at,email_verified_at FROM users WHERE id IN (2,3,6)` → all `status=active`, `closure_type=NULL`, `mfa_enabled_at=NULL`, activated + email-verified 2026-07-04. |
| 4 | Bank write path matches `DevBankAccountSeeder` | PASS | Seeder writes `distributors.bank_account_enc = PiiCrypter::encryptString($accountNumber)` and `bank_ifsc` as plain text. Same two columns, same crypter used here. Crypter round-trip proven on staging first: `PiiCrypter::encryptString("9876500001")` → 200-byte ciphertext → `decryptString` → `9876500001`. |
| 5 | Dummy bank details written for the 7 (only where missing) | PASS | All 7 had `bank_account_enc = NULL`, `bank_ifsc = NULL` before (see Mutations). Update was guarded with `->whereNull('bank_account_enc')`, so it is idempotent and could not overwrite a real value. |
| 6 | Payout engine's own decrypt path works for all 7 | PASS | `PayoutService::bankLast4ForDistributor()` (the method that throws `BankDecryptionException` → line status `bank_decrypt_failed`) invoked by reflection for ids 1-7, together with `hasBankAccountOnFile()` and `isKycVerified()`: `d1..d7 hasBank=true kyc=true last4='0001'..'0007'`. No exception on any of the 7. |
| 7 | "KYC active" definition located | PASS | `PayoutService::isKycVerified()` (`PayoutService.php:1327-1334`) = `users.status === 'active'` for the distributor's user (same definition as `RequireKycApproval`). It does **not** read `kyc_documents` or `distributors.status`. |
| 8 | The 7 satisfy the KYC gate | PASS — **no change needed** | All 7 already had `users.status = active` and `distributors.status = active` before this task. **No KYC-related column was modified.** |
| 9 | Probe failed job removed | PASS | `php artisan queue:forget 909aa10d-8480-4bd3-952f-ce45230a2d2e` → `INFO Failed job deleted successfully.` |
| 10 | failed_jobs count moved by exactly 1 | PASS-with-note | Count before = **12** (ids 228-239), not 11 as the plan baseline states; after = **11**. The probe (id 239, `failed_at 2026-09-10 12:10:26`) is gone; ids 228-238 untouched. The baseline number was taken before the probe row landed. |
| 11 | Sanity snapshot for the 7 | PASS | See table below. |

### Sanity snapshot (after all mutations)

| dist | ADN | user | users.status | distributors.status | bank decrypts | IFSC | personal BV (`bv_ledger_entries` sum ÷ 100) | repurchase wallet |
|---|---|---|---|---|---|---|---|---|
| 1 | 444555666 | 2 | active | active | yes | HDFC0000001 | 2,80,600.00 | ₹272.98 |
| 2 | 973708897 | 3 | active | active | yes | HDFC0000001 | 14,400.00 | ₹1,801.00 |
| 3 | 177536419 | 4 | active | active | yes | HDFC0000001 | 14,400.00 | ₹1,601.00 |
| 4 | 957327353 | 5 | active | active | yes | HDFC0000001 | 5,00,000.00 | ₹500.50 |
| 5 | 608628172 | 6 | active | active | yes | HDFC0000001 | 6,00,000.00 | ₹0.00 |
| 6 | 920536893 | 7 | active | active | yes | HDFC0000001 | 2,65,000.00 | ₹0.00 |
| 7 | 946362630 | 8 | active | active | yes | HDFC0000001 | 3,65,000.00 | ₹0.00 |

Sources used (the app's own code, not re-implemented SQL):
- personal BV = `BvLedgerService::totalPersonalBvPaise()` — the same call `PayoutService` uses for the `web_only` gate (`neftMinBvPaise`, 3000 BV).
- repurchase wallet = `WalletService::repurchaseWalletBalancePaise()` (`repurchase_deduction` credits − `repurchase_wallet_used` debits).

All 7 clear the three weekly-payout gates: BV ≥ 3000 (lowest is 14,400), KYC active, bank on file and decryptable.

## Defects

| Sev | Finding |
|---|---|
| Low | **No bank-account-holder-name column exists anywhere in the schema.** `information_schema` search for `%holder%` / `%beneficiar%` / `%account_name%` across `ahdhesuhty` returned **zero** columns; `distributors` carries only `bank_account_enc`, `bank_ifsc`, `razorpay_contact_id`. The task asked for a holder name = `users.full_name`; it could not be stored because the platform does not model it. Worth confirming with the payout/NEFT owner: a manual-NEFT file and a Razorpay fund-account both normally need a beneficiary name, and today it can only be derived from `users.full_name` at export time. Flagged for T12/T32 to check what the NEFT export actually emits as the beneficiary name. |
| Info | Plan baseline says failed_jobs = 11; the real pre-task count was 12. Post-task = 11, not 10. |

## Mutations made on staging

| Table | Rows | Column | Before → After |
|---|---|---|---|
| `users` | id 2 (d1, ADN 444555666) | `password_hash` | `$2y$12$hTmETCcg…` → `$2y$12$oi4aITY3…` (bcrypt of `QaPass!2026`) |
| `users` | id 6 (d5, ADN 608628172) | `password_hash` | `$2y$12$wUZ51wCy…` → `$2y$12$rc6X/0ID…` |
| `users` | id 3 (d2, ADN 973708897) | `password_hash` | `$2y$12$wnPkm5tm…` → `$2y$12$SAOoo5y0…` |
| `users` | ids 2, 6, 3 | `password_set_at` | `2026-08-30 22:01:41` / `2026-07-31 13:31:07` / `2026-08-30 22:02:35` → `now()` (2026-09-10) |
| `distributors` | ids 1-7 | `bank_account_enc` | `NULL` (all 7) → `PiiCrypter::encryptString('98765000' + zero-padded id)`, i.e. 9876500001 … 9876500007 (200-byte ciphertext each) |
| `distributors` | ids 1-7 | `bank_ifsc` | `NULL` (all 7) → `HDFC0000001` |
| `failed_jobs` | id 239, uuid `909aa10d-8480-4bd3-952f-ce45230a2d2e` | row | deleted via `queue:forget` (count 12 → 11) |

Not modified: `users.status`, `distributors.status`, any `kyc_*` table, `razorpay_contact_id`, any wallet/ledger/BV row.
The old bcrypt hashes are not recoverable (only the 8-char prefixes above were captured) — the previous passwords for these three reserved accounts were unknown anyway.

## Final QA credentials

Login form takes **ADN** for distributors, email for staff.

| Role | ADN / email | Password |
|---|---|---|
| Distributor (d1, user 2) | `444555666` | `QaPass!2026` |
| Distributor (d5, user 6) | `608628172` | `QaPass!2026` |
| Distributor (d2, user 3) | `973708897` | `QaPass!2026` |
| Admin | `admin@arovolife.test` | `admin12345` |

## Notes for the orchestrator

1. All 7 earning reserved accounts now pass `web_only` + `kyc_pending` + `no_bank_account` and decrypt cleanly — T12/T15/T32 can produce non-empty payout batches.
2. The 3 QA logins are verified at the hash level only (no browser was used, per brief). First browser task should confirm an actual login.
3. Bank details are the **same IFSC** for all 7 (`HDFC0000001`) with unique account numbers — deliberate, so a NEFT export groups them.
4. No beneficiary-name column exists; whoever runs T12/T32 should check what the NEFT/manual export writes as the beneficiary name.
5. failed_jobs is now 11 (ids 228-238), all pre-existing; T42 should sweep those.
6. Repurchase wallets are non-zero for d1-d4 (₹272.98 / ₹1,801 / ₹1,601 / ₹500.50) — those four will hit the "wallet must be 0" eligibility gates and the payout sweep in T10/T12.
