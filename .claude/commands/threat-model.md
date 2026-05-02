---
description: Generate or update STRIDE threat-model entries for new endpoints or data flows
allowed-tools: Bash(git diff:*), Bash(grep:*), Read(**), Write(**), Edit(**), Glob(**), Grep(**)
argument-hint: "[feature-or-endpoint]"
---

# /threat-model — STRIDE walkthrough

Update `docs/security/threat-model.md` for the feature named in
`$ARGUMENTS`, or for everything new in the diff if no argument is given.

For each endpoint and each stored data flow, enumerate:

- **S**poofing — who else could claim this identity?
- **T**ampering — how could the payload be changed in transit or at rest?
- **R**epudiation — is there an audit trail strong enough to survive a dispute?
- **I**nformation disclosure — what PII could leak? Logs? Error messages? Cache?
- **D**enial of service — rate limits, resource caps, slow-operation guards?
- **E**levation of privilege — vertical + horizontal authorisation + IDOR?

Per item, record:
- Likelihood (Low / Medium / High)
- Impact (Low / Medium / High / Critical)
- Mitigation (code change, config, policy)
- Owner and target date

Append findings to `docs/security/threat-model.md` under a dated section.
Do NOT silently rewrite existing entries.
