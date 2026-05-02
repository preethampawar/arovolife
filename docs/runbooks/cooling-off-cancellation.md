# Runbook — Cooling-off cancellation

> **Statutory:** A distributor may cancel within 30 days of Effective Date and receive a full refund of any product purchases (T&C §4, Code V).

## Triggers

- Distributor clicks "Cancel my registration" on their dashboard.
- Distributor calls support; support invokes admin tooling.
- Distributor emails support@arovolife.example; support invokes admin tooling.

## Pre-conditions

- `now() <= distributors.cooling_off_end_at`.
- Distributor's user `status` is `active` (not already `frozen`/`terminated`).

## Procedure (one-click flow)

1. UI surfaces a confirmation page with the exact wording from the
   `arovolife-ux-writing` skill ("Cancel registration?" template).
2. On confirmation, dispatch `compliance.cooling_off.cancelled` event.
3. `CancelCoolingOff` service executes:
   - Set `cooling_off_events.cancelled_at = now()`.
   - Update user `status = 'terminated'`.
   - Update distributor `placement_side` is unchanged (do NOT alter the
     tree — the slot is preserved as "ghost" until reassignment).
   - Soft-mark distributor `terminated_at = now()` (column to be added in
     Phase 8 termination workflow; for Phase 1, set status only).
   - Queue refund event (Phase 3+ wallet picks up; in Phase 1 we emit
     a placeholder ledger entry of value 0 to rehearse plumbing).
   - Write `audit_log` row (`action = compliance.cooling_off.cancelled`).
   - Send email + SMS confirming cancellation.

## Post-conditions

- The distributor cannot log in (status terminated).
- A `cooling_off_events` row records the event.
- An audit-log row exists with the actor (self-cancel = the distributor's user_id).

## SLA

- The cancellation is recorded synchronously on the user's request (≤ 2 s).
- Refund (Phase 3+) is queued; SLA = 7 working days to back-to-source.

## What can go wrong

| Symptom | Likely cause | Action |
|---|---|---|
| Click does nothing | JS error or rate limit | Check browser console; check rate-limit logs. Provide manual cancellation via admin. |
| Email/SMS not delivered | Vendor outage | Open ticket with vendor; record fact in audit log; provide written acknowledgement. |
| Distributor reports they cancelled but status is active | Race or queue lag | Check `cooling_off_events`; if a row exists, force status update + investigate worker logs. |
| Distributor cancels on day 31 | Window expired | Show clear error ("the 30-day cooling-off has ended on …"); offer alternative termination path. |

## Test references

- `tests/Modules/Compliance/CoolingOffCancelTest.php`
- `tests/Modules/Compliance/CoolingOffEvaluatorTest.php`

## Compliance notes

- This action MUST be exercisable in a single click after the
  confirmation page.
- The refund (when wallet exists) MUST be at the Direct Seller Price
  with no GST deduction during cooling-off.
- All cooling-off cancellations must be reportable to the regulator.
