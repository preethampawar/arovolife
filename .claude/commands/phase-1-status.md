---
description: Emit a Phase-1 exit-gate checklist — which user stories are green, which compliance items are signed, security audit status
allowed-tools: Bash(cd app && php artisan phase:status*), Bash(git log:*), Read(**), Glob(**), Grep(**)
---

# /phase-1-status — exit-gate checklist

Run the command and relay its output:

```
cd app && php artisan phase:status
```

Run it **from the host checkout, not inside the Docker container** — it reads
`docs/` and `backlog/`, which sit above the Laravel app and are not mounted
into the image. The command says so itself if it cannot see them.

## What it reports

1. **User stories US-1.01 … US-1.16** — green / amber / red, parsed from the
   story-to-sprint map in `backlog/phase-1-backlog.md`.
2. **Compliance items C-01 … C-09** — signed or unsigned, from
   `docs/compliance/risk-register.md`, plus a count of `Compliance-Review:`
   trailers in the git log. A trailer is evidence a review happened; it is not
   a signature on C-01…C-09, and the command says so.
3. **Post-development security audit (10-point)** — the verdicts from the most
   recent audit-run table in `docs/security/audit-checklist.md`.
4. **Exit criteria (PRD §11)** — nine criteria. Only the ones that are
   repository facts get a state; the staging measurements and human sign-offs
   are reported as UNVERIFIED or NEEDS-A-HUMAN rather than assumed green.
5. **Next up** — the top of the roadmap's final sign-off gate.

## After running it

If anything is red or unverified, say what would move it. The command reads
documents, so a stale document produces a stale snapshot — if a section looks
wrong, check whether the document is behind the code rather than trusting
either one.

Do not paraphrase the verdict line. Quote it exactly:

- `PHASE-1: READY FOR UAT`
- `PHASE-1: STILL IN BUILD (N items red)`
