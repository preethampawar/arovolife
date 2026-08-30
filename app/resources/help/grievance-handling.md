# Grievance Handling

Everything on this page is a **published commitment**. The Grievance Redressal
Policy at `/p/grievance` tells complainants exactly what we will do and when,
and DSR 2021 Rule 12 requires us to be able to show that we did it. Treat the
queue as a compliance obligation, not a support inbox.

---

## Where complaints come from

Every intake channel lands in the same tracker at **Admin → Grievances**:

| Channel | How it arrives |
|---|---|
| Web form | `/p/grievance/form` — open to anyone, account or not |
| In-app | Distributor dashboard → Help → Raise a Grievance |
| Email | `grievance@arovolife.com` — record it with **Record a complaint** |
| Phone | Helpline — record it with **Record a complaint** |
| Post | Registered office — scan, then **Record a complaint** |
| In person | Walk-in at an authorised centre — **Record a complaint** |

The last four have no self-service route, so a human has to enter them. If that
does not happen, our published promise that "every channel routes into the same
ticketing system" is false. **Record the complaint the day it arrives.**

**Enter the date it reached us**, not today's date. Every clock in the published
policy is measured from receipt, so a letter that arrived last Tuesday is
already five days into its window when you key it in. The "Date received" field
on the intake form is what starts the clocks.

---

## The clocks

| Stage | Commitment |
|---|---|
| Acknowledgement (with the complaint number) | **48 hours from receipt** — sent automatically the instant a web or in-app complaint is filed. For post, phone, email and walk-in it goes out when you record the complaint, so record it the day it arrives |
| First substantive response | **5 working days** (working days exclude Sunday only) |
| Resolution | **30 days**, or **60** where a third party must respond |
| Progress update while a 60-day extension runs | at least every **15 days** |
| Record retention after closure | **7 years** |

An hourly sweep (`grievance:sla-sweep`) stamps a breach the moment a clock
lapses. Those stamps are permanent — they are what the monthly compliance
report counts. You cannot un-breach a ticket by resolving it later, and you
should not try.

---

## The escalation ladder

| Step | Owner | Moves up after |
|---|---|---|
| 1 | Customer care agent | 7 days |
| 2 | Grievance Officer | 15 days |
| 3 | Nodal Officer | — |
| 4 | Compliance Committee | — |

The sweep escalates automatically when a step runs over. You can also escalate
by hand from the ticket page. Each escalation emails the new owner.

**Ethics and privacy complaints skip step 1** and open directly with the
Grievance Officer. A bribe allegation must not land in the lap of the agent it
might be about. The four named distributor-conduct complaints — **Poaching**,
**Competitive business**, **Selling on e-commerce sites** and **Stocking and
under-cutting** — are ethics complaints too: they follow the same routing and
the same compliance-only visibility, and they are the place a distributor
reports another distributor's conduct (there is no separate "report a
distributor" form).

Step 4 is the end of the internal ladder. Steps 5 to 8 of the published matrix
— the National Consumer Helpline, the CCPA, the Consumer Disputes Redressal
Commissions and the Data Protection Board — are statutory authorities the
complainant approaches directly. We do not control or track those, and the
complainant does not have to exhaust our steps first.

---

## Third-party extensions

Use **Waiting on a third party** only when a bank, payment gateway, AUA/KUA
partner or statutory authority genuinely has to respond before the matter can
close. It:

- extends resolution from 30 days to 60,
- starts a 15-day progress-update obligation,
- **can only be applied once**, and never shortens an existing due date.

That "once" is deliberate. If staff could re-flag a ticket for another 30 days,
the published 60-day ceiling would stop meaning anything.

While the extension runs, the sweep reminds the owning officer when an update
is due. It will not write the update for you — only you know what the third
party actually said. Send it from the ticket with **Send progress update**.

---

## Resolving and closing

**Resolve** requires you to write what was done. The complainant is emailed
that text together with the next escalation step, so write it as something they
should read, not as an internal note.

**Close** is a separate act, available only after resolution. It starts the
seven-year retention clock. A closed grievance cannot be replied to — the
complainant must file a fresh one quoting the old number.

Do not close a ticket to clear the queue. A closed grievance with no stated
outcome is not a record we can defend.

---

## Anonymous complaints

We accept them (policy §6.5), especially for ethics matters. Anonymous tickets
store no name, email or phone, so:

- no acknowledgement, update or outcome can be sent,
- the complaint number appears **once**, on screen at submission,
- the ticket is still marked acknowledged, so it does not sit in the breach
  queue forever for a promise we never made.

Investigate as far as the evidence allows.

---

## Privacy

Opening a grievance and opening an attachment are both **audit-logged**. That
is deliberate: a complaint body routinely contains PII and often names another
distributor. The officer alert email carries the complaint number and category
but never the body, because an inbox has no audit trail.

Never paste a complaint body into a chat, a ticket in another system, or an
email thread outside the officers handling it.

**Who can see what.** The queue is gated on the `grievance.handle` permission,
which `admin-finance` does not hold. **Ethics and Privacy complaints are visible
only to the compliance side** — they do not appear in the queue for anyone else
and open as 404, because telling someone an ethics complaint exists is itself a
disclosure. Nobody can open a grievance filed by their own distributor account.

**Internal notes.** Use *Add internal note* for anything that names another
distributor or a member of staff. Internal notes stay in the statutory register
but never appear on the complainant's timeline. The *Respond* box does — treat
everything you write there as something the complainant will read.

**Privacy & data complaints** are alerted to the DPO mailbox rather than the
escalation-step owner, because the DPO is the contact published under DPDP §13.

---

## The monthly compliance report

**Admin → Grievances → Compliance report.** It shows, per month: complaints
received, resolved, closed and still open; acknowledgement, first-response and
resolution breaches; 60-day extensions; anonymous complaints; median days to
resolve; and breakdowns by category and intake channel.

The CSV export covers a trailing 12 months. This is the artefact handed to the
Compliance Committee each quarter and to a regulator on request.

Breach counts come from stamps written when the clock lapsed, not recomputed
from today's settings — so editing an SLA setting does not rewrite last month's
figures.

---

## Settings

**Admin → Settings → Grievance redressal** holds the SLA windows, escalation
clocks, attachment limits and officer mailboxes.

Every one of those values is printed at `/p/grievance`. **Change one and the
policy page must be amended in the same change** — a mismatch between what the
software does and what the published policy says is exactly what an audit
looks for. The SLA windows are developer-owned for that reason; the mailboxes
and the auto-escalation switch are not.

Before launch, the officer mailboxes must be real, monitored inboxes. The
defaults are placeholders.

---

## Commands

| Command | What it does |
|---|---|
| `grievance:sla-sweep` | Hourly. Stamps breaches, auto-escalates, nudges overdue progress updates. `--dry-run` reports without writing. |
| `grievance:purge-expired` | Deletes records past the 7-year retention window. **Not scheduled** — reports only unless `--force` is passed. |
