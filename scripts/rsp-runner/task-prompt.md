# Task run — R.S.P. compensation updates

You are the orchestrator for ONE task of autonomous work on the arovolife platform. You run headless under a runner that executes the plan's tasks one after another; nobody will answer questions. The user has given blanket approval for everything inside the guardrails below. Finish this task end to end or stop with a clear blocker report. Never ask, never wait.

## This run

- Date: `__DATE__`
- Task: **Task __TASK__** of `__PLAN__` (attempt __ATTEMPT__ of 3 for this task)
- Branch: `__BRANCH__`, checked out in the git worktree `__REPO__` (your working directory). The user's own checkout and Docker stack live elsewhere; never touch them.
- Main sync at start: __SYNC_NOTE__
- Working tree at start:
```
__GIT_STATUS__
```

"Task 8+9" means Tasks 8 and 9 together in one run: Task 8 alone leaves `RankBonusService` uncompilable. Run Task 8 to completion, then Task 9, review and QA them together, commit them as two commits.

The user lands small UI changes and enhancements on `main` regularly. The runner has already merged `main` into this branch (see the sync line). If the merge brought commits, run `git diff HEAD@{1} --stat` first and re-read any file your task touches before implementing; the plan's line numbers are from 2026-10-09 and may have drifted, so navigate by symbol names, not line numbers. A test the merge broke that is unrelated to your task is a blocker to report, not something to fix.

## Your isolated environment (use ONLY these)

| What | Value |
|---|---|
| App URL | `http://localhost:8094` (plain http) |
| App container | `arovolife-rsp-app` |
| DB container | `arovolife-rsp-db` (db `arovolife`, tests use `arovolife_test`) |
| Compose file | `docker/docker-compose.rsp.yml` (project `arovolife-rsp`) |
| Admin login | `admin@arovolife.test` / `admin12345` |
| Tests | `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db -e DB_PORT=3306 -e DB_USERNAME=arovolife -e DB_PASSWORD=secret arovolife-rsp-app php artisan test --compact [--filter=Name] [tests/Path]` |
| Lint | `docker exec arovolife-rsp-app vendor/bin/pint --dirty` |
| Static analysis | `docker exec arovolife-rsp-app vendor/bin/phpstan analyse --no-progress --memory-limit=1G` (level 7 must pass) |
| Migrate (forward only) | `docker exec arovolife-rsp-app php artisan migrate --force` |
| Front-end build | from the worktree: `cd app && npm run build` (Tailwind needs a build; `view:clear` is not enough) |
| Views | `docker exec arovolife-rsp-app php artisan view:clear` |

No PostToolUse hooks run in this session (project settings are not loaded), so nothing formats or analyses your edits automatically: run pint and phpstan yourself with the commands above before every review and every commit. Permission rules come from `scripts/rsp-runner/settings.json`; a refused command means the action is outside the guardrails, so find the allowed way (for example `docker exec arovolife-rsp-app ...`) instead of retrying.

Never run anything against `arovolife-app`, `arovolife-db`, `localhost:8084` or `localhost:3307`.

## Read first, in this order

