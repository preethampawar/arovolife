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
| Who moves the money | Finance, by uploading the bank file (NEFT) to the company bank's portal. | Razorpay, automatically. |
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
2. The **weekly payout** command runs each Tuesday at 03:00 IST and pays ONE
   earning week: Wednesday to Tuesday, the week that closed the *previous*
   Tuesday. The batch dated Tuesday 18 August pays the GSB and Mentorship
   income earned from Wednesday 5 August to Tuesday 11 August; income earned on
   12 August waits for the 25 August batch. The week is keyed on the day the
   income was earned — the GSB cut-off date — not on when the credit landed in
   the wallet, because Tuesday's cut-off is credited at 00:10 on Wednesday. The
   batch list shows the last day each batch pays for under **Earnings through**;
   each batch records it when it is created, so it reads "—" for batches
   written before the rule was deployed and for legacy `gsb_weekly` batches,
   which swept the whole wallet balance rather than a bounded week.
   The **monthly payout** runs on the 8th at
   04:00 IST for Growth Booster, Rank, Fortune, Awards and ADC — a week after
   the crediting engines close the month on the 1st, and only if every one of
   them succeeded (see § Monthly close below). It pays ONE month, the one it is
   named for: income earned for a later month stays in the wallet for that
   month's own batch. The month a credit belongs to is the month it was earned
   FOR, not the day it was written — the engines close a month on the 1st of the
   next one. The batch records the last day it pays for under **Earnings
   through**, the same column the weekly batches use.
3. Each run computes one line item per distributor: gross → repurchase
   deduction (already taken at credit time; the batch only sweeps and reports
   it) → admin charge → TDS → net. The wallet is debited at this moment,
   not at approval — the money is already committed before anyone clicks
   anything.
4. The batch lands in **Pending approval**.

A batch that hit an error for some distributors lands in **Partially failed**
instead and cannot be approved. Re-run the same batch date: only the
distributors who failed are retried, and the batch returns to Pending.

## Monthly close: crediting on the 1st, payment on the 8th

Two scheduled commands own the month, and they run a week apart on purpose.

**`compensation:monthly-close`** — 1st, 00:20 IST. Runs the seven crediting
engines in one process, in dependency order: rank qualifications → Rank Bonus →
Growth Booster → Fortune enrolment → ADC → Fortune payout → purchase offers. It waits for the closed month's last daily
cut-off before starting, and it stops at the first step that fails rather than
letting the next engine run on half-written input. Re-running it **resumes**:
every step already recorded as succeeded is skipped, so a failure at step 5
never re-touches steps 1–3. `--restart` forces the whole sequence, and is only
for the rare case where an earlier step genuinely has to be recomputed.

**`compensation:monthly-payout-close`** — 8th, 04:00 IST. Runs the monthly
payout batch, but **only if every crediting engine for that month actually
succeeded**. If one failed, it refuses, names the engine and prints the exact
command to re-run it; nothing is swept and no batch is created. An engine whose
feature flag is off records a *skipped* run and does not block — it computes
nothing either way.

A step that fails also reaches the monitored mailbox in the next morning's
engine-health email (08:00 IST) with the steps that close it, so an incomplete
month is not waiting on somebody opening the console — see
**Compensation → Daily engine-health email** in the compensation help page.

The week between the two is the only window in which a bad month can still be
fixed: the monthly batch is idempotent per month, so once it has swept that
month, a credit written for it afterwards has nowhere to go. A credit for a
LATER month is untouched — each batch only sweeps its own month.

If a month is refused: open **Compensation → Engine Runs → Events**, filter to
**Failed**, re-run the engine the refusal named (Engine Runs, or the printed
command), then re-run `compensation:monthly-payout-close --month=YYYY-MM` for
the crediting month. `--force` exists and pays out over an incomplete month —
do not use it without knowing exactly which credits will be missing.

The admin sidebar shows an **Engine failures** badge whenever a compensation
engine's last outcome for a period is a failure. It links straight to the
failed runs and clears itself once the engine has been re-run successfully.

## Reviewing a batch before approving it

On **Compensation → Payouts → (a batch)**:

- **Distributors / Total gross / Deductions / Net to transfer** — the four
  summary cards. Net is what will actually leave the company.
- The status strip under them counts every line item by status. Lines that are
  `web_only`, `kyc_pending`, `no_bank_account` or `bank_decrypt_failed` are
  *holds*: their money stayed in the wallet, was never debited, and will be
  picked up by the first batch after the block is cleared. They are shown so
  you can see who is waiting and why.
