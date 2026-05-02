# placement-engine-spec/

This folder is the authoritative specification for the binary-tree
placement logic. It is the **walking skeleton** for Phase 1 — the first
piece Claude Code implements after the project is bootstrapped, because
every other Phase-1 feature depends on it being correct.

Files:

- `README.md` — this file
- `algorithm-proof.md` — formal statement of the algorithm + invariants
- `PlacementStrategyResolver.pseudo.php` — pseudocode reference
- `PlacementEngine.pseudo.php` — pseudocode reference
- `test-scenarios.md` — every scenario that must have an automated test

## Why this spec exists separately from the Laravel code

The placement engine is compliance-adjacent. The algorithm, the
invariants, and the scenarios are the *contract* that the Laravel
implementation honours. Keeping the spec outside the Laravel app makes
reviews easier and prevents the code from being the source of truth for
behaviour that the Compliance Officer must approve independently.

## Changing the algorithm

Requires an ADR that supersedes ADR-0001 and/or ADR-0002. The spec
is updated *first*; the implementation changes *second*; tests
change *last*. In that order. Never change the tests to make a new
implementation pass.
