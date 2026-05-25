# Line-Change Request → Admin Approval → Placement Move

**Date:** 2026-05-25
**Status:** Approved (design)
**Module:** Genealogy (+ Admin)
**Phase:** 1

---

## 1. Summary

Today a distributor can *submit* a line-change request, but there is no admin
approval step and nothing executes the change. This spec adds the missing
approval workflow and the actual placement move, and **reframes the feature
from a sponsor change to a binary-placement change**.

A line change moves **only the distributor's binary placement**
(`placement_parent_id` + `placement_side`). The **sponsor link
(`sponsor_id` / sponsorship tree) is never touched.** Both the distributor's
form and the admin screens must carry an explicit notice:

> *"This changes your binary tree placement only. Your sponsor stays the same."*

The distributor supplies a **target parent ADN only**. The admin chooses the
side (L/R) at approval time, pre-selected to the first free side (L preferred,
R fallback — mirroring `PlacementEngine::resolveSlot`). (Note: ADR-0003 removed
the old "Placement Strategy" setting; placement is invariant, so there is no
strategy setting to read.)

## 2. Background / current state

The existing implementation is entirely **sponsor**-based and must be renamed:

- `line_change_requests` columns: `from_sponsor_id`, `to_sponsor_id`.
- `RequestLineChange` enforces a "new **sponsor** must have joined earlier" rule.
- Exception `LineChangeNewSponsorTooNewError`.
- `LineChangeController` + `genealogy/line-change.blade.php` say "different sponsor".
- `LineChangeRequested` event carries sponsor ids.
- `LineChangeRequestTest` covers the sponsor-based request flow.

Confirmed mechanics that shape the design:

- The requester is always a **leaf** — the service already blocks anyone with
  downline (`genealogy_closure` depth ≥ 1). So moving them only rewrites
  *their own* closure rows; no descendant cascade.
- `PlacementEngine` already provides slot resolution (`resolveSlot`,
  `hasOpenSlot`), a per-`placement_id` MySQL advisory lock, and the unique
  index on `(placement_parent_id, placement_side)` — all reused for the move.
- The "parent must have joined before the child" invariant holds for the
  binary tree, so the existing seniority check stays, reframed from *sponsor*
  to *placement parent*.

## 3. Eligibility rules (request side)

`RequestLineChange` (reworked). A distributor may submit only when **all** hold:

1. Within **5 business days** of `effective_date` (unchanged;
   `LineChangeWindowExpiredError`).
2. Has **no downline** (unchanged; `LineChangeHasDownlineError`).
3. **No pending request** already open (unchanged;
   `LineChangeAlreadyRequestedError`).
4. **No previously *approved* line change** — one change per distributor, ever.
   New: `LineChangeAlreadyProcessedError`.
5. Target parent **joined before** the requester (reframed seniority rule).
   Renamed: `LineChangeNewSponsorTooNewError` → `LineChangeNewParentTooNewError`.
6. Target parent has **≥ 1 open slot** (L or R) at request time.
   New: `LineChangePlacementSlotFullError`.
7. Target parent is not self (unchanged guard) and not a couple-secondary
   (unchanged guard).

Distributor supplies **target parent ADN only**; no side at this stage.

## 4. Schema changes

Migration on `line_change_requests` (clean rename + additive columns):

- Rename `from_sponsor_id` → `from_placement_parent_id`.
- Rename `to_sponsor_id` → `to_placement_parent_id`.
- Add `chosen_side` `char(1)` nullable (L/R; null until approval).
- Add `reviewed_by` `unsignedBigInteger` nullable, FK → `users.id`
  (`restrictOnDelete`).
