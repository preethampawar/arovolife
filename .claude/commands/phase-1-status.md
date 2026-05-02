---
description: Emit a Phase-1 exit-gate checklist — which user stories are green, which compliance items are signed, security audit status
allowed-tools: Bash(git log:*), Bash(grep:*), Bash(find:*), Bash(php artisan test:*), Read(**), Glob(**), Grep(**)
---

# /phase-1-status — exit-gate checklist

Produce a one-page status snapshot for the Product Owner.

## Sections

1. **User stories US-1.01 … US-1.16** — for each, green / amber / red with one-line rationale.
   Source of truth: `backlog/phase-1-backlog.md` and the presence of tests exercising each story.
2. **Compliance items C-01 … C-09** — status and sign-off state.
   Source: `docs/compliance/risk-register.md` and `git log` for trailers starting with `Compliance-Review:`.
3. **Post-development security audit (10-point)** — per-item status.
   Source: `docs/security/audit-checklist.md` (edit it to mark items done in PRs).
4. **Exit gate (PRD §13)** — each numbered criterion with a tick or cross.
5. **Next 3 highest-priority items** — what the team should work on next, based on what's red.

## Format

Use a plain ASCII table (no HTML, no fancy Markdown) so the PO can paste
it into email. End with a single one-line verdict:

- `PHASE-1: READY FOR UAT`
- `PHASE-1: READY FOR SECURITY AUDIT`
- `PHASE-1: STILL IN BUILD (N items red)`
