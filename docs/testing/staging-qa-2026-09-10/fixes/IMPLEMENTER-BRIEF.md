# Implementer brief — QA fix batches (read fully before touching code)

You are one of several implementers fixing findings from the staging QA run. Your batch is named in your task prompt. Work ONLY on your batch's findings and files.

## Repo and harness
- Repo root: `/Users/preetham/Documents/arovolife/arovolife/arovolife-code`. Laravel app is under `app/` (code in `app/app/Modules/...`, views `app/resources/views`, migrations `app/database/migrations`, tests `app/tests`). Use absolute paths; the shell cwd drifts.
- Branch `fix/staging-qa-2026-09-10` is already checked out. Do NOT create branches, do NOT push, do NOT touch `main`.
- Full finding text: `docs/testing/staging-qa-2026-09-10/00-plan.md` (table rows `| Fnn | ... |`). Read only your own rows (`grep -n '^| F81 '`). Your fix spec: `docs/testing/staging-qa-2026-09-10/fix-plan.md`, your batch section.
- Per-task evidence (repro detail) lives in `docs/testing/staging-qa-2026-09-10/results/Txx-*.md`; read only if the row is not enough.
- Tests: from `app/`, targeted SQLite: `php artisan test --filter=SomeTest` (fast). Schema-sensitive paths (enums, unique indexes, JSON, digests) must ALSO pass on MySQL inside the container: `docker exec arovolife-app sh -c 'cd /var/www/html && php artisan test --filter=SomeTest'`. Never run a bare full suite against the dev DB; the test DB is `arovolife_test` / SQLite only.
- Lint: `cd app && vendor/bin/pint --dirty`. Static analysis: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G <changed php files>` must pass at the configured level.
- Laravel 13 / PHP 8.4. Use current framework idioms (see `app/composer.json` versions). Prefer `mcp__laravel-boost__search-docs` if you need docs.

## Conventions (project CLAUDE.md, enforced)
- `declare(strict_types=1);`, PSR-12, `final` services, typed returns, form requests for validation, policies not just middleware, `{{ }}` only in Blade, Tailwind utilities, Lucide icons via `<x-lucide-*>`, all display numbers via `IndianNumber::format` / `@bv` (never `Number::format`).
- Money in paise, BV in bv_paise; `Bv::format(int $paise)` already divides by 100 — never pre-divide.
- User-facing terms: Genos (never "binary"), group (never "leg"), "Genos BV"; brand `arovolife` lowercase; every weaker/power/carry-forward figure carries an explicit Left/Right label.
- Every admin action / KYC change / settings change writes `audit_log` (`AuditLog::digest()` raw bytes, never hex).
- Never log PAN / Aadhaar / OTP / passwords / tokens; never print full PAN/Aadhaar/bank numbers anywhere, including test fixtures and your result file.
- Feature-flag zero-trace rule: flag OFF = no UI trace.
- Surgical changes only: touch what the finding needs; no drive-by refactors or formatting; match existing style. If you notice unrelated dead code, note it in your result file, don't delete it.
- Help docs: if you change admin behaviour, update the matching `app/resources/help/*.md` in the same commit.

## Hard limits
- NEVER connect to staging or production (no SSH, no remote MySQL, no HTTP to the staging host). Everything is local.
- NEVER run `migrate:fresh|reset|refresh|rollback`, `db:wipe`, `db:seed` against the dev DB, `truncate`, bulk deletes, `queue:clear`, or edit any `.env`. New migrations are forward-only and idempotent; test them on SQLite and MySQL (container).
- No browser tools. No subagents. Do not read files outside your batch's need. Keep your context small: read specific line ranges, not whole large files.

## Workflow per finding
1. Read the finding row + the fix spec. Locate the code (grep by class name; the fix plan lists the files).
2. Write/extend a test that reproduces the defect (Pest, under the matching `app/tests/...` path). Run it — it must fail.
3. Make the minimal fix. Run the test — it must pass. Run the nearby test class/dir too.
4. `pint --dirty`, phpstan on changed files.
5. Commit ONLY your files: `git add <paths>` (never `git add -A` / `git add .`). Message: `<type>(<scope>): <what>` + a body line `Fixes QA finding Fnn.` + trailers. Types: fix/feat/compliance/security/test/docs/chore. Add `Compliance-Review: compliance-officer (pending)` when the change touches money, KYC, consent, tree, placement, or public copy. Always end with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.
6. If a finding cannot be fixed as specified (spec wrong, needs a decision, blocked), do NOT improvise a product decision: record it as `[!]` with the reason and the options, and move on.

## Result file (write incrementally, after every finding)
`docs/testing/staging-qa-2026-09-10/fixes/<batch>.md` with, per finding: `Fnn — fixed|blocked|skipped`, files changed, test names, commit sha, one line on what changed, any follow-up. End with a `## Summary` listing counts and every `[!]`. Also flip the checkbox for each finding in `fix-plan.md` (`[ ]` → `[x]` or `[!]`) using a targeted edit of that single line.

Your final message to the orchestrator: the summary section only (no code dumps).