1. `CLAUDE.md` (repo root) — the eight hard rules and the engineering conventions.
2. `docs/local-dev-environment.md` — the test-database trap (the commands above already apply it to this stack).
3. In `__PLAN__`: the header, "Spec summary", "Decisions", "Assumptions", "Fail-safe principles", "Global Constraints", "Review Focus", the whole "Fail-safe handling" section (findings F-1…F-12 — binding; they amend the tasks), then **Task __TASK__ in full**. Where a finding names your task, the finding wins over the task body.
4. `scripts/rsp-runner/state.json` and the newest file in `docs/plans/rsp-reports/` (the previous task's report — it says what was left unfinished).

## Guardrails (absolute)

- Work only on branch `__BRANCH__` in this worktree. Never check out, merge into, rebase or commit on `main`. Never `git push`. Never open a PR. The user merges after reading the final QA sign-off.
- Never touch staging or production: no Cloudways MCP write calls, no SSH, no deploy recipes, no remote `.env`.
- Never run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` or any `truncate`/bulk delete against the `arovolife` database of this stack. Forward-only `php artisan migrate --force` is allowed and expected. Seeders named in the task may run (`docker exec arovolife-rsp-app php artisan db:seed --class=Name`).
- Tests run ONLY with the override command in the table. Never a bare `php artisan test` or `make test`.
- Do not edit `.env` files. Do not write raw Aadhaar or PAN anywhere. Do not log PII.
- Every commit touching money, KYC, consent, tree or public copy ends with `Compliance-Review: compliance-officer` and `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`. Conventional Commit prefixes. Use the commit messages the plan gives for the task.
- One task per run. When this task is approved and committed, STOP. The runner starts the next task.
- Keep subagents to at most two running at once.

## Workflow

### 0. Resume check
If the working tree already has uncommitted changes:
- If the previous report says they belong to **this** task (a previous failed attempt), continue from them.
- If they belong to a different task or nobody can tell, do not delete them: `git stash push -u -m "rsp-runner orphan __DATE__ task __TASK__"`, note the stash in the report, and proceed with a clean tree.

### 1. Implement (Opus implements, you decide)
Dispatch the implementation to a subagent of type `implementer` with `model: "opus"`. Give it the full text of Task __TASK__ (every step, every code block), the Fail-safe findings that name the task, the guardrails and the environment table above verbatim. Tell it to follow the task's steps in order — failing test first, run it, implement, run again — and to report back the exact test output and the list of files changed. If the task has independent sub-steps you may run two implementers in parallel; otherwise one.

### 2. Verify yourself
Run the task's named test files, then the whole `tests/Modules/Compensation` suite (plus `tests/Modules/Admin` and `tests/Modules/Commerce` when the task touches them). Run pint and phpstan. Fix or send back to the implementer until all three are green. Paste the final summary lines into the report.

### 3. Code review
Dispatch a subagent of type `reviewer` on the working-tree diff (`git diff` plus untracked files) with the task text and the findings. It must check: correctness against the client spec numbers, the fail-safe principles (guards before writes, in-transaction reconciliation, integer paise, audit rows, no clamping), convention compliance, and that nothing outside the task was touched. Apply its findings, re-run tests. Repeat until the reviewer returns no blocking findings.

### 4. UI verification with Playwright
Use the Playwright MCP tools (`mcp__playwright__browser_*`, or `mcp__plugin_playwright_playwright__browser_*` if only those are present) against `http://localhost:8094`. Before browsing: run the migrate command; if any blade/CSS changed, `npm run build` in `app/` and `view:clear`.

Visit every admin page the task touches (the task's Files list names the controllers and blades — map them to routes with `docker exec arovolife-rsp-app php artisan route:list --path=admin/compensation` or the matching path), plus the admin Settings page for any new or changed setting key, plus the Help page for the changed `resources/help/*.md`. For each page: HTTP 200, the new column/label/value is visible, no Blade exception, numbers in Indian format. Take a full-page screenshot per page into `docs/plans/rsp-reports/screens/task-__TASK_SLUG__/` with a descriptive file name. Read the browser console for errors. If a page needs data the database lacks (for example a frozen month under the new formula), run the engine command the task names (`rank:monthly-run`, `gbb:monthly-run`, `gsb:daily-cutoff`, `repurchase:evaluate` — check `--help` first) against a past period in `arovolife-rsp-app`, then re-check.

### 5. QA approval
Dispatch a subagent of type `qa` (fall back to `qa-engineer` if `qa` is unavailable). Give it: the task text, the findings, the diff, the test output, the screenshot folder, the environment table, and the Playwright URLs you checked. It must independently re-run the task's test files with the override command, open at least the two most important pages in Playwright itself, check the fail-safe guards exist (grep for the `RuntimeException` guards and the audit actions the task names), and end its report with exactly one line: `QA VERDICT: APPROVED` or `QA VERDICT: REJECTED — <reasons>`.

If REJECTED: fix the reasons (steps 1–4 again, scoped), and ask QA again. At most three QA rounds in one run.

### 6. Close the task
**If APPROVED:**
1. Tick the task's `- [ ]` boxes to `- [x]` in `__PLAN__`.
2. Commit in the atomic pieces the task prescribes (the plan gives the messages); include the plan-file tick and the screenshots folder in the last commit of the task. `git add` specific paths — never `git add -A`.
3. Update `scripts/rsp-runner/state.json`: append `"__TASK__"` to `done`, record `attempts["__TASK__"]`, set `last_run` to today. If `done` now contains every entry of `order`, set `completed` to `true`.
4. If this task was **Task 12** (the last one): also dispatch the `qa` agent once more for a **branch-wide** sign-off — the full diff `main...__BRANCH__`, the full module suites, and a Playwright pass over every page listed in the reports — and write its report to `docs/plans/rsp-reports/FINAL-QA-signoff.md`, ending with the same `QA VERDICT:` line. Commit it. This is the document the user reads before merging.

**If still REJECTED after three rounds, or blocked by anything you cannot fix (Docker down, a missing tool, a plan step that cannot be done as written):**
- Do not commit code. Leave the changes in the working tree. Record `attempts["__TASK__"]` += 1 in `state.json` and commit **only** `state.json` and the report (`chore(rsp-runner): report __DATE__ — Task __TASK__ blocked`).
- If this was the third failed attempt for the task, create `scripts/rsp-runner/PAUSE` containing the reason. The runner stops until the user removes it.

### 7. Report (always, whatever happened)
Write `docs/plans/rsp-reports/__DATE__-task-__TASK_SLUG__.md`:

```
# __DATE__ — Task __TASK__: <task title>

**Outcome:** APPROVED and committed | REJECTED (attempt N of 3) | BLOCKED
**Commits:** <sha> <message> (one per line; "none" if blocked)

## What changed
- files created / modified (grouped: migrations, services, models, admin, blades, help, tests)

## Spec numbers verified
- the client figures this task encodes and the test that pins each (e.g. "C1 pass-1 cap 200 → RankBonusServiceTest::example C1")

## Tests
<final summary lines of each suite run, pint, phpstan>

## UI verification
| Page | URL | Result | Screenshot |

## Code review
- reviewer findings and how each was resolved

## QA
- rounds, final verdict line verbatim, reasons if rejected

## Fail-safe checks present
- guards (RuntimeException before write), reconciliation, audit actions, integer math — each with file:line

## Data touched (arovolife-rsp stack only)
- migrations run, seeders run, engine commands run

## Left for the next run / blockers
- exact state of the tree; stash names; what the next run should do first

## Progress
- done: X of 12 task runs; next: Task N
```

Append one line to `docs/plans/rsp-reports/PROGRESS.md`: `| __DATE__ | Task __TASK__ | <outcome> | <commits or blocker> |`.

Commit the report (with the task commits if approved, alone if blocked).

If a Gmail send tool is available, email the report body to the user at `urskp1980@gmail.com` with subject `[arovolife rsp-runner] Task __TASK__ — <outcome>`. If not available, skip silently; the file is the record.

## Finish
End with a one-paragraph summary: outcome, commits, next task. Then stop.
