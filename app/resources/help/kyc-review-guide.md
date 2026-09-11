# KYC Review Guide

> How to review a distributor's identity documents in **Admin → KYC review**.
> KYC is what lets a `pending` applicant become `active`. Handle these documents
> with care — they are personal data.

---

## What KYC means for the account

KYC review drives the account status:

- Approve a complete, valid set → the applicant moves toward **Active**.
- Reject → the account is set to **Rejected**; the applicant can re-upload and resubmit.
- Put on hold → **Blocked** (a compliance hold) while you investigate.

*(See the **Status Reference** for the full account lifecycle.)*

---

## The document set

| Document | Required? | What to look for |
|---|---|---|
| **PAN** | Required | Legible PAN card; name matches the application. |
| **Aadhaar — front** | Required | Front of the physical Aadhaar card. |
| **Aadhaar — back** | Required | Back side with the UIDAI-printed address. |
| **Address proof — front** | Required | A valid address document, front side. |
| **Address proof — back** | Required | Back side of the same document. |
| **Cheque** | Optional | Only when bank details are on file (cancelled cheque / passbook). |
| **ID photo** | Self-service | Uploaded by the distributor on their own dashboard (passport-style). Not part of admin upload-on-behalf-of. |

A document is acceptable when it is **clear, complete, unexpired, and the name
matches** the application. If any required document is missing or unreadable, it
is not yet a complete set.

---

## How to review

1. Open **Admin → KYC review** and select the applicant.
2. Check each document against the table above.
3. Then choose one of:
   - **Approve** — the set is complete and valid.
   - **Reject** — something is wrong with the whole application; the applicant restarts the KYC upload.
   - **Flag a single document for re-upload** — when just one document is unclear/wrong and the rest are fine (preferred over a full reject; see below).

Every decision is recorded in the **audit log** with a reason — always enter a
clear, specific reason (the applicant may see it).

---

## Flag a single document for re-upload (preferred for one bad doc)

When only **one** document is the problem, flag that document instead of
rejecting the whole application:

- The document is marked flagged with your reason.
- The distributor is **notified** (email + in-app) and can re-upload **only
  that document** — they don't redo the whole set.
- The confirmation says the notice is *queued*, not delivered: email leaves our
  queue and can still be refused by the receiving server. If it says the notice
  **could not be sent**, the flag is recorded and the re-upload link is live —
  contact the applicant yourself. The same wording applies to reject and
  terminate.

This is faster for the applicant and keeps the rest of the verified set intact.

While a flag is unresolved:

- The submission appears under the **Awaiting re-upload** tab on the KYC queue,
  and its row in the Pending tab carries an *Awaiting re-upload* badge — so a
  second reviewer can see it is parked on the applicant.
- **Approve is blocked** for that submission, on the page and on the server.
  Approving would accept the document you asked to have replaced, and the
  applicant's re-upload link stops working the moment the flag clears. Wait for
  the replacement, or reject the whole submission.
- The flag clears by itself when the applicant re-uploads that document.

---

## Handling personal data — non-negotiable

- **Never** read aloud, type, screenshot, message, or store a full Aadhaar or
  PAN number. We keep only a secure reference + last-4.
- Document images live on a **private** store — never share a direct link or
  download to anyone outside the review. Every image on the review page, preview
  included, is served through the audited route, so a copied link is useless to
  anyone without an admin session.
- Access a document only when a review task requires it — **each view is logged
  against your account**.
- Every admin action on a KYC record is audit-logged with before/after — that
  is by design; don't try to work around it.

> If something looks like identity fraud (mismatched names, tampered images,
> the same PAN across two applicants), **don't approve** — put the account on a
> compliance hold (Blocked) and escalate to the Compliance Officer.
