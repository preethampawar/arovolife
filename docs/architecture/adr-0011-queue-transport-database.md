# ADR-0011 — Queue transport stays on the database driver; Redis rejected

- **Status:** Accepted
- **Date:** 2026-08-29
- **Deciders:** Laravel Architect, Compliance Officer, Business Owner
- **Builds on:** ADR-0004 (double-entry ledger), ADR-0006 (BV ledger)
- **Supersedes:** the "Phase 10 may swap to Redis" note formerly in `CLAUDE.md`
  and the "Redis queue swap" item in `docs/roadmap.md`

## Context

Every queued class used to land on one `default` queue behind a single worker
pool, so an engine chain or a full recompute — minutes to hours of ledger
work — parked in front of every OTP, order confirmation and KYC mail on the
platform. The natural fix was a Redis queue with Horizon, which is what the
roadmap had pencilled in.

Two facts about the Cloudways server (`1611779`) decided against it, both
verified on 2026-08-28/29:

1. **Its Redis is shared with eight other applications** under
   `maxmemory-policy allkeys-lfu` with `maxmemory` 794 MB. `allkeys-*` evicts
   from the whole keyspace, TTL or not, and Laravel's queue keys carry no TTL.
   Evicting a queue key drops every job in it at once — silently: no
   exception, no `failed_jobs` row, no log line. The policy is server-wide and
   not ours to change; the server cannot be restarted (it holds other
   products' production). Current pressure is low (60 MB used, zero
   evictions) but it is set by other people's applications, not ours.
2. **Staging was already dispatching to Redis.** All 227 rows in its
   `failed_jobs` carried `connection = redis`. The exposure was live, not
   hypothetical.

On the compensation path a silently dropped job is a distributor not
credited, with nothing anywhere recording that it should have been — a DSR
2021 Rule 5(1)(c) traceability failure, not merely an operational one. The
platform's volume (a few hundred jobs a day) gives Redis no throughput
advantage worth measuring against that.

A third constraint shaped the *how*: Cloudways' Supervisord Jobs panel has a
read-only **Connection Driver** of `redis` (its API enum has that single
value), accepts exactly **one lowercase alphanumeric queue name per job**,
and has no free-text command field. Every worker it manages is launched as
`queue:work redis --queue=<one name> ...`.

## Decision

1. **The queue lives in MySQL on every environment, permanently.**
   `QUEUE_CONNECTION=database` everywhere, Cloudways included. Cache and
   sessions stay on Redis: a lost cache entry is regenerated, a lost job is
   not.
2. **The `redis` connection name in `config/queue.php` is an alias of
   `database`.** The `queue:work redis` argument is a name resolved through
   config, not a reference to the Redis server, so the name Cloudways forces
   stays and the driver beneath it is the database. Both connections are one
   array, defined once, so they cannot drift. `QueueRoutingTest` fails if the
   alias is reverted.
3. **Three named queues, three workers, one queue each:**

   | Job | Queue | Processes | Timeout | Tries | Carries |
   |---|---|---|---|---|---|
   | 1 | `otp` | 1 | 120 | 3 | `OtpCodeNotification` — a human is waiting |
   | 2 | `default` | 2 | 120 | 3 | every other notification and mail |
   | 3 | `compensation` | **1, never more** | 7200 | **1** | the four `Compensation\Jobs` |

   Jobs select their queue in their own constructors via `onQueue()`;
   `QueueRoutingTest` asserts no compensation job is left untagged.
4. **Job 3 runs one process** because the connection's `retry_after` (90 s)
   is far below the engine timeouts (3600 s / 7200 s): a long job goes
   "available" again while still running, and a second process would
   re-reserve it and write to the ledger concurrently. `retry_after` is
   deliberately not raised — it is per connection, so raising it would delay
   the retry of every crashed OTP job by the same amount. The single process
   is the guard; R-61 tracks the code-level guard that should back it.
5. **Job 3 runs each job once (tries 1)** for visibility, not for safety.
   A sequential retry cannot double-credit: every engine is either a single
   transaction (rank, GBB, ADC, group-BV propagation) or per-row idempotent
   behind a unique key, and `wallet_ledger_entries` is UNIQUE on
   `(type, reference_type, reference_id)`. What an automatic retry does is
   re-run the money path before anyone has read why it stopped, and with
   tries 3 the first two failures never reach `failed_jobs`. A visible
   failure in `failed_jobs`, Pulse and the Engine Runs page, re-triggered by
   a person once the cause is known, is the intended recovery — resume, not
   revert (ADR-0004: the ledger is append-only).
6. **Horizon is off the table** with Redis (it is Redis-only). Laravel Pulse
   provides queue and failed-job visibility instead, developer-role only.

## Consequences

- One config block whose name does not match its driver, with a comment
  explaining why, a runbook section (`cloudways-deployment.md` §1.9), a
  pitfalls row, and a test guarding it. That is the accepted cost.
- Job 3 must be created by hand in the panel on every environment; until it
  exists, compensation jobs accumulate in `jobs` with no error raised and no
  distributor credited. The runbook and the pitfalls table both say so.
- Two panel defaults silently break a new job — Timeout 60 and Artisan Path
  `public_html/artisan` — and are documented in §1.9.
- A transient failure on Job 3 waits for a human. Accepted on this queue.
- `docker/docker-compose.yml` mirrors Jobs 1–3 locally so behaviour matches.

## Rejected alternatives

- **Redis + Horizon** — eviction exposure on the money path; see Context.
- **Cron + `flock` running `queue:work database --stop-when-empty`** —
  honest about what it is, but loses Supervisor's restart semantics and adds
  a lock file to reason about, for no gain over the alias.
- **Hybrid (only `compensation` off Redis)** — viable, but leaves the
  platform running two transports for one queue system with the same
  Supervisor constraint to route around anyway.
