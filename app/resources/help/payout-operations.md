# Payout Operations

How commission money leaves the platform and reaches a distributor's bank
account, and what every button on the payout screens actually does.

## The one rule

**Approving a batch is not the same as paying it.** Approval is finance saying
"this amount is correct and may be released". Payment is the bank actually
moving the money, and only the bank can tell us it happened — through a
Razorpay payout webhook, or through the response file the bank returns after a
NEFT upload. A line item is marked `transferred` on that evidence and nothing
else, because "transferred" is the number that appears on a distributor's
Total Withdrawal Income and on their tax statement.

## The two modes

The gateway is chosen once, platform-wide, on **Compensation → Payout
Settings** (the setting is `payout.gateway`). It applies from the next batch
approved; batches already approved continue on the route they were approved
under.

| | Manual NEFT | Razorpay Payouts |
|---|---|---|
| What approval does | Marks the batch `approved`. No money moves. | Marks the batch `dispatched` and queues every payable line item to the RazorpayX API. **Real bank transfers start immediately.** |
| Who moves the money | Finance, by uploading the NEFT CSV to the company bank's portal. | Razorpay, automatically. |
| How a line becomes `transferred` | You import the bank's response file on the batch page. | Razorpay's `payout.processed` webhook, which also carries the UTR. |
| How long it takes | As long as the bank takes, plus the human steps. | Minutes to hours, depending on the rail. |
| What it needs | Nothing beyond bank access. | `RAZORPAYX_KEY_ID`, `RAZORPAYX_KEY_SECRET`, `RAZORPAYX_WEBHOOK_SECRET` and `RAZORPAYX_ACCOUNT_NUMBER` in the server environment. |

Manual NEFT is the default. No environment starts moving money through an API
merely because the code shipped.

## Where a batch comes from

Nobody creates a payout batch by hand.

1. The bonus engines credit distributors' wallets as product sales are made.
   Every credit carries a `product_sale_id` — there is no such thing as a
   payout without a sale behind it (hard rule 2).
2. The **weekly payout** command runs each Tuesday at 09:00 IST and sweeps the
   unpaid GSB and Mentorship credits. The **monthly payout** runs on the 9th at
   10:30 IST for Growth Booster, Rank, Fortune, Awards and ADC.
3. Each run computes one line item per distributor: gross → repurchase
   deduction → admin charge → TDS → net. The wallet is debited at this moment,
   not at approval — the money is already committed before anyone clicks
   anything.
4. The batch lands in **Pending approval**.

A batch that hit an error for some distributors lands in **Partially failed**
instead and cannot be approved. Re-run the same batch date: only the
distributors who failed are retried, and the batch returns to Pending.

## Reviewing a batch before approving it

On **Compensation → Payouts → (a batch)**:

- **Distributors / Total gross / Deductions / Net to transfer** — the four
  summary cards. Net is what will actually leave the company.
- The status strip under them counts every line item by status. Lines that are
  `web_only`, `kyc_pending`, `no_bank_account` or `bank_decrypt_failed` are
  *holds*: their money stayed in the wallet, was never debited, and will be
  picked up by the first batch after the block is cleared. They are shown so
  you can see who is waiting and why.
- **NEFT CSV** downloads every payable line. It is always available, in both
  modes — in Razorpay mode it is a record to reconcile against rather than an
  instruction to the bank.

Check the net total against what the engines reported before approving.
Approval cannot be undone from this screen.

## Razorpay mode: approve and dispatch

Pressing **Approve & dispatch to bank** does four things:

1. Moves the batch to `dispatched` and records who approved it and when.
2. Queues a job on the `compensation` queue, which sends each line item to
   RazorpayX one at a time.
3. For each distributor, creates (or reuses) a *contact* and a *fund account*
   from their bank details, then creates the *payout*. The line item stores the
   payout id, contact id, fund account id, the rail used, and the dispatch time
   — but its status stays `pending`.
4. Marks each line `transferred` only when the payout webhook confirms it, and
   fills in the UTR from that event.

**If one distributor fails, the rest still go out.** A bad IFSC marks that one
line `failed` with the reason and the batch continues.

If the button is disabled, the credentials are missing — the red banner on the
page says so. Fix the environment, or switch the gateway to Manual NEFT.

### What the webhooks do

Razorpay must be subscribed to `payout.processed`, `payout.failed` and
`payout.reversed` at the URL shown on the Payout Settings page, with the
webhook secret configured. Without the webhook nothing is ever confirmed:
transfers really happen, but every line item stays `pending` forever.

- `payout.processed` → `transferred`, UTR recorded.
- `payout.failed` / `payout.rejected` → `failed`, with Razorpay's reason.
- `payout.reversed` → `failed`, "Payment reversed by bank" — the only event
  that can overturn a line already marked transferred.
- `payout.queued` / `payout.pending` / `payout.initiated` → noted in the event
  trail, no status change. The transfer is in flight.

Every delivery is stored once, keyed on Razorpay's event id, so a redelivery
is a no-op. A late event that would walk a settled transfer backwards is
ignored.

## Manual NEFT mode: export, upload, import

1. **Approve batch** — the batch moves to `approved`. Nothing has moved.
2. **NEFT CSV** — download it and upload it to the company bank's portal.
3. The bank returns a response file naming which lines settled.
4. **Import bank response** on the batch page. Rows are matched on ADN. A row
   marks that line `transferred` (with its UTR) or `failed` (with the bank's
   reason).

The file needs a header row with an **ADN** column and a **Status** column.
**UTR** and **Failure Reason** are used when present. Status wording is matched
loosely — `SUCCESS`, `Successful`, `PROCESSED`, `PAID` all read as success;
`FAILED`, `REJECTED`, `RETURNED`, `BOUNCED` as failure. Anything else is
skipped and reported rather than guessed at.

