# ADR-0008 — Dedicated, stable encryption key for PII at rest

- **Status:** Accepted (2026-06-08)
- **Deciders:** Owner (Preetham), Compliance Officer
- **Supersedes / relates to:** Hard rule #8 (PII encrypted at rest), R-06 (PAN dedup), R-07 (KYC data breach)

## Context

Personal data at rest — `distributors.pan_encrypted`, `aadhaar_encrypted`,
`bank_account_enc` — was encrypted with Laravel's `encrypted` cast, which is
bound to **`APP_KEY`**.

`APP_KEY` is an *operational* secret: it protects sessions, cookies, signed
URLs and the `encrypt()` helper, and it is rotated as a routine action
(`php artisan key:generate`, a fresh deploy image, copying a DB between
environments without its key, etc.). The moment it changes, **every column
encrypted under it becomes undecryptable** — Laravel throws
`DecryptException: The MAC is invalid`.

This actually happened: a local DB seeded with staging PII produced a 500 on
the admin "edit identity" action because the staging `APP_KEY` that wrote the
PAN/Aadhaar was not present locally (see fix commit prior to this ADR). PII is
long-lived and must not share the fate of a routinely-rotated key.

## Decision

Introduce a **dedicated, stable encryption key, `PII_ENCRYPTION_KEY`**, used
exclusively for personal data, decoupled from `APP_KEY`.

- `config('app.pii_key')` ← `env('PII_ENCRYPTION_KEY')`.
- `App\Modules\Shared\Crypto\PiiCrypter` builds a single `Encrypter` from the
  PII key (primary) and **registers `APP_KEY` + `APP_PREVIOUS_KEYS` as
  fallback decryption keys**, so data written before the dedicated key existed
  still reads during migration.
- `App\Modules\Shared\Casts\PiiEncrypted` is the Eloquent cast that wraps
  `PiiCrypter` for model attributes. `Distributor::$casts` uses it for
  `pan_encrypted` / `aadhaar_encrypted`; the admin write paths
  (`AdminDistributorEditController`) and `bank_account_enc` use
  `PiiCrypter::encryptString()` directly. One encrypter, one key, everywhere.
- **Graceful default:** when `PII_ENCRYPTION_KEY` is unset, `PiiCrypter` uses
  `APP_KEY`, so behaviour is unchanged until the key is provisioned.
- **Migration:** `php artisan pii:reencrypt` reads each value via the fallback
  chain and rewrites it under the PII primary key. Idempotent; values that
  decrypt with no available key (foreign-key ciphertext) are left untouched and
  reported — recover those by admin re-entry on the distributor edit page.

### Key per environment

`PII_ENCRYPTION_KEY` is read from the environment, so it **can** differ per
environment. Two valid postures:

1. **Same key across environments** (owner's current preference): data is
   portable between local/staging/prod and the cross-environment incident above
   can never recur. Simplest operationally.
2. **Distinct prod key** (compliance-preferred for isolation): prod PII is not
   decryptable in lower environments.

These are not exclusive over time — the code supports either, and key
*versioning* (below) lets us migrate between them without an outage. **The
correct long-term control is to not hold real production PII in non-prod at
all** (DPDP purpose-limitation / data-minimisation): mask or synthesise PII in
staging/local, or scrub-and-re-key on import. A per-environment key only limits
blast radius; it is not a licence to seed lower environments with live
PAN/Aadhaar.

## Consequences

- ✅ Rotating `APP_KEY` no longer makes PII unreadable (test PII-03 locks this).
- ✅ Backward compatible: unset key → APP_KEY; fallback keeps legacy data
  readable; `pii:reencrypt` migrates on your schedule.
- ✅ Custom cast never decrypts the *previous* value for dirty-checking, so a
  row with an unreadable old value can still be overwritten (no 500).
- ⚠️ The PII key is now a critical, long-lived secret. **Store it in a secret
  manager, never in the repo image.** Losing it is unrecoverable (by design).
  Custody should be separated from `admin-finance` / `admin-compliance` per the
  separation-of-duties principle in CLAUDE.md.
- ⚠️ Only `Distributor` PII moved onto the dedicated key in this change.
  `Customer` encrypted fields (`email_enc`, `phone_enc`) and any future PII
  columns are a follow-up — they should adopt `PiiEncrypted` + an extended
  `pii:reencrypt` before relying on APP_KEY-rotation safety for them.

## Follow-ups (not in this change)

1. **Key versioning / rotation.** Store a key-id alongside the ciphertext so the
   PII key itself can be rotated and re-wrapped lazily, without a MAC-invalid
   outage. Without this, a stable key just relocates the single point of failure.
2. **Extend coverage** to `Customer.email_enc` / `phone_enc` and any new PII.
3. **Non-prod PII policy:** mask/synthesise or scrub-and-re-key on import to
   lower environments (the real fix for the originating incident).
4. **Optional:** make `pan_hash` a keyed HMAC with a stable pepper (brute-force
   resistance for a low-entropy identifier). One-way and must never change once
   set — its own deliberate migration; tracked separately.

## Provisioning

```bash
# Generate a key (prints base64:… — does NOT modify .env):
php artisan key:generate --show
# Put it in the environment (secret manager / .env), same value where you want
# data portability:
PII_ENCRYPTION_KEY=base64:xxxxxxxx...
# Migrate existing rows onto it:
php artisan pii:reencrypt
```
