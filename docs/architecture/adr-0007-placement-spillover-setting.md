# ADR-0007 — Optional binary spillover (admin-toggled)

- **Status:** Accepted (2026-06-06) — implemented **default-off**; switching the
  toggle **on in production** remains gated on PO + Compliance compensation sign-off
- **Date:** 2026-06-06
- **Deciders:** Product Owner, Compliance Officer, Laravel Architect
- **Amends:** ADR-0003 (does **not** fully supersede it — see "Relationship to ADR-0003")
- **Trigger:** Partner feedback (KP, WhatsApp 2026-06-04/05). A joiner invited
  under a node whose slot(s) were already filled was rejected
  (`/contact-us?reason=placement_full`). In a binary MLM the expected behaviour
  is **spillover** — the joiner auto-places into the next open position below
  the target. We shipped the clearer "placement full" message
  (commit `9b07c86`); this ADR decides whether/how to add the spillover itself.

## Context

ADR-0003 made placement **single-level**: a joiner is placed *exactly* at
`placement_id.<side>`, and if that slot is full the registration is rejected.
`PlacementEngine::resolveSlot()` never descends; the wizard's `start()` rejects
a full target up front.

Two facts shape this decision:

1. **Spillover is the binary-MLM norm.** Once a sponsor's chosen leg fills, new
   members normally "spill" to the next open position deeper in that leg. The
   current hard rejection blocks ordinary growth and confuses sponsors.
2. **But spillover changes downline composition**, which feeds future
   commission distribution (Phase 4+). It is a business-model lever, not a
   cosmetic change — so it must be a **conscious, reversible, audited** choice,
   not a silent default flip.

Therefore: add spillover, but **behind an admin toggle that defaults OFF**. With
the toggle off, behaviour is byte-for-byte ADR-0003 (including the new
`placement_full` message). With it on, the engine spills over.

## Decision

### 1. The toggle — admin **setting**, not a Pennant flag (recommended)

Add a boolean setting **`placement.spillover.enabled`**, default **`false`**.

| Option | Mechanism | Recommendation |
|---|---|---|
| **A (recommended)** | Admin **setting** in `AdminSettingsController::registry()` under a new `placement` group, read by the engine via the existing `settings` table | ✅ Matches the lineage (`placement.default_side`/`placement.allow_sponsor_override` lived here pre-ADR-0003), renders in the friendly grouped Settings UI with type+validation, and the settings-update path already writes an `audit_log` entry. It is a **business-rule strategy toggle**, which is what Settings is for. |
| **B** | Laravel **Pennant** flag (`PlacementSpillover` resolver, `/admin/feature-flags`) | Viable — Pennant auto-audits `feature_flag.toggled` and fits "off until admin opts in". But Pennant here is positioned for **killswitches / canary**, and `resolve()` is required to be stateless/global. A standing business rule reads better as a Setting. Keep as the fallback if we'd rather one toggle surface. |

> Sub-decision for sign-off: **Setting (A)** vs **Pennant (B)**. Both give a
> default-off, admin-flippable, audited toggle. The rest of this ADR assumes (A);
> swapping to (B) changes only the read site and the admin surface.

The toggle is **authoritative at finalisation** (`PlacementEngine::place()`), so
the placement rule is decided when the ADN is issued, not when the link was
opened. The wizard's `start()` reads it only to decide whether to pre-reject a
full target (see §4).

### 2. Spillover algorithm — choose one (recommended: A)

When `placement.spillover.enabled` is **on** and the immediate target slot is
full, the engine descends from the **link's placement target** to the first open
slot. The "group" semantics are preserved: a `side=L` link always lands the
joiner somewhere inside the target's **left** subtree.

