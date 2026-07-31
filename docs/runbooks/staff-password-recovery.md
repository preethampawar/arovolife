# Runbook — Recover a locked-out staff account

> Covers every account that signs in with an **email address** rather than an
> ADN: `admin`, `admin-finance`, `admin-compliance`, and the developer account.
> Distributors are a different flow — reset those from
> Admin → Distributors → *Send password reset*.
>
> This file is engineering-facing and lives only in the repo. The admin-visible
> version (self-service path only) is `resources/help/staff-access-recovery.md`,
> rendered at **Admin → Help & Reference → Staff Access & Password Recovery**.

## Why there is no "recover the password" — only "set a new one"

Staff passwords exist **only** as a bcrypt hash in `users.password_hash`. They
are not in `.env`, not in `config/`, not in the repo, not in any seeder, and not
in an audit row. That is deliberate, and it is the reason a forgotten password
can never be *read back* — it can only be *replaced*.

Do not "solve" this by adding a `DEV_PASSWORD` / `DEV_PASSWORD_HASH` variable to
`.env`. Plaintext there is the highest-privilege credential on the platform
sitting in a file that is copied into backups and readable from the hosting
panel. A bcrypt hash there is useless for the stated purpose — you still cannot
read the password back from it — while adding a second, weaker way to set the
password. The recovery paths below make it unnecessary either way.

## Pick a path

| # | Path | Needs | Works on | Use when |
|---|---|---|---|---|
| 1 | **Forgot-password email** | Access to the account's mailbox | Local, staging, production | Always try this first. It is the only path that needs no server access. |
| 2 | **Console (tinker)** | Shell on the app container/server | Local, staging, production | The mailbox is unreachable, or mail delivery is broken. |
| 3 | **Adminer / direct SQL** | DB access | Local (Adminer container) | Artisan itself is broken. |

## Path 1 — Forgot-password email

The reset flow looks the account up by email with no role or status filter
(`app/Modules/Identity/Services/RequestPasswordReset.php:31`), so staff and
developer accounts are **not** excluded from it.

1. Go to `/forgot-password` and enter the account's email.
2. The response is deliberately generic ("if an account exists…") — it does not
   confirm whether the address matched. That is anti-enumeration, not a failure.
3. Open the link within **60 minutes**.

Where the mail lands:

- **Local** — the `mailpit` container: <http://localhost:8027>
- **Staging / production** — the real mailbox. This is why a staff account's
  email must be an address you can actually read; a made-up `@arovolife.test`
  address on a live environment removes this path entirely.

## Path 2 — Console reset

There is no artisan command for this on purpose:

- `staff:create` **refuses** an existing email
  (`StaffAccountService.php:52-58`) — create-or-overwrite would be an
  account-takeover path disguised as a creation, and would be audited as one.
- `reset:test-users-password` only touches emails containing `test`,
  `may2026` or `mailinator`. It will not match a real staff address.

So use tinker. `password_hash` has **no** `hashed` cast, so hash explicitly —
assigning plaintext would store plaintext:

```bash
docker compose exec app php artisan tinker --execute '
$u = App\Modules\Identity\Models\User::where("email", "someone@example.com")->firstOrFail();
$u->forceFill([
    "password_hash"   => bcrypt("a-new-strong-password"),
    "password_set_at" => now(),
])->save();
echo $u->email." updated\n";'
```

`password_set_at` is not optional — the login controller treats a null value as
"password never set" and refuses the account.

**Before running this anywhere other than local**, confirm which database the
container points at. It is a single-row `UPDATE` on `users`, but on the wrong
environment it is an unannounced credential change on a live admin account.

**It writes no audit row.** A password changed this way is invisible in the
audit log, so record it yourself: who ran it, on which account, when, and why.

## Path 3 — Adminer / direct SQL

Last resort, when artisan will not boot. The `adminer` container is part of the
local compose stack. Generate a bcrypt hash (`bcrypt()` in tinker on any working
environment, or `php -r 'echo password_hash("…", PASSWORD_BCRYPT);'`), then set
`users.password_hash` and `users.password_set_at` on the row. The same
no-audit-row caveat applies.

## After any recovery

1. Sign in and confirm the account works.
2. Store the new password in a password manager — that, not `.env`, is where it
   belongs.
3. If paths 2 or 3 were used, note the out-of-band change somewhere durable.
4. If the lockout happened because the account's email is unreachable, fix the
   email so path 1 works next time.

## Related

- `docs/runbooks/artisan-commands.md` — the full command inventory
- `resources/help/staff-access-recovery.md` — the admin-visible version
- `resources/help/admin-actions.md` — Block / Unblock / Terminate, which are
  *not* recovery tools and must not be used as one
