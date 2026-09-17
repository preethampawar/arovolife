# Engine scale benchmark — 15 September 2026

Harness: `compensation:scale-seed` + `compensation:scale-benchmark`, against the
`arovolife_perf` database. Measured on the local Docker stack (MySQL 8, one app
container). The absolute seconds are not production numbers — the shape of the
curve is the finding.

## The population

Ten lakh distributors, placed by real spillover with a 0.7 left bias:

| | |
|---|---|
| Distributors | 1,000,000 |
| Closure rows | 21,578,906 (average depth **21.6**, max **36**) |
| Paid orders | 1,580,000 — one joining purchase each, plus a month of daily buys |
| `group_bv_daily` | 3,317,429, propagated up each buyer's placement chain |

The depth matters. A balanced binary tree at this size is depth 20 flat; the
spillover bias produces one that runs to 36, which is what makes the ancestor
walks and the carry-forward do real work. The joining purchase matters too:
without it most of the roster has no personal BV, the cut-off answers
`below_600bv` and takes its bulk shortcut for ~98% of the population, and the
benchmark measures the gate rather than the engine.

## The curve

| Distributors | Engine | Wall clock | Queries | Peak memory | Rows written | Per 1k |
|---|---|---|---|---|---|---|
| 1,000 | Repurchase Evaluation | 0.42s | 6,012 | 52 MB | 1,000 | 0.42s |
| 1,000 | GSB Daily Cut-off | 0.11s | 670 | 60 MB | 1,000 | 0.11s |
| 100,000 | Repurchase Evaluation | 196.69s | 600,458 | 58 MB | 100,000 | 1.97s |
| 100,000 | GSB Daily Cut-off | 49.96s | 65,953 | 82 MB | 100,000 | 0.50s |
| **1,000,000** | **Repurchase Evaluation** | **2,824.79s** | **6,004,508** | **58 MB** | 1,000,000 | 2.83s |
| **1,000,000** | **GSB Daily Cut-off** | **297.47s** | **669,752** | **138 MB** | 1,000,000 | 0.30s |

## Answer to the question the plan asked

> Does the single-process chain finish in an acceptable wall clock at 10 lakh?

**Yes — 52 minutes — but nine tenths of it is one engine, and that engine is
getting worse as it grows.**

The chain starts at 00:05 IST, so the two nightly steps finish around 00:57.
That is inside the night, with the next chain not due for 23 hours and the
health digest not sent until 08:00. No skipped night, no overlap.

Three things the run settles:

1. **Memory is no longer the ceiling.** Both engines now chunk their roster
   (2,000 at a time, warming and forgetting per chunk), and peak memory is
   **58 MB and 138 MB at ten lakh** — against a 512 MB container limit. Before
   that change the cut-off held 485 MB at one hundred thousand and grew
   linearly, which projected to roughly 4.8 GB.
2. **`repurchase:evaluate` is the whole problem.** 2,824s of the 3,122s total,
   and **6,004,508 queries** — six per distributor, every night, with no
   batching. `GsbCutoffService::warmBatch()` is why the cut-off does 0.67
   queries per head instead; `RepurchaseCycleService` has no equivalent.
3. **It is scaling worse than linearly.** Per thousand distributors the
   repurchase evaluation costs 0.42s at 1k, 1.97s at 100k and 2.83s at 1M — a
   14.4× increase in wall clock for a 10× increase in population. The cut-off
   goes the other way (0.11 → 0.50 → 0.30s per thousand), because the bulk warm
   queries amortise better the more rows they cover.

The superlinearity is the part to watch: at ten lakh the night has an hour of
headroom, but an engine that costs 1.44× per head for every 10× of growth does
not keep that headroom at twenty lakh.

## What to fix next, in the order the benchmark ranks it

1. **`RepurchaseCycleService::warmBatch()`**, mirroring
   `GsbCutoffService::warmBatch()` — batch-load cycles and self-purchase BV per
   chunk instead of per distributor. This is 90% of the night on one change.
2. **The superlinear term.** Six queries per distributor should be flat per
   head; that it is not suggests an unindexed predicate growing with the table.
   Profile the six before optimising them.
3. **`offers:monthly-run`** — five per-distributor queries, and the most
   expensive step of the monthly close. Not on the nightly path, so it costs a
   month-end rather than a night.

## What this run does NOT measure

- **Credits.** The seeded BV (600–1,200 per purchase) puts every distributor on
  the engine path but only ancestors near the root cross slab 1 (15,000 BV a
  side), so the run measures compute-and-settle, not the full credit tail —
  wallet write, repurchase deduction, admin charge and TDS.
- **The monthly close.** Only the two nightly steps were benchmarked; the seven
  crediting engines are a separate run.
- **Production hardware.** One local container against one local MySQL.

## Reproducing

```bash
docker exec -e DB_DATABASE=arovolife_perf -e COMP_SCALE_DATABASE=arovolife_perf arovolife-app \
  php -d memory_limit=3G artisan compensation:scale-seed --distributors=1000000 --months=1 --fresh --force

docker exec -e DB_DATABASE=arovolife_perf -e COMP_SCALE_DATABASE=arovolife_perf arovolife-app \
  php -d memory_limit=3G artisan compensation:scale-benchmark
```

The seed takes about 41 minutes at ten lakh, nearly all of it the closure table.
`arovolife_perf` must be migrated and carry the plan fixtures (`GsbSlabsSeeder`,
`RankTiersSeeder`, `FortuneBonusLevelsSeeder`, `FortuneBonusTiersSeeder`,
`SettingsSeeder`). The seeder turns on the engine feature flags itself: a
flag-off engine exits 0 having done nothing, and benchmarking that reports four
queries and no rows, which looks like excellent news.

`--fresh` empties the engines' own output as well as the fixture, because an
engine that finds its work already done does almost nothing — and it refuses
outright unless every distributor in the database carries an `SC` ADN, so it
cannot empty a database that holds anybody's real data whatever it is called.

## Fixed along the way

Four unbounded `whereIn($ids)` calls, each building one SQL placeholder per
distributor against MySQL's 65,535 limit. **The platform did not reach ten lakh
before this run: it threw at roughly sixty-five thousand.**

| Where | Engine it killed |
|---|---|
| `RepurchaseEvaluateCommand::withPossibleCycle()` | `repurchase:evaluate` |
| `BvLedgerService::warmPersonalBvCache()` | `gsb:daily-cutoff` |
| `IncomeEligibilityService::warmCycleCache()` | `gsb:daily-cutoff` |
| `GsbIdleCutoffBatch::partition()` (four reads) | `gsb:daily-cutoff` |

All chunked at 500. None was a performance problem — each was an exception that
would have aborted the night.