| Option | Rule | Notes |
|---|---|---|
| **A (recommended) — directed BFS by group** | `side=L`/`R` → breadth-first search for the shallowest open slot **within that child's subtree** (i.e. starting at `target.L` / `target.R`). No `side` → BFS for the shallowest open slot under the target across **both** legs (balanced). | Honours the sponsor's explicit leg choice ("put them in my left group and let it spill"), stays shallow/balanced within the leg, deterministic. |
| **B — balanced BFS only** | Always shallowest open slot under the target, ignoring any `side`. | Simplest, but discards the sponsor's L/R intent. |
| **C — weaker-leg fill** | Place under whichever leg currently has fewer members. | "Auto-balance the org." Strong opinion about tree shape; least predictable for the sponsor; needs a per-call subtree count (cost + lock scope). |

BFS (not depth-first) is recommended so legs build **top-down and shallow**,
which is the usual spillover expectation and keeps `depth` (and thus future
traversal/closure costs) bounded.

### 3. Engine changes (`PlacementEngine`)

- Keep `resolveSlot()` as the **single-level** path (toggle off) — unchanged.
- Add `resolveSlotWithSpillover(int $targetId, ?string $sideOpt): array` →
  returns `[parentId, side, chosenBy]` where **`parentId` may be a descendant**
  of `targetId` (the spilled-into node). BFS per §2-A. A binary tree is never
  "full", so this always finds a slot.
- `place()` reads `placement.spillover.enabled`; picks `resolveSlot` (off) or
  `resolveSlotWithSpillover` (on). Everything downstream — `depth` from the
  **actual** parent, `writeClosureRows($id, $actualParentId)`, sponsorship,
  audit, events — already keys off the resolved parent, so it needs no change
  beyond using the returned `parentId`.

### 4. Wizard `start()` change

`start()` currently rejects a full target with `reason=placement_full`. Gate
that rejection on the toggle:

- Toggle **off** → unchanged (reject full target → `placement_full` page).
- Toggle **on** → skip the `hasOpenSlot` rejection entirely (the engine will
  always find a slot via spillover). Cross-line and unknown-ADN guards stay.

### 5. `side_chosen_by` audit values

Per ADR-0003's forward-compatibility clause, reintroduce enum values for the new
rule. Add:

| Value | When |
|---|---|
| `spillover_left` | toggle on, `side=L`, spilled into the left subtree |
| `spillover_right` | toggle on, `side=R`, spilled into the right subtree |
| `spillover_balanced` | toggle on, no `side`, shallowest open slot |

The existing `referral_explicit` / `referral_default` / `referral_fallback_right`
remain for the toggle-off path **and** for the toggle-on case where the joiner
still landed at the immediate target slot (no descent occurred). The intended
target is already persisted in **`distributors.placement_id_at_registration`**,
and the actual parent in `placement_parent_id` — so "did this spill, and from
where to where" is fully reconstructable **without a new snapshot column**
(this satisfies ADR-0003 forward-compat item 3; the column it asked for already
exists).

### 6. Race-safety

Spillover widens the critical section (we now traverse a subtree before insert),
so two registrations spilling under the same target could pick the same slot.
Defence in depth:

1. **Advisory lock on the link's placement target** — `GET_LOCK('placement:'||target_id, 5)`,
   as today. This serialises the common case (concurrent joins under the *same*
   target). The lock is held across traversal **and** insert.
2. **Unique index `(placement_parent_id, placement_side)`** — last-line defence,
   unchanged. It still catches the rarer **overlapping-subtree** race (target X
   and a deeper target Y inside X's subtree spilling onto the same node, where
   the two locks differ).
3. **Bounded retry** — wrap resolve+insert in a small retry loop (e.g. 3 tries)
   that, on a unique-index violation, re-runs the BFS (the just-taken slot is now
   visibly full) and tries the next open slot. Exhausting retries surfaces the
   existing `PlacementSlotsExhaustedError` (now practically unreachable, but kept
   as the safe failure).

(SQLite test driver still skips `GET_LOCK`; the unique index + single-threaded
runner keep tests correct, as today.)

## Relationship to ADR-0003

This **amends** ADR-0003 rather than superseding it: ADR-0003's single-level rule
remains the **default and the toggle-off behaviour** verbatim. ADR-0007 adds an
opt-in alternative. If the toggle is later defaulted **on** (or ADR-0003's path
removed), that future change supersedes ADR-0003 outright and should be its own
ADR.

