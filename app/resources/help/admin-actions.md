# Admin Actions & Separation of Duties

> What each account action does, what's reversible, and who should do it.
> Every action below is recorded in the **audit log** with the actor, a reason,
> and before/after detail.

---

## Two separate status axes on a distributor

Don't confuse these — they're independent (see the **Status Reference**):

- **Account status** (can they sign in / lifecycle): Pending → Active → Blocked
  → Terminated, plus Rejected.
- **Distributor record** (the position itself): Active / Inactive.

A person can have an **Active** login but an **Inactive** distributor record, or
be **Blocked** from signing in while their record still exists.

---

## Actions on an account (Admin → Distributors → a distributor)

| Action | What it does | Reversible? | Use when |
|---|---|---|---|
| **Block** *(internally "freeze")* | Sets the account to **Blocked**. The distributor cannot sign in until unblocked. | **Yes** — Unblock restores access. | A compliance/admin hold while you investigate. |
| **Unblock** | Returns a Blocked account to **Active**. | Yes | The hold is cleared. |
| **Terminate** | **Permanently** closes the account. The distributor can **never** sign in again. | **No — irreversible.** | A final closure (fraud, repeat offence, policy). Requires a reason. |
| **Deactivate Distributor** | Marks the **record** Inactive (separate from login). | Yes — Activate restores it. | Pausing a distributor position without closing the account. |
| **Activate Distributor** | Marks the record Active. | Yes | Re-enabling a paused record. |

> **Block ≠ Terminate.** Block is a reversible hold (sign-in disabled).
> Terminate is permanent and cannot be undone. When unsure, **Block first** and
> escalate — never reach for Terminate as a quick fix.

### A note on wording
The buttons say **Block / Unblock**, and the status reads **Blocked**. The
underlying system value is still `frozen` and the audit-log keys are
`admin.distributor.frozen` / `unfrozen` — so if you're reading raw audit logs,
"frozen" there means "Blocked" here.

---

## Cooling-off cancellation vs Termination

Both end with the account **closed**, but they're different:

- **Cooling-off cancellation** — the distributor's own choice within 30 days,
  with a full refund. The account shows **Cancelled**. (See the Cooling-off
  reference.)
- **Termination** — an admin closure. The account shows **Terminated**.

---

## Separation of duties — the principle (and where we are today)

**The principle:** sensitive actions should be split across distinct admin
roles so no single person can do everything — e.g. a finance role that handles
payouts should **not** be able to freeze accounts, and a compliance role that
freezes/holds should **not** be able to approve payouts. This is a control
against both error and abuse.

**Where we are today:** the role split is **in force** (R-17). There is a
super-admin plus three scoped roles:

| Role | Can do | Cannot do |
|---|---|---|
| **`admin`** (super-admin) | Everything | — |
| **`admin-operations`** | Approve/reject line-change | Block/terminate accounts, record payments |
| **`admin-finance`** | Record payments (e.g. mark COD paid), refunds | Block/terminate accounts, decide line-change |
| **`admin-compliance`** | Block / unblock / terminate accounts | Record payments, decide line-change |

So **admin-finance cannot block** an account and **admin-compliance cannot
record payments** — enforced in code (each sensitive action checks a permission
the scoped role grants). A full **`admin`** keeps doing everything. Two
compensating controls still apply:

1. **Every action is audit-logged** with the actor and a reason — traceable after the fact.
2. Give a person the **narrowest** role that lets them do their job; reserve the
   full `admin` role for the few who genuinely need it.

If you believe an action needs a second pair of eyes, get one before acting.

---

## Always

- Enter a **clear, specific reason** on every action — it's permanent in the audit log.
- Prefer the **reversible** action (Block, Deactivate) over the irreversible one (Terminate).
- Escalate anything money-related, fraud-related, or irreversible to the Compliance Officer first.