Two things the import will not do: change a line that has already settled
(re-importing the same file is safe), and settle a line for an ADN that is not
in this batch (those are reported as unmatched).

## When a transfer fails

Failures come in two kinds, and the difference decides what you do.

**The bank details are wrong** — invalid IFSC, invalid account number, an
account that no longer exists. Retrying changes nothing. Correct the
distributor's bank details first (Distributors → the distributor → bank
details), then retry. In Razorpay mode a corrected account produces a new fund
account automatically.

**Something transient went wrong** — a gateway blip, a rate limit, a RazorpayX
balance that was short at the time. Retrying is exactly the right answer.

### Retrying

- **Retry** on a single line item — Razorpay mode only, for a `failed` line
  that has not already reached the retry limit.
- **Retry N failed** in the header — queues every eligible failed line in the
  batch at once.
- **Automatic** — a nightly sweep at 11:00 IST re-sends failed transfers that
  have sat untouched longer than the configured window (default 24 hours) and
  are under the retry limit (default 3). It does nothing in Manual NEFT mode.

A retry never sends a second transfer for a payout Razorpay already has: each
attempt carries a deterministic idempotency key, and a line item that already
holds a payout id is skipped outright.

`bank_decrypt_failed` lines are deliberately never auto-retried — the stored
bank details cannot be read at all, and only re-capturing them fixes it.

## Status reference

### Line item statuses

| Status | Meaning | Money position |
|---|---|---|
| `pending` | Computed and payable; awaiting approval, or in flight with the bank. | Debited from the wallet, not yet with the distributor. |
| `transferred` | The bank confirmed the transfer. | Paid. Counts toward Total Withdrawal Income. |
| `failed` | The transfer was attempted and refused, or reversed. | Debited, not paid. Retryable. |
| `below_minimum` | Net fell under the minimum payout threshold. | Held in the wallet; rolls into a later batch. |
| `web_only` | Personal BV below the NEFT eligibility threshold. | Held in the wallet. Income still accrues and is visible. |
| `kyc_pending` | KYC not yet verified. | Held in the wallet. Released by the first batch after approval. |
| `no_bank_account` | No bank account on file. | Held in the wallet. |
| `bank_decrypt_failed` | Bank details on file cannot be decrypted. | Held in the wallet. Needs the details re-captured. |

### Batch statuses

| Status | Meaning |
|---|---|
| `pending` | Computed, awaiting finance approval. |
| `processing` | A batch run is in progress. Not approvable. |
| `approved` | Manual NEFT: signed off, awaiting the bank response file. |
| `dispatched` | Razorpay: every line handed to the gateway, awaiting webhooks. |
| `completed` | Every payable line transferred. |
| `partially_failed` | Some transferred, some failed. Fix and retry the failures. |
| `failed` | Every payable line failed. |

An `approved` or `dispatched` batch is closed: re-running the batch date will
not append new line items to it.

## Audit trail

Every action leaves an `audit_log` row. In Compliance → Audit log, look for:

| Action | What it records |
|---|---|
| `payout.batch.created` / `payout.batch.finalised` | The engine run that produced the batch. |
| `payout.batch.approved` | Who approved it, under which gateway, for how much. |
| `payout.batch.dispatched` | How many line items were sent, how many failed on the way out. |
| `payout.batch.reconciled` | A bank response import: file name, rows, matched, transferred, failed. |
| `payout.batch.settled` | The batch reaching completed / partially failed / failed. |
| `payout.line_item.dispatched` | One transfer handed to Razorpay, with its payout id. |
| `payout.line_item.retry_requested` / `retry_dispatched` | Who asked for a retry, and the attempt that followed. |
| `payout.line_item.dispatch_failed` | A transfer that could not be sent, and why. |
| `payout.line_item.transferred` / `failed` | A webhook changing a line item's state. |
| `payout.settings.updated` | A change to the gateway or its levers. |
| `payout.gateway.connection_tested` | Someone pressing Test connection. |

Alongside it, `payout_gateway_events` holds the raw exchange with the gateway —
every call we made and every webhook it delivered. Bank account numbers, IFSC
codes and names are stripped before anything is written there.

## FAQ

**I approved a batch by accident. Can I undo it?**
No, and in Razorpay mode the transfers have already started. Approval is
recorded against your account. If a specific transfer must be stopped, that is
a call to Razorpay support, not a button here.

**I pressed Approve twice.**
Nothing happens the second time. Approval only acts on a batch in `pending`.

**A transfer's webhook never arrived. The line is stuck on `pending`.**
Check the payout id shown in the UTR column against the RazorpayX dashboard. If
the transfer really did settle, the webhook subscription is the problem — check
that the endpoint on Payout Settings is registered and its secret matches.
Never mark a line transferred to work around a missing webhook.

**Can I pay one distributor without running a batch?**
No. Payouts exist only as line items of a batch, and batches come only from the
engines. That is what keeps every rupee paid traceable to product sales.

**The distributor says the money never arrived, but the line says transferred.**
Give them the UTR from the line item. That is the reference their own bank uses
to trace an inward credit.

**Why is a distributor's income visible but not paid?**
One of the holds — `web_only`, `kyc_pending`, `no_bank_account`,
`bank_decrypt_failed`. Income accrues and stays fully visible to them
throughout; only the bank release is held. Clearing the block releases it in
the next batch, with nothing lost.

**Test connection fails.**
It only proves the key pair is accepted. A failure means the credentials are
wrong, absent, or the key is for a different Razorpay account. It creates no
payout and moves no money, so it is always safe to press.
