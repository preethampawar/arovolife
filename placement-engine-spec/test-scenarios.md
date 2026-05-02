# Placement Engine — Test Scenarios

Every scenario in this file MUST have a corresponding automated test in
`tests/Modules/Genealogy/`. CI will fail (via `/placement-test`) if any
scenario is unmapped.

## Scenario index

### Strategy resolution (PlacementStrategyResolver)

- **PSR-01** strategy=`default_left`, side=null → `('admin_default','L')`
- **PSR-02** strategy=`default_right`, side=null → `('admin_default','R')`
- **PSR-03** strategy=`default_left`, side=`L` → `('admin_default','L')` (no override needed)
- **PSR-04** strategy=`default_left`, side=`R`, override=true → `('sponsor_override','R')`
- **PSR-05** strategy=`default_left`, side=`R`, override=false → throws SideOverrideForbiddenError
- **PSR-06** strategy=`default_right`, side=`L`, override=true → `('sponsor_override','L')`
- **PSR-07** strategy=`custom`, side=`L` → `('prospect_custom','L')`
- **PSR-08** strategy=`custom`, side=`R` → `('prospect_custom','R')`
- **PSR-09** strategy=`custom`, side=null → throws SideRequiredError

### First-slot walk (PlacementEngine.findFirstEmptySlot)

- **WLK-01** Empty tree under `sponsor`; placement_id=sponsor, side=L → parent=sponsor, side=L, depth=1
- **WLK-02** Same as WLK-01 with side=R → parent=sponsor, side=R, depth=1
- **WLK-03** sponsor has L child, no R child; side=L → parent=L_child, side=L, depth=2
- **WLK-04** sponsor has L child whose L child also exists; side=L → parent=L_child.L_child, side=L, depth=3
- **WLK-05** sponsor full to depth 5 on L spine; side=L → depth=6 placement at end of spine
- **WLK-06** sponsor spine fills the chosen side but the OTHER side is open everywhere; side=L → still fills L spine, never crosses

### Descendant validation

- **DESC-01** placement_id == sponsor_id → accepted
- **DESC-02** placement_id is a direct child of sponsor → accepted
- **DESC-03** placement_id is 5 levels under sponsor → accepted
- **DESC-04** placement_id is the parent of sponsor (upline) → CrossLinePlacementError
- **DESC-05** placement_id is in a sibling subtree → CrossLinePlacementError
- **DESC-06** placement_id is a separately-rooted distributor → CrossLinePlacementError
- **DESC-07** placement_id does not exist → not-found error (not CrossLinePlacementError)

### End-to-end placement (PlacementEngine.place)

- **PE-01** Happy path: brand-new prospect under existing sponsor, default_left, no placement_id, no side. Asserts row inserts, closure rows, sponsorship row, two events emitted.
- **PE-02** placement_id 3 levels deep + default_left → placement at depth 4 on the L spine of placement_id.
- **PE-03** placement_id 3 levels deep + default_right + sponsor_override + side=L → placement at depth 4 on the L spine.
- **PE-04** placement_id 3 levels deep + default_right + override DISABLED + side=L → SideOverrideForbiddenError; nothing inserted.
- **PE-05** strategy=custom, side missing → SideRequiredError; nothing inserted.
- **PE-06** placement_id outside sponsor downline → CrossLinePlacementError; nothing inserted; audit-log row written.

### Concurrency

- **CONC-01** Two placements under the same `placement_id` simultaneously, side=L. Both succeed. The first occupies (parent_id, L) at depth N+1. The second occupies (the_first, L) at depth N+2. No unique-index violation surfaces.
- **CONC-02** Lock timeout simulation: assert the upper layer retries with back-off and eventually succeeds.
- **CONC-03** Bypass the lock (test seam): the unique index `uniq_distributors_slot` raises a duplicate-key error and the transaction rolls back; nothing is half-written.

### In-flight strategy change

- **INF-01** Session opens with strategy=`default_left`. Admin flips to `default_right`. Session finalizes. Placement uses `default_left` (snapshot wins). Rows record `placement_strategy_snapshot='default_left'`.
- **INF-02** New session started AFTER the flip uses `default_right`.

### Audit trail

- **AUD-01** A successful placement writes an `audit_log` row with `action='genealogy.placement.created'` and `details` JSON containing snapshot + chosen side.
- **AUD-02** A rejected cross-line placement writes an `audit_log` row with `action='genealogy.placement.rejected'` and reason.
- **AUD-03** A change to `placement.default_side` writes an `audit_log` row with `action='admin.settings.changed'`, before/after, reason, ip.

### Property-based / fuzz

- **PROP-01** For random sequences of 100 placements with random
  sponsor_id from existing nodes and random Placement Strategy flips
  between placements, all six invariants (I-1 to I-6) hold.
- **PROP-02** For random replays of the same input sequence with
  different random seeds, the resulting tree shape is identical.

### Performance

- **PERF-01** With a 1M-row seeded tree, p95 placement latency ≤ 250 ms across 1000 placements.
- **PERF-02** With 100 concurrent placements, no deadlocks, no failures, p99 ≤ 1 s.

### Compliance / contractual

- **CMP-01** No placement is performed if `cooling_off_end_at` would be in the past (clock-skew defence).
- **CMP-02** Distributor created via placement is in `pending` status until KYC + orientation + agreement are all green.
- **CMP-03** placement_id_at_registration column is NULL when the prospect did not supply one.
- **CMP-04** placement_strategy_snapshot is NEVER mutated after insert. (Property test on a long-running session.)
