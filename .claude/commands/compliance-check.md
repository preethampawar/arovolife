---
description: Audit the current diff against DSR 2021 and the eight hard rules; invoke the compliance-officer subagent
allowed-tools: Bash(git status:*), Bash(git diff:*), Bash(git log:*), Bash(grep:*), Bash(find:*), Read(**), Glob(**), Grep(**)
argument-hint: "[base-ref]"
---

# /compliance-check — DSR 2021 compliance audit

Audit the changes on this branch (or since `$ARGUMENTS` if supplied,
otherwise since `main`) against:

1. The eight hard rules in `CLAUDE.md`.
2. `docs/compliance/dsr-2021-mapping.md` — every new endpoint, field, or
   user-facing string that touches an item in the mapping must be covered.
3. `docs/compliance/risk-register.md` — re-assess each standing risk
   against the diff.
4. The skill `.claude/skills/arovolife-compliance-rules/`.

## Procedure

1. `git status` + `git diff <base>...HEAD`.
2. Delegate the analysis to the **compliance-officer** subagent (in
   `.claude/agents/compliance-officer.md`). Pass it the diff and the
   above references.
3. Compile a findings table:

   | Severity | Finding | File:line | Suggested fix |
   |---|---|---|---|

   Severity key:
   - **Critical** — a hard rule would be violated if this merges.
   - **High** — statutory risk is created but not yet realised (e.g., missing validator).
   - **Medium** — best-practice or audit-trail gap.
   - **Low** — style/tone suggestion.

4. Block the merge if any Critical finding exists.
5. Suggest an explicit list of code/copy changes.

## Output format

Start with a one-line verdict:
- `COMPLIANCE: PASS` — no Critical/High findings.
- `COMPLIANCE: PASS WITH RECOMMENDATIONS` — Medium/Low findings only.
- `COMPLIANCE: FAIL` — one or more Critical/High findings. List them at the top.

Then the findings table, then a prioritised remediation list.