- Add `reviewed_at` `dateTime(3)` nullable.
- Add `decision_note` `string(1024)` nullable (admin's approve/reject note).
- Keep `reason` (distributor's words), `requested_at`, `approved_at`,
  `status` enum (`pending`, `approved`, `rejected`, `expired`).
- Rename FK constraint names accordingly (`fk_lcr_from`, `fk_lcr_to`).

`LineChangeRequest` model: update `$fillable`, `casts` (`reviewed_at` →
datetime), and relationships → `fromPlacementParent()`, `toPlacementParent()`,
`reviewer()` (BelongsTo User).

## 5. Approval execution

`ApproveLineChange` (new service, mirrors `ApproveKycSubmission`). Inside a DB
transaction, using the same MySQL advisory lock + unique-index protection as
`PlacementEngine`:

1. Lock the request row; assert status is `pending`.
2. Re-validate the target slot is still open (it can fill between request and
   approval) → `LineChangePlacementSlotFullError`.
3. Resolve **side**: admin picks L or R among free slots; the form pre-selects
   the first free side (L preferred, R fallback). Persist as `chosen_side`.
4. Update the requester row: `placement_parent_id`, `placement_side`,
   `side_chosen_by = 'referral_explicit'` (admin explicitly chose the side;
   reuses the existing enum — no enum-rewrite migration),
   `depth = newParent.depth + 1`.
   (`placement_id_at_registration` is historical and left untouched;
   `sponsor_id` is left untouched.)
5. **Rebuild closure rows**: delete rows where
   `descendant_id = requester AND depth >= 1`, then re-insert ancestor rows
   from the new parent's closure `+ 1` (plus the requester's own depth-0
   self-row stays). Leaf node ⇒ no descendant cascade.
6. Mark request `approved`; set `reviewed_by`, `reviewed_at`, `approved_at`.
7. Audit `genealogy.line_change.approved` with before/after placement.
8. Dispatch `LineChangeApproved`.

`RejectLineChange` (new service): requires a non-empty `decision_note`; sets
status `rejected`, `reviewed_by`, `reviewed_at`, `decision_note`; audits
`genealogy.line_change.rejected`; dispatches `LineChangeRejected`. No placement
is touched.

## 6. Admin UI

Mirrors `/admin/kyc`. Routes under the existing `/admin` `role:admin` group,
named `admin.line-changes.{index,show,approve,reject}`.

- `AdminLineChangeController@index` — list with tabs: **Pending** (default) /
  **Decided**. Columns: requester ADN + name, current parent ADN, requested
  target parent ADN, requested date, action link. Paginated. Tab count badges.
- `@show` — detail page: who is requesting; their current placement (parent
  ADN + side); the **target parent** (ADN + name + which slots are open); the
  distributor's reason; the binary-only notice; a **side selector** defaulted
  to the first free side (only free sides selectable). Approve
  button (submits `chosen_side`) + Reject form (requires `decision_note`).
- `@approve` — validates `chosen_side` ∈ open slots; calls `ApproveLineChange`;
  redirects to index with status. Surfaces `LineChangePlacementSlotFullError`
  as a back-with-error.
- `@reject` — validates `decision_note` (e.g. min 8, max 1024); calls
  `RejectLineChange`; redirects with status.

Views: `admin/line-change/index.blade.php`, `admin/line-change/show.blade.php`,
using `admin/layouts/admin.blade.php`.

**Platform UI conventions (apply here):** every form field gets a help/info
hover tooltip (description + impact); the Approve and Reject buttons each fire
a **confirmation modal** stating the action and its impact (placement moves;
sponsor unchanged) before submitting; the admin show page and the distributor
request form each carry a short **form-purpose note**.

## 7. Emails (queued notifications)

- **On request** (`LineChangeRequested` listener,
  `SendLineChangeRequestedMails`): notify **all admin-role users** **and** the
  **requester** ("we received your request"). Admin recipients come from a new
  `AdminNotificationRecipients::lineChangeReviewers()` method (active users with
  role `admin` or `admin-compliance`, mirroring the existing `compliance()`
  helper). Both include the binary-only notice and the target parent ADN.
- **On approve** (`LineChangeApproved` listener): notify the **requester**
  (done; new placement shown) and the **new placement parent** (consistent
  with the existing `NewPlacementUnderYouNotification` pattern). Reserved root
  ADNs are skipped, as in `SendPlacementCreatedMails`.
- **On reject** (`LineChangeRejected` listener): notify the **requester** with
  the admin's `decision_note`.

New notification classes follow the existing Genealogy notification pattern
(`via() => ['mail']`, constructor-injected data, mail view + params).

## 8. Distributor view updates

`genealogy/line-change.blade.php` + `LineChangeController`:

- Relabel "New sponsor ADN" → "New placement parent ADN".
- Rewrite copy to the binary-only framing (placement changes, sponsor stays).
- Render `approved` state (show the new placement) and `rejected` state (show
  the admin's note).
- If the distributor has **already used** their one line change (a prior
  `approved` request), show a terminal "you've already used your one line
  change" panel instead of the form.
- Apply platform UI conventions: help tooltip on the target-parent-ADN and
  reason fields, a form-purpose note at the top, and a confirmation modal
  (showing the binary-only impact) before the request submits.

## 9. Tests

- Update `LineChangeRequestTest` for the rename, the one-change-only rule, and
  the request-time slot check.
- New `ApproveLineChangeTest`: closure-rebuild correctness, `depth` update,
  slot-full-at-approval, side resolution (explicit + strategy default),
  sponsor untouched, status/reviewer fields set.
- New `RejectLineChangeTest`: status/note set, placement untouched.
- New admin controller coverage (index tabs, show, approve, reject happy +
  error paths).

## 10. Compliance notes

- No hard-rule conflict: line change is a genealogy position change, not a
  commission, payment, or PII change. No money flows (Phase 1).
- Audit entries on request, approve, and reject (admin actions logged with
  actor + before/after, per CLAUDE.md audit conventions).
- Phase 2+ TODO already tracked: reject line-change requests for distributors
  with any commerce activity. Out of scope here (no commerce in Phase 1).

## 11. Out of scope

- Couple-secondary as a *requester* (existing flow already targets primaries).
- Re-placing a non-leaf distributor (blocked by the no-downline rule).
- Commerce-activity guard (Phase 2+).
- Changing the 5-business-day window.
