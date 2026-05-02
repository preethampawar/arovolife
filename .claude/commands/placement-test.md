---
description: Run the full PlacementEngine regression suite and report pass/fail per scenario
allowed-tools: Bash(php artisan test:*), Bash(./vendor/bin/*), Bash(vendor/bin/*), Bash(grep:*), Read(**)
---

# /placement-test — PlacementEngine regression

Runs the full regression for the binary-tree placement engine:

```
cd app
./vendor/bin/pest tests/Modules/Genealogy --colors=always --coverage
```

After the run, summarise:
- Total scenarios from `placement-engine-spec/test-scenarios.md`
- How many are covered by automated tests
- Any scenario in the spec without a test (BLOCKING — every scenario must have a test)
- Property-based fuzzing seeds that failed (record them in `tests/Modules/Genealogy/seeds.txt`)

If any test fails, do NOT propose a fix immediately. Instead:
1. Print the failing assertion verbatim.
2. Re-read `placement-engine-spec/algorithm-proof.md`.
3. Decide whether the test is wrong or the implementation is wrong.
4. Open a stub ADR if the algorithm needs to change — never silently change it.
