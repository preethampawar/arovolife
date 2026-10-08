# rsp-runner — the R.S.P. plan, task after task, autonomously

Drives `docs/plans/compensation-rsp-updates-2026-10-09.md` to completion on the
branch `feat/compensation-rsp-updates-2026-10`, one task per headless Claude
Code session, sessions run back to back. Each task ends in a QA-approved set of
commits or a blocker report that stops the sequence. Nothing is pushed or
merged; the user merges to `main` after reading
`docs/plans/rsp-reports/FINAL-QA-signoff.md`.

## Isolation (why there is a second worktree and a second Docker stack)

The user keeps working on `main` in the regular checkout
(`arovolife-code`, stack `arovolife`, http://localhost:8084). This branch's
migrations drop columns `main` still reads, so the runner lives in:

- git worktree `/Users/preetham/Documents/arovolife/arovolife/arovolife-rsp`
- Docker project `arovolife-rsp` from `docker/docker-compose.rsp.yml`
  (app `arovolife-rsp-app`, web http://localhost:8094, MySQL :3317, Redis :6389, Mailpit :8037 — all bound to 127.0.0.1)
- its own DB volume `arovolife_rsp_dbdata`, seeded once from a dump of the dev DB

Before every task the runner merges `main` (and `origin/main` when reachable)
into the branch, so the work always sits on top of the latest main. A merge
conflict stops the sequence with a `<date>-merge-conflict.md` report.

## Pieces

| File | Role |
|---|---|
| `state.json` | Task order, what is done, attempt counts. Each run updates it. |
| `task-prompt.md` | The self-contained brief the headless session receives (placeholders substituted). Edit this to change how a task runs. |
| `run.sh` | Preflight (stack up, app answering, right branch, main merged), picks the next task, runs `claude -p`, watchdog, notification; loops to the next task while the previous one succeeded. |
| `logs/` | One log per day plus the raw transcript `<date>.task-<n>.out`. Git-ignored. |
| `PAUSE` | If present, the runner exits. Created automatically after three failed attempts on one task. |

Reports: `docs/plans/rsp-reports/<date>-task-<n>.md`, `PROGRESS.md`,
screenshots under `screens/task-<n>/`, and at the end `FINAL-QA-signoff.md`.
All committed to the branch.

## Each task

1. Implementer subagent (Opus) builds it test-first, exactly as the plan writes it.
2. Orchestrator (Fable) runs the task's tests, the Compensation suite, Pint and Larastan against `arovolife_test` in the rsp stack.
3. Reviewer subagent reviews the diff; findings fixed.
4. Playwright pass over every admin page the task touches on :8094; screenshots saved.
5. QA subagent re-runs tests and Playwright independently → `QA VERDICT: APPROVED|REJECTED`. Up to three rounds.
6. Approved → plan checkboxes ticked, atomic commits with the compliance trailer, `state.json` advanced, report written → runner starts the next task. Rejected/blocked → no code commit, report written, runner stops.
7. Task 12 (last) also produces the branch-wide `FINAL-QA-signoff.md`.

Order: 1, 2, 3, 4, 5, 6, 7, 8+9 (one run), 10, 11, 13, 12.

## Permissions

No blanket bypass. The session runs with `--permission-mode acceptEdits`,
`--setting-sources user` and `--settings scripts/rsp-runner/settings.json`,
which holds an exact-prefix allow list (only the `arovolife-rsp-*` containers) and a deny list (`git push/merge/rebase/checkout`, interpreters, `rm`, `docker run`, Gmail/Drive/Cloudways/GitHub/Razorpay connectors,
`gh`, `curl`, `ssh`, `rm -rf`, `.env` edits, destructive artisan commands, a
bare `php artisan test`, the user's `arovolife-app`/`arovolife-db` containers,
Cloudways/GitHub/Razorpay MCPs). Project settings are deliberately not loaded:
the repository's hooks run Pint inside the user's dev container and would
format files in the main checkout. The worktree's `.claude/` holds only the
project agents and skills, copied from the main checkout.

## Operating it

```bash
cd /Users/preetham/Documents/arovolife/arovolife/arovolife-rsp

bash scripts/rsp-runner/run.sh --dry-run      # preview the next task's prompt
bash scripts/rsp-runner/run.sh --smoke        # prove headless auth works
nohup bash scripts/rsp-runner/run.sh > /dev/null 2>&1 &   # run every remaining task
bash scripts/rsp-runner/run.sh --once         # just the next task
bash scripts/rsp-runner/run.sh --task 8+9     # a specific task

touch scripts/rsp-runner/PAUSE                # stop after the current task
rm scripts/rsp-runner/PAUSE                   # resume (then start run.sh again)

tail -f scripts/rsp-runner/logs/$(date +%F).log
tail -f scripts/rsp-runner/logs/$(date +%F).task-1.out

docker compose -f docker/docker-compose.rsp.yml ps        # the isolated stack
docker compose -f docker/docker-compose.rsp.yml down      # stop it (keeps the DB volume)
```

Knobs: `RSP_MAX_HOURS` (default 6 per task), `RSP_MODEL` (default
`claude-fable-5-1`; the implementer subagent is always Opus).

## After the last task

Read `docs/plans/rsp-reports/FINAL-QA-signoff.md`. If it says APPROVED, merge
`feat/compensation-rsp-updates-2026-10` into `main` from the regular checkout,
run `php artisan migrate` on the dev stack, and remove the worktree
(`git worktree remove ../arovolife-rsp`) and the rsp stack
(`docker compose -f docker/docker-compose.rsp.yml down -v` — this deletes the
rsp DB volume only).
