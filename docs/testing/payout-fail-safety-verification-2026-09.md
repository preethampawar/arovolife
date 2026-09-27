# Payout fail-safety (Batch 1) — verification after deploy

Plan: `docs/plans/payout-fail-safety-2026-09-27.md`. Merged to `main` as `661552b2`, deployed 2026-09-27 to staging then production (`app:deploy --maintenance`, all checks green on both).

| # | Check | Environment | Result |
|---|---|---|---|
| 1 | `schedule:list` shows `payouts:reconcile` (30 9,16) and `payout:auto-retry-failed` (0 11), Asia/Kolkata | staging | ✅ both listed |
| 1 | same | production | ✅ both listed |
| 2 | `payouts:reconcile --dry-run` runs clean | staging | ✅ exit 0 — "Payout gateway is manual_neft — nothing to reconcile." |
| 2 | same | production | ✅ exit 0 — same message |
| 3 | Deploy status: scheduler ticking, compensation worker, no failed jobs since deploy | staging | ✅ scheduler 62 s, no failed jobs in 24 h. "Worker: compensation 2 processes" = the idle Cloudways Redis supervisor worker + the one flock'd database worker (`/tmp/arovolife-q-compensation.lock`); only the database worker takes payout jobs |
| 3 | same | production | ✅ scheduler 43 s, workers idle, 0 failed jobs |
| 4a | Razorpay end to end (fan-out, `kill -9` mid-batch, auto-retry adoption, Send again lookup order, Action Center items) | staging | ⏸ deferred — user decision 2026-09-27: run once Batches 2–4 are complete. Staging gateway is `manual_neft` and RazorpayX is not configured; these paths are pinned by tests only until then |
| 4b | Failed-batch rebuild: batch 15 (weekly, 2026-09-22, `pending`, never approved, 0 lines) forced to `failed`, then `compensation:weekly-run --date=2026-09-22` | staging | ⏳ pending — needs the user to run the staging write (blocked for the agent) |
| 5 | 08:00 engine health digest reports nothing new as stuck | staging / production | ⏳ pending — next digest 2026-09-28 08:00 IST |
| 6 | Production read-only: `schedule:list`, `payouts:reconcile --dry-run`, deploy status | production | ✅ see rows 1–3 |

Behaviour pinned by tests (1,753 green across Compensation, Payments, Action Center plus the scheduled-command check; 301 for the NEFT-export guard): PD-01…09, PF-01…05, PR-01…05 plus bank-file and unreachable cases, planner re-owe pairs (weekly + monthly), failed-sweep re-entry, both Action Center providers, NEFT export refused in Razorpay mode.

Compliance review: compliance-officer, APPROVE WITH NOTES on `9cc847af`; notes closed in `63aa9e3a` and `47b95900` (R-112 option A, user decision 2026-09-27).
