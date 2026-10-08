# R.S.P. compensation updates — daily progress

Plan: `docs/plans/compensation-rsp-updates-2026-10-09.md` · Branch: `feat/compensation-rsp-updates-2026-10` · Runner: `scripts/rsp-runner/`

Order: 1, 2, 3, 4, 5, 6, 7, 8+9, 10, 11, 13, 12. One task per day. The user merges to `main` only after `FINAL-QA-signoff.md` says APPROVED.

| Date | Task | Outcome | Commits / blocker |
|---|---|---|---|
| 2026-10-09 | Task 1 | APPROVED and committed | 900e46b7 fix(repurchase): the 30-day window is inclusive of the anchor day; report commit follows. Main-side: 3 stale tests + phpstan baseline drift noted, not fixed |
| 2026-10-09 | Task 2 | APPROVED and committed | feat(msb): cap the daily MB point value at ₹120; report commit follows. Follow-up: settings form has no whole-rupee check on save (engine refuses, F-6) |
| 2026-10-09 | Task 3 | APPROVED and committed | cc7cb9d3 feat(msb): failed sponsors below the royalty rank earn no MB points; report commit follows. Hand-off: F-4 stale flag + sponsor_repurchase_failed to Task 4; gated path stays cap-free |
| 2026-10-09 | Task 4 | APPROVED and committed | 0b556a88 feat(msb): Mentorship Royalty daily cap for failed rank-6+ sponsors; report commit follows. Follow-ups: stale-flag lookup not warmed (1 query/accrual); whereDate on the locked day SUM |
