# Arovolife — MLM + E-Commerce Hybrid Platform

A Laravel 13 implementation of the Arovolife direct-selling platform.
Compliance-first, modular, and built strictly against the
**Consumer Protection (Direct Selling) Rules, 2021 (India)**.

> **Status:** Phase 1 in progress (Registration · Authentication · Authorization · Genealogy)
> **Stack:** Laravel 13 · PHP 8.4 · Blade + Tailwind · MySQL 8 · Redis · Docker · Cloudways + Lightsail

---

## What this folder contains

This is a **kickoff bundle** — design-time artefacts and configuration that
travel with the codebase. It is intentionally NOT a Laravel project yet.
Claude Code will scaffold the Laravel project on first run and use the
artefacts here as authoritative reference.

```
arovolife-code/
├── README.md                       # this file
├── CLAUDE.md                       # primary context file for Claude Code
├── .gitignore
├── .env.example                    # local-dev environment template
├── .claude/                        # Claude Code configuration
│   ├── settings.json               # permissions + model + hooks
│   ├── commands/                   # custom slash commands
│   ├── agents/                     # role-based subagents (architect, compliance, qa, security)
│   └── skills/                     # project-specific skills
│       ├── arovolife-placement-engine/
│       ├── arovolife-compliance-rules/
│       ├── arovolife-compensation-plan/
│       └── arovolife-ux-writing/
├── docs/
│   ├── phase-1-prd.md              # the Phase 1 Product Requirements Document
│   ├── compliance/                 # DSR 2021 mapping, risk register
│   ├── architecture/               # ADRs, data model, service layer, events catalog
│   ├── security/                   # threat model, audit checklist
│   ├── runbooks/                   # operational runbooks
│   └── testing/                    # test strategy
├── backlog/
│   └── phase-1-backlog.md          # sprint-by-sprint breakdown of US-1.01..US-1.16
├── docker/
│   ├── docker-compose.yml          # app + db + redis + queue + mailpit + adminer
│   ├── Dockerfile                  # PHP 8.4 + extensions
│   ├── php/php.ini
│   ├── nginx/nginx.conf
│   └── mysql/my.cnf
├── migrations-blueprint/           # canonical SQL DDL for every Phase-1 table
├── placement-engine-spec/          # the FIRST SLICE — algorithm + tests
└── plugins/                        # Claude Code plugins (none installed yet)
```

---

## Where to start (first 30 minutes)

```bash
# 1. open the folder in Claude Code
cd arovolife-code
claude

# 2. inside Claude Code, run the bootstrap command
/bootstrap-laravel
```

The `/bootstrap-laravel` slash command (defined in `.claude/commands/`)
walks Claude Code through:

1. `composer create-project laravel/laravel app` (Laravel 13, PHP 8.4)
2. Wiring up the Docker stack from `docker/`
3. Translating `migrations-blueprint/*.sql` into Laravel migrations
4. Generating the Eloquent models for the 13 Phase-1 tables
5. Implementing the `PlacementEngine` and `PlacementStrategyResolver`
   services from `placement-engine-spec/`
6. Generating the PHPUnit test suite for the placement engine

Read **CLAUDE.md** before doing anything else — it's the operating manual.

---

## Compliance-first development

Every change in this codebase MUST honour the Direct Selling Rules, 2021.
Before merging anything that touches money, KYC, consent, or the binary
tree, run:

```bash
/compliance-check
```

This invokes the **compliance-officer** subagent, which audits the diff
against `docs/compliance/dsr-2021-mapping.md` and `.claude/skills/arovolife-compliance-rules/`.

**Hard rules — never compromise these:**

- Joining is free of cost. No SKU may be added during registration.
- Commissions are paid only on product sales. Never on recruitment alone.
- Mandatory orientation video before activation.
- 30-day cooling-off; one-click cancellation; full refund.
- One PAN = one Arovolife Distributor Number (ADN).
- No e-commerce listings, no offline retail (T&C §9).
- No income-projection visuals anywhere on the public site.
- PAN encrypted at rest; raw Aadhaar never stored (only reference + last-4).

---

## Phase 1 in one paragraph

Phase 1 delivers the identity, KYC, consent, binary-placement and
genealogy spine that every later phase depends on. A prospect can be
invited via a sponsor link, watch the mandatory orientation, accept the
Direct Seller Agreement, complete PAN/Aadhaar/bank KYC, be placed in the
binary tree under a chosen downline node (with the leg decided by a
company-wide admin setting), and log in as an active Direct Seller. An
admin can search the tree, freeze/terminate distributors, change the
Placement Strategy setting (with audit log), and export the Register of
Direct Sellers. **No commissions, no cart, no wallet** — those are later
phases.

Full PRD: [`docs/phase-1-prd.md`](docs/phase-1-prd.md).
Backlog: [`backlog/phase-1-backlog.md`](backlog/phase-1-backlog.md).

---

## Local development

Step-by-step fresh install + DB reset procedures live in
[`docs/runbooks/fresh-install-and-reset.md`](docs/runbooks/fresh-install-and-reset.md).
Cloudways production deployment lives in
[`docs/runbooks/cloudways-deployment.md`](docs/runbooks/cloudways-deployment.md).
When a nightly job or a compensation engine has failed, start at
[`docs/runbooks/engine-failure-triage.md`](docs/runbooks/engine-failure-triage.md)
— symptom, cause and the exact command, for every failure mode the engines have.

Quick start (assumes Docker + Node 20+ on the host):

```bash
cp app/.env.example app/.env
make up                                                # start stack
docker exec arovolife-app composer install             # PHP deps
docker exec arovolife-app php artisan key:generate
make migrate                                           # schema
(cd app && npm install) && make build                  # front-end assets
make reset-force                                       # seed admin + 31 reserved distributors
```

Default admin: `admin@arovolife.test` / `admin12345` (dev only — re-seeded
by every `make reset-force`).

To wipe test data and rebuild the canonical bootstrap state at any time:

```bash
make reset            # interactive y/n prompt
make reset-force      # skip the prompt
```

Run `make help` for the full list of dev commands.

Services:

| Service   | URL                     | Notes                              |
| --------- | ----------------------- | ---------------------------------- |
| App       | http://localhost:8084   | nginx → php-fpm                    |
| Adminer   | http://localhost:8083   | DB GUI                             |
| Mailpit   | http://localhost:8027   | catches all outbound mail          |
| MySQL     | localhost:3307          | user `arovolife` / pwd `secret`    |
| Redis     | localhost:6379          | cache + queue                      |

---

## Production targets

| Tier        | Target                                     |
| ----------- | ------------------------------------------ |
| App         | Cloudways (PHP 8.4 + nginx)                |
| Database    | Amazon Lightsail MySQL                     |
| Backups     | Lightsail snapshots + offsite to S3        |
| Email       | Amazon SES                                 |
| SMS / OTP   | MSG91 (PO decision D-05, default)          |
| KYC         | Karza / IDfy / Signzy (PO decision D-06)   |
| Storage     | S3 (Mumbai region) — KYC docs              |

---

## Documents this implementation is based on

- Arovolife Compensation Plan (`new-3-26`)
- Arovolife Direct Seller Agreement & Code of Ethics
- Phase-Wise Implementation Plan v1.0 (master, parent folder)
- Phase 1 PRD v1.0 (parent folder + `docs/phase-1-prd.md`)

---

## Licence

Proprietary — Arovolife Private Limited.
