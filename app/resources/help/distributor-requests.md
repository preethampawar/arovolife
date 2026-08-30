# Distributor Requests

A **distributor request** is a formal, documented ask from a distributor about
their *own* record. It replaces the paper "Distributor Request" form. The
feature ships behind the `identity.distributor_requests` flag; while it is off
there is no menu item, no queue and the routes 404.

## What is a request — and what is not

| The distributor wants to… | Where it goes |
|---|---|
| Change mobile, email or address | **My profile** (self-service, OTP-verified). Not a request. |
| Correct a misspelt name, change their legal name, correct their date of birth | Distributor request (this queue) |
| Transfer the distributorship to a spouse / parent / child / sibling | Distributor request → compliance |
| Cancel their ID | Within 30 days: cooling-off cancellation (self-service, full refund). After that: distributor request → compliance |
| Report poaching, competitive business, e-commerce selling, stocking / under-cutting by another distributor | **A grievance**, not a request — those are ethics complaints and follow the grievance ladder |

## The five types

| Type | What approval does | Who decides |
|---|---|---|
| Name correction | Updates the name on the record to the requested value | `kyc.review` (operations) |
| Name change (same person) | Updates the name on the record | `kyc.review` (operations) |
| Date of birth correction | Updates the date of birth on the record | `kyc.review` (operations) |
| Membership transfer to an immediate blood relation | **Acknowledges only.** Compliance then carries it out: the relation registers and completes KYC under their own PAN (one PAN = one ADN), and the position is transferred with the account tools | `compliance.discipline` |
| ID cancellation | **Acknowledges only.** Compliance closes the account from the distributor's page (Terminate / close) after confirming the date with the distributor | `compliance.discipline` |

Reading the queue needs `distributor.request.handle` (operations and
compliance). Finance has no part in it.

## Before you approve a name or DOB request

1. **Open the document** — it is the only evidence. The requested value must
   match it *exactly* (spelling, initials, date). If it does not, reject with a
   reason; do not "fix" the value yourself.
2. A **name change** needs the legal instrument (gazette, marriage certificate
   or affidavit) *and* an ID in the new name. A **correction** needs only the ID.
3. Approving writes the new value to the record and an audit entry with the
   before/after (`profile.identity_corrected`). It does **not** re-run KYC: if
   the change means the PAN on file no longer matches the name, send the
   distributor through KYC re-upload as well.
4. Every document you open is audit-logged (`distributor_request.document_viewed`).

## Before you approve a transfer or cancellation

- A transfer is a compliance matter under the Direct Seller Agreement. Approval
  in this queue only tells the distributor the request is accepted in
  principle. Nothing in the genealogy moves until the relation has their own
  KYC-verified ADN.
- A cancellation after the cooling-off window is a voluntary closure: earned
  income already due is still paid; nothing new accrues after the closure date.
  Confirm the date with the distributor, then close the account from their page.

## Wording

Say "closed" or "cancelled", never "terminated", for a voluntary cancellation —
termination is the disciplinary outcome and shows differently on the record.
