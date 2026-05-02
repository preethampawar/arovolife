# Claude Code Plugins

This folder is reserved for Claude Code plugins that travel with the
repository.

## What Claude Code plugins are

Installable bundles of:

- MCP servers (e.g., a Linear/Jira connector for pulling story status)
- Skills
- Slash commands
- Subagents

A plugin is installed with `/plugin install <id>` or by placing a
`.plugin` bundle in this folder and running `/plugin install ./plugins/<name>.plugin`.

## Plugins relevant to Arovolife

None installed at Phase 1. Candidates to consider later:

| When | Plugin | Why |
|---|---|---|
| Phase 1–2 | GitHub connector | PR reviews in-line from Claude Code |
| Phase 3+ | Razorpay / Cashfree MCP | wallet / payout workflows |
| Phase 4+ | Metabase / Superset MCP | BV / payout dashboards |
| Phase 8 | Freshdesk / Zendesk MCP | grievance redressal |

## Installing a plugin the Arovolife way

1. Add the plugin to this folder (either as a `.plugin` bundle or a
   subfolder).
2. Document what it provides in this README.
3. If the plugin adds skills that duplicate an existing
   `.claude/skills/arovolife-*` skill, delete the duplicated skill and
   point to the plugin version in a short note — never maintain two.
4. Run `/compliance-check` after enabling anything that touches PII or
   money.

## Do NOT put secrets in plugins

Plugins travel with the repo. Any secret they need (API key, token)
must come from `.env` or a vault — never from the plugin bundle.
