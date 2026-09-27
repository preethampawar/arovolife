# Engine fail-safety (Batch 2) — verification after deploy

Plan: `docs/plans/engine-fail-safety-2026-09-27.md`. Merged to `main` as `ef205f69` (E1–E4 only), deployed 2026-09-27 to staging then production (`app:deploy --maintenance`, all 14 steps green on both).

E5 (skip-and-continue for repurchase evaluation failures) is **not deployed**: the compliance-officer review failed it on two High findings (a skipped day can be lost for good on production; late by-name retries overspend the frozen GSB and MSB pools). User decision 2026-09-27: ship E1–E4, redesign E5. Its commit `accbe09c` stays on `fix/engine-fail-safety`.

| # | Check | Environment | Result |
|---|---|---|---|
| 1 | `app:status` after deploy: scheduler ticking, compensation worker up, no failed jobs since deploy | staging | ✅ scheduler 34 s, compensation worker idle, backlog empty, no failed jobs in 24 h |
| 1 | same | production | ✅ scheduler 46 s, all three workers idle, backlog empty, 0 failed jobs |
| 2 | `CompensationQueueBacklog::depth()` reads the queue | staging | ✅ `0` on the idle queue |
| 2 | same | production | ✅ `0` |
| 2 | Registry helpers on the deployed code: `descendantKeys('compensation.nightly-run')`, `ancestorKeys('payout.monthly')`, `payout.monthly` flag class | staging | ✅ `[repurchase.evaluate, gsb.daily-cutoff]`, `[compensation.monthly-payout-close, compensation.monthly-run]`, `GenosSalesBonusFeature` |
| 2 | same (descendants + flag) and `inFlightRefusal()` on an idle platform | production | ✅ same values; `inFlightRefusal()` returns null |
| 3 | In-flight guard: synthetic `running` row for `gsb.daily-cutoff` (2026-09-26, manual), then `gsb:daily-cutoff --date=2026-09-26` | staging | ✅ refused: "A GSB Daily Cut-off (incl. MSB) run started at 11:02 is still in flight (run #209), so … was held back rather than write the same rows beside it." New run row #210 `skipped` with that reason; `gsb_cutoff_results` for the date unchanged (317 before, 317 after). Both synthetic rows deleted afterwards (2 rows) |
| 4 | Admin trigger refusal while the orchestrator is in flight | staging | ⏸ not observable: staging is a recompute environment and hides the per-engine trigger forms. Pinned by `AdminEngineRunsControllerTest` ("refuses to trigger an engine while its orchestrator is in flight"). Not attempted on production (no triggers on production data) |
| 5 | Next morning: nightly run rows show the queue wait passed and both steps succeeded; 08:00 digest reports nothing new | staging / production | ⏳ pending — 2026-09-28 |
| 6 | E1 (flag-off monthly payout → skipped) and E2 (atomic no-match settle) | both | ⏸ pinned by tests only — no observation possible without switching the compensation flag off or crashing a settle. `EngineRunRecorderTest` and `GsbCutoffServiceTest` cover them |

Behaviour pinned by tests (1,651 green across `tests/Modules/Compensation`, `tests/Feature/Compensation`, `tests/Feature/ActionCenter`, `tests/Feature/RunClockTest.php`, 9,807 assertions, on the branch with E5 included; E1–E4's own files re-run green on `main` before the merge by the implementer).

Compliance review: compliance-officer over `git diff main...HEAD` on 2026-09-27 — E1–E4 sound; E5 FAIL (two High), held back. Review notes carried into the E5 redesign: relative skip cap and fail-closed on a single exception class; a `--distributor` cut-off should verify the distributor was re-evaluated; an unreadable `jobs` table counts as an empty queue with no alert (E4, open); E2 needs a forced-deadlock test on dev MySQL (open).