- **Download bank file (NEFT)** produces the file the company bank executes —
  one line per payable distributor, with their **full account number**, IFSC and
  beneficiary name. It appears only once the batch has been approved, and only
  for finance. In Razorpay mode it is a record to reconcile against rather than
  an instruction, but it still waits for approval. See
  [The bank file](#the-bank-file-neft) below for the columns and what each
  download records.

Check the net total against what the engines reported before approving.
Approval cannot be undone from this screen.

## Who may approve a batch

Approving is a separate authority from running the payout. Two rules, both
enforced in code:

1. **Approval needs `finance.approve`.** Only `admin` (and the developer role)
   holds it. `admin-finance` deliberately does not: that role runs the batch,
   pulls the bank file, imports the bank's response and retries a failed
   transfer — the *making* and *settling* of a payment run. The person who signs
   the money off is a different person. `admin-compliance` and
   `admin-operations` hold neither and can only look.
2. **Whoever created the batch cannot approve it.** Every batch records its
   maker in `created_by`: the admin who ran it from Engine Runs, or the admin
   whose action on a payout page created it. If that is you, the Approve button
   is not shown, the route refuses the request, and a
   `payout.batch.self_approval_refused` audit row is written. Ask a second
   approver.

A batch the **scheduler** built has no maker (`created_by` is empty, shown on
the batch page as "Created by the scheduler"), so any approver may sign it off.
That is the normal case: the weekly and monthly runs are cron jobs.

**Holds are re-read at approval.** Every held line is re-checked against the
income gates the moment you approve: anyone whose KYC was approved or whose bank
details arrived after the batch was generated is released into *this* batch and
paid by it, and a hold that still stands but for a different reason is restated
before it is signed off. The batch totals are re-derived from the lines that
then exist, so the figure you confirm is the figure that goes out. The
`payout.batch.holds_reevaluated` audit row records what changed.

The confirmation also names the income the batch is NOT moving — the held total
and how many distributors it belongs to — beside the net going to the bank, so a
batch of nothing but holds no longer reads as "₹0.00 to 0 distributor(s)".

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

## The bank file (NEFT)

The download is the instruction the bank acts on, so it carries what a bank
needs to execute a transfer — not a reconciliation sheet:

| Column | What it holds |
|---|---|
| `Line#` | Row number within this file. |
| `ADN` | The distributor being paid. |
| `Beneficiary Name` | The account holder's name **as the bank holds it**, from the distributor's Bank details page. Falls back to their registered full name when they have not given one. |
| `Account Number` | The full account number. |
| `IFSC` | The branch code. |
| `Net Amount (₹)` | What leaves the company for that line, after every deduction. Plain digits with two decimals and no grouping — a bank parser reads `1234.50`, not `1,234.50`. |
| `Narration` | `arovolife <ADN> B<batch id>` — what the distributor sees on their statement, and what ties a credit back to a batch. |
| `UTR` | Blank until the bank's response file is imported. |
| `Status` | The line's state at the moment of download. |

**Every download is audited.** A `payout.batch.bank_file_exported` row records
who downloaded it, which batch, how many lines, and a SHA-256 of the exact
bytes handed over. The file itself is never stored and no account number is
ever written to the audit log or to any application log — the digest is there so
that a file produced later can be proved identical to (or different from) the
one that went to the bank.

**A line the platform can no longer decrypt** — a bank account whose ciphertext
does not open, which normally means a key rotation between the batch run and the
download — still appears in the file, with its `Account Number` and `IFSC`
blank and its `Status` reading `bank_decrypt_failed`. It cannot be executed, and
it is visible rather than silently missing. Fix the distributor's bank details
and re-run the batch date.

## Manual NEFT mode: export, upload, import

1. **Approve batch** — the batch moves to `approved`. Nothing has moved.
2. **Download bank file (NEFT)** — download it and upload it to the company
   bank's portal.
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

Two more it will **reject** — count them and read the reasons before you treat a
batch as settled:

- **The amount does not match.** When the file carries an amount column (`Net
  Amount`, `Amount`, `Transfer Amount` and similar), the row's amount has to
  equal that line's net. A mismatch means the file belongs to another batch or a
  column is out of step, so the row is not applied. If the file has no amount
  column at all, the import says so in its result message — amounts went
  unverified.
- **The bank reference is already in use.** One UTR settles one line. A
  reference repeated inside the file, or already recorded on any earlier line
  item, is refused: two distributors cannot be paid by one transfer. The
  database enforces the same rule, so it holds even if two imports run at once.

## When a transfer fails

Failures come in two kinds, and the difference decides what you do.

**The bank details are wrong** — invalid IFSC, invalid account number, an
account that no longer exists. Retrying changes nothing. The details have to be
corrected first, then retry. In Razorpay mode a corrected account produces a new
fund account automatically.

Two paths now correct them, and the distributor's own is the better one:

- **The distributor, from My profile → Bank details.** They type the account
  number twice, give the IFSC and the account holder's name as their bank has
  it, and confirm a 6-digit code emailed to them; nothing is written until the
  code is confirmed. Every change writes an audit row
  (`distributor.bank_details_updated`, last-4 only) and emails them a receipt.
  Point them here rather than keying an account number in for them — a number
  read out over a phone call is a number you can mistype.
- **You, from Distributors → the distributor → bank details**, when they cannot
  do it themselves.

Either way the held line clears on its own: holds are re-read from live state
when the batch is re-run and again at approval.

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
| `no_bank_account` | No bank account on file. The distributor can add one themselves from My profile → Bank details. | Held in the wallet. |
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
| `payout.batch.self_approval_refused` | An approver was refused their own batch: who tried, and who created it. |
| `payout.batch.bank_file_exported` | Who downloaded the bank file, for which batch, how many lines, and a SHA-256 of the exact bytes. |
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