## Consequences

- **Default-off = zero behaviour change on deploy.** Nothing changes until an
  admin (with PO/Compliance awareness) flips the setting. Fully reversible.
- **Compensation impact (the reason this needs sign-off).** With spillover on,
  members land deeper under existing distributors, changing downline counts and
  therefore future commission distribution (Phase 4+). Commissions remain
  **product-sale-only** (hard rule #2) and no earnings are projected (hard rule
  #3) — spillover only changes *who is under whom*, not whether money moves. But
  the distributional effect is real and is a PO/Compliance call.
- **No income/projection surface.** Public + registration copy is untouched; the
  joiner sees no earnings implication.
- **Closure table & team stats unaffected.** `writeClosureRows` and
  `TeamStatsService` already key off the actual parent; counts stay correct via
  the closure table (ADR-0001). No traversal-algorithm change.
- **Tree views unaffected.** Binary/sponsorship views render from the same data.
- **Auditability.** Every placement still writes `genealogy.placement.created`
  with `side_chosen_by` + intended-vs-actual parent; toggle flips are audited.
- **Demo seed.** With spillover on, sequential registrations under one root no
  longer cap at 2 — demo data can register many under a single link.
- **Line-change.** Unchanged window/rules; a spilled placement is line-changeable
  on the same terms.

## Compliance notes (for the officer)

- Hard rule #2 (commissions only on product sales) and #3 (no income
  projections): **unaffected** — spillover is structural, pre-revenue.
- Hard rule #6 (one PAN = one ADN), couple rows: unaffected; `-S` rows still
  excluded from the binary tree and normalised to primary at entry (commit
  `9b07c86`).
- DSR-2021: binary spillover is a recognised, permissible placement method; the
  toggle + audit trail document the company's conscious choice and when it
  applied. Recommend Compliance records the rule in the risk register and the
  T&C/plan description if/when enabled.

## Implementation plan (only after sign-off)

1. `placement.spillover.enabled` → `SettingsSeeder` + `ProductionSeeder`
   (default `'false'`) and `AdminSettingsController::registry()` (`bool`,
   group `placement`) + `groups()` label.
2. `PlacementEngine`: add `resolveSlotWithSpillover()`, branch in `place()`,
   retry loop on unique violation. New `side_chosen_by` enum values via a
   migration that widens the enum (no data backfill — Phase 1 has no prod users).
3. `RegistrationWizardController::start()`: gate the `placement_full` rejection
   on the setting.
4. `.claude/skills/arovolife-placement-engine/SKILL.md`: document both modes.
5. Update this ADR status to Accepted; cross-link from ADR-0003.

## Test plan

- **Toggle off (regression):** all existing placement + `REG-004/004b`
  (`placement_full`) tests stay green unchanged.
- **Toggle on — directed spillover:** target's `L` full → next join with `side=L`
  lands at the shallowest open slot in the left subtree (assert parent, side,
  depth, `side_chosen_by=spillover_left`, `placement_id_at_registration=target`).
- **Toggle on — balanced:** no `side`, both immediate slots full → shallowest
  open slot across the subtree.
- **Toggle on — start() accepts a full target** (no `placement_full` redirect).
- **Race:** property/concurrency test that two spillovers under the same target
  never collide (unique index + retry) and produce two distinct parents.
- **Closure integrity:** spilled node has correct closure rows to every ancestor
  up to the root; `TeamStatsService` counts reflect the deeper placement.
- Run via `/placement-test`.

## Decisions (signed off 2026-06-06 — owner went with all recommendations)

1. **Toggle mechanism:** admin **Setting** `placement.spillover.enabled`
   (group `placement`), default `false`. ✅
2. **Algorithm:** **directed BFS by group** (Option A). ✅
3. **No-side default under spillover:** **balanced BFS** (shallowest open slot
   across both legs). ✅
4. **Compensation sign-off:** accepted as a **gate on production enablement** —
   the feature ships default-off; PO + Compliance must sign off the
   downline-distribution effect before the toggle is switched on in prod. The
   `placement` Settings panel carries this caveat in its description.
