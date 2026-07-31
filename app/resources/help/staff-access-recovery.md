# Staff Access & Password Recovery

> What to do when you — or another staff member — can't sign in. Also covers
> resetting a **distributor's** password, which is a different flow.

---

## Two different sign-ins

| Who | Signs in with | Where |
|---|---|---|
| **Staff** (admin and back-office accounts) | Their **email address** | `/login` |
| **Distributors** | Their **ADN** | `/login` |

A staff member who tries to sign in with an ADN, or a distributor who tries an
email, will simply be rejected — the credential is looked up differently for
each. Check this first before assuming a password problem.

---

## If you forgot your own password

Use the self-service link. It works for every staff account.

1. On `/login`, choose **Forgot password**.
2. Enter the email address your account signs in with.
3. Open the emailed link **within 60 minutes** and set a new password.

The confirmation message is deliberately vague — *"if an account exists for that
email…"* — and says the same thing whether or not the address matched. That is
intentional: it stops an outsider using the form to discover which addresses are
real accounts. It does **not** mean the request failed.

The new password must pass the same strength and breach checks as every other
password on the platform: at least 12 characters, and not a password known to
have appeared in a public breach.

---

## If the reset email never arrives

| Situation | What to do |
|---|---|
| Wrong or mistyped address | Try again with the exact address on the account. |
| Address is a mailbox nobody can open | You cannot recover it yourself — **contact engineering**. There is a documented server-side procedure. |
| Link expired (over 60 minutes) | Request a new one; links are single-use and time-limited. |
| Everything looks right, still nothing | Check spam, then contact engineering — mail delivery itself may be the problem. |

**Keep every staff account's email address real and reachable.** If the address
on an account is one nobody actually monitors, the self-service path is gone and
every lockout becomes an engineering ticket.

---

## Nobody can look up your password for you

Passwords are stored only in a scrambled (hashed) form that cannot be reversed —
not by an admin, not by engineering, not from the database. There is no screen
anywhere in the platform that shows a password.

So a forgotten password is never *recovered*, only *replaced*. If someone claims
they can read yours back to you, treat that as a red flag and report it.

For the same reason: never share a password, and never sign in as someone else.
Every admin action is recorded against the account that performed it — using
another person's login attributes their actions to them.

---

## Resetting a distributor's password

Do **not** use account actions for this.

- Go to **Admin → Distributors →** the distributor **→ Send password reset**.
- This emails them a reset link. You never see or set their password.

**Block, Unblock, Terminate and Deactivate are not password tools.** Blocking an
account to "force a reset" locks the person out without sending them anything,
and Terminate is irreversible. See **Admin Actions & Separation of Duties**.

---

## Quick reference

| Symptom | Action |
|---|---|
| Forgot your staff password | `/login` → Forgot password → check your email |
| Reset link expired | Request a new one |
| Staff mailbox unreachable | Contact engineering (server-side procedure exists) |
| Distributor forgot their password | Admin → Distributors → Send password reset |
| Distributor can't sign in but has no password problem | Check their **account status** — a Blocked or Terminated account is refused at sign-in regardless of password |
