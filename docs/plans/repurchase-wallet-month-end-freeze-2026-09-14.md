> **SUPERSEDED — do not implement (2026-09-14).**
>
> This plan fixed the month-end repurchase-wallet bug by freezing the verdict
> into two new tables. The bug was fixed instead by making the recompute replay
> every engine at the instant the scheduler would have fired it, so a month's
> crediting runs on the 1st of the following month and cannot write into the
> month it judges — no new table, and F125 and the premature-freeze class fixed
> in the same move. See `docs/architecture/adr-0014-test-environment-recompute.md`.
>
> What survives from this document: the two-mode gate (an engine verdict that
> refuses an open month, a display view that never throws), Fortune asking the
> gate before freezing its pool, and the two open client questions, now R-85.
> All of those are implemented. The freeze tables are not, and should not be.

---

# Repurchase wallet month-end freeze — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Planned on Fable 5.1 (2026-09-14); implement on Opus 5. This document is self-contained — it does not rely on the planning conversation.

**Goal:** The month-end repurchase-wallet verdict ("did this distributor still hold repurchase-wallet money at the last instant of the month?") is answered **once per month, before any crediting engine runs, and persisted**; Rank Bonus (requalification + AO-GO), Growth Booster and Fortune all read that one frozen answer, so the deductions one engine writes can never change what the next engine in the same close sees — in production, in an in-flight test close, and in a recompute replay.

**Architecture:** A new month-typed engine `repurchase.wallet-freeze` (`repurchase:freeze-wallet`) becomes step 1 of `compensation:monthly-close` and fires at 00:12 on the 1st in the replay calendar. It writes one header row per month plus one row per distributor holding a balance, computed from the wallet ledger by `created_at` at `min(month-end 23:59:59 IST, now)`. `RepurchaseWalletGateService::clearedAtMonthEnd()` (engine mode) reads only those rows — freezing a **closed** month on demand if nobody has (deterministic: same ledger, same instant) and **refusing** an **open** month that nobody has frozen. A new `standingAtMonthEnd()` (display mode) serves the distributor dashboard / AO-GO status without ever writing. Both tables are registered in `DerivedTables`, so a recompute wipes and re-freezes them for the replayed period.

**Tech Stack:** Laravel 13, PHP 8.4, Pest on MySQL (`arovolife_test`), Pennant feature flags, Blade + Tailwind, blade-lucide-icons.

**Spec:** §1–§3 of this document; `docs/compensation/repurchase-client-examples-2026-09-07.md` §2.3 and §6 Q2 (the client's month-end wallet = ₹0 gate, unchanged by this work); ADR-0014 written in Task 10.

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`; services and commands are `final`; PSR-12 via Pint (`vendor/bin/pint --dirty`); Larastan level 7 (`vendor/bin/phpstan analyse --no-progress --memory-limit=1G <changed files>`) must pass before each commit.
- **Tests run on MySQL `arovolife_test`, never the dev DB.** Exact command (from `docs/local-dev-environment.md`):
  `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db arovolife-app php artisan test --compact --filter=<TestName>`
  Never run a bare `php artisan test`, `make test`, or any `migrate:fresh` against `arovolife`.
- **No new money path.** Nothing here credits, debits or pays. Hard rules 2 and 3 are untouched. The report page shows repurchase-wallet balances to admins only (already visible on the wallet pages) and never a projection.
- **Compliance review:** run the `compliance-officer` subagent once, after Task 6, over the diff of Tasks 1–6; add `Compliance-Review: compliance-officer` as a trailer on the commits of Tasks 3, 4, 5 and 6 (amend before pushing if the review comes later).
- **Zero-trace flag gating:** everything new sits behind `RepurchaseEngineFeature`. Flag off ⇒ the gate reports everyone clear without touching the tables, the freeze engine records a `skipped` run, the report 404s and its nav link is hidden.
- **Timezone:** every "month end" is `Asia/Kolkata` 23:59:59 (`config('app.timezone')` = Asia/Kolkata, `DB_TIMEZONE=+05:30`). Use `Carbon::now('Asia/Kolkata')` and `OpenMonthGuard::isOpen()` — never bare `now()` for the open/closed question.
- **Copy:** "arovolife" lowercase; "Genos", never "binary"; every number through `IndianNumber::format`; icons via `<x-lucide-*>`; no `dark:` variants; keep `resources/help/compensation.md` in sync (Task 10).
- **Commits:** Conventional Commits; end every commit with the `Co-Authored-By:` line your session reminder specifies. Solo-dev workflow: **confirm with the user before any push or merge to main.**
- **Workspace:** `git status` on `main` shows unrelated, uncommitted inventory/catalog work. Do not touch it. Start from a worktree off `main` (`superpowers:using-git-worktrees`), branch `fix/repurchase-wallet-month-end-freeze`.
- **Sizing:** ≤ 2 implementer agents at a time (session-limit rule). Tasks 1–7 are sequential; Task 8 and Task 9 can run in parallel with each other after Task 7; Task 10 after everything; Task 11 is optional and separate.

---

## 0. Read first (15 minutes, in this order)

1. `docs/local-dev-environment.md` — the test-DB isolation trap and the exact `docker exec` invocation.
2. `app/app/Modules/Compensation/Services/RepurchaseWalletGateService.php` — the live-read gate this plan replaces (74 lines).
3. `app/app/Modules/Compensation/Services/WalletService.php:255-345` (`creditWithRepurchaseDeduction`) and `:613-633` (`repurchaseWalletBalancesAsOfPaise`).
4. `app/app/Modules/Compensation/Console/Commands/MonthlyCloseCommand.php` — the orchestrator; note `STEPS`, `runStep()`, the resume rule.
5. `app/app/Modules/Compensation/Support/EngineRegistry.php` — engine definitions; `app/app/Modules/Compensation/Support/OpenMonthGuard.php`; `app/app/Modules/Compensation/Support/DerivedTables.php`.
6. `app/app/Modules/Compensation/Services/Recompute/EngineReplayService.php:126-235` and `:259-400` — how the replay back-dates the clock (`Carbon::setTestNow`) and orders the in-flight catch-up by `monthPosition`.
7. `app/app/Modules/Compensation/Database/Migrations/2026_09_06_100002_drop_repurchase_monthly_snapshots_table.php` — the freeze that existed on 2026-09-05 and was dropped on 09-06; the gate came back on 09-07 (commit `43f66121`) as a live query.
8. Memory notes (if available): `freeze_the_roster_not_just_the_pool`, `compensation_recompute_testing_tool`, `repurchase_wallet_credit_time_system`.

---

## 1. The bug (root cause, verified 2026-09-14)

**Symptom (staging):** repurchase wallet brought to ₹0; GSB, MSB and Rank Bonus run and credit; their 10 % repurchase deductions land in the wallet; Growth Booster and Fortune then run and skip everyone as `repurchase_wallet_blocked`.

**Root cause:** `RepurchaseWalletGateService::clearedAtMonthEnd()` is a live ledger sum windowed on **`created_at <= <month end 23:59:59>`** (`WalletService.php:622`). It re-runs on every call. The monthly close is ordered `rank.check → rank.bonus → gbb.monthly → fortune.enroll → adc.bonus → fortune.payout → offers.monthly` (`MonthlyEngineCompletionGate::ENGINE_KEYS`). Step 2 (Rank Bonus) writes `repurchase_deduction` rows; steps 3 and 6 then ask the gate again. Whether step 2's rows are visible depends solely on where `now()` falls:

| Run | Rank Bonus writes at | Gate asks `created_at <=` | Step 2's rows counted? |
|---|---|---|---|
| Production, 1st of next month, prev-month | 01 Oct 00:30 | 30 Sep 23:59:59 | No — safe **by accident of scheduling** |
| Staging `--in-flight` / recompute catch-up for the current month | 14 Sep hh:mm | 30 Sep 23:59:59 (**future**) | **Yes** — "as of month end" degrades to "as of right now" |

So production is only correct because the engines happen to run one day after the month they judge; any re-run, in-flight run or replay whose write clock falls inside the judged month moves the verdict. The gate's own docblock promises determinism ("a re-run of a closed month reaches the same verdict") that the code does not deliver.

**History that explains it:** 09-05 shipped the gate *with* a frozen `repurchase_monthly_snapshots` table; 09-06 moved the deadline onto the distributor's own cycle and dropped the table; 09-07 re-confirmed the calendar-month gate and reinstated it as a live query because its freeze had just been deleted. This plan restores the freeze — properly this time (month-typed engine, one period definition, Asia/Kolkata as-of, registered in `DerivedTables`).

**Not a bug, but worth knowing (see §7):** GSB/MSB deductions credited on days 2–31 of a month legitimately count against that month's gate. That is the shipped client rule and this plan does not change it.

---

## 2. Decisions

| # | Decision | Rejected alternative(s) and why |
|---|---|---|
| D1 | **Clock:** the frozen balance is the ledger by `created_at` at `as_of = min(month-end 23:59:59 IST, freeze instant)`. | *Earned clock (`bonus_month`)*: changes production behaviour (adds the last day's GSB deduction, credited 00:10 next morning, to the judged month — money the distributor could not have spent) and is incoherent for the monthly engines' own credits. The cycle verdict (`RepurchaseCycleService::resolveAtWindowEnd`) already uses the write clock; keep one clock. |
| D2 | **Persist** a header row per month + one row per distributor **holding a balance** (blocked). Header present ⇒ every distributor not listed is cleared. | *Row per distributor incl. cleared*: 10× the rows for no information. *Per-engine verdict columns* (already exist on result rows): they are frozen per engine at different instants — that is the bug. |
| D3 | **Primary writer:** engine `repurchase.wallet-freeze`, step 1 of the close, cadence `monthlyOn(1, '00:12')`, flag `RepurchaseEngineFeature`, `orchestratedBy: 'compensation.monthly-close'`, `requiresClosedPeriod: true`. | *Lazy freeze on first read*: a display page could freeze a live month; population would be whoever the first caller asked about. |
| D4 | **Safety net in the gate (engine mode):** closed month + no header ⇒ freeze on demand (`source = on_demand`, same instant, same answer). Open month + no header ⇒ throw `RepurchaseWalletVerdictNotFrozen`. | *Refuse every missing header + backfill migration*: adds a deploy-time data step and a refusal class that can abort a production close for a month whose answer is fully determined. *Silently compute live*: is the bug. |
| D5 | **Display mode** `standingAtMonthEnd()` — header if present, else live as-of `min(month end, now)`, never writes. `RankRequalificationGateService::passesSoFar()` uses it; `RankStatusService` and `AogoOfferService::eligibilityFor()` switch to it; the old `passes()` is deleted so no caller can pick the wrong mode by accident. | Leaving the dashboard on the engine path would either throw (D4) on every page view in an open month or (lazy variant) freeze the live month. |
| D6 | **Idempotent + self-healing command:** existing final header ⇒ untouched. Existing *provisional* header: month still open ⇒ kept; month closed and no crediting engine has frozen a roster against it ⇒ replaced (audit `…refrozen`); roster frozen against it ⇒ kept and audit-logged (`…provisional_kept`) — a recompute is the remedy. Header model has an `updating` guard. | Editing in place; replacing a consumed verdict (frozen economics never move under money). |
| D7 | **Not in `MonthlyEngineCompletionGate::ENGINE_KEYS`** (the payout gate). It credits nothing, and adding it would make the 8th's payout close refuse a month that was closed before this deploy. It is in the close's `STEPS` (resume logic works for any registered key) and it gets `hasDerivedProof` (final header ⇒ computed). | — |
| D8 | **Consumers declare the dependency** (`rank.bonus`, `gbb.monthly`, `fortune.payout` → `repurchase.wallet-freeze`), so the admin chain resolver runs the freeze first for a manual trigger. Fortune asks the gate **before** freezing its pool (today it freezes first). | Command-level guards in three commands — duplicated logic; the gate is the one place the question is asked. |
| D9 | **Recompute:** both tables in `DerivedTables::TABLES` (child first) and `DATE_COLUMNS` (`month_start`, `month`). The replay needs no other change: the registry cadence fires the freeze at 00:12; the catch-up sorts it first (`01\|00:12`); `OpenMonthGuard::FREEZING_COMMANDS` gains the command so the in-flight month gets `--in-flight`. A partial `--only` selection that names a consumer without the freeze is refused up-front while the repurchase flag is on (same rule as Repurchase Evaluation). | — |
| D10 | **Report page** `Compensation → Calculation reports → Repurchase wallet — month-end freeze` (month picker, header facts, blocked rows). Read-only, flag-gated. | Bolting the balance onto three I&O pages. |
| D11 | **F125 (replay fires the cut-off a day early)** is a separate, optional Task 11 — it is the only remaining reason a staging replay's verdict can differ from production (the last day's GSB deduction). Do it after the main fix is verified on staging, in its own PR. | Bundling it: it touches the replay's core loop and is not needed for correctness of the freeze. |

---

## 3. Semantics after this plan

| Situation | What happens |
|---|---|
| Scheduled close, 1st 00:20, month closed | Step 1 freezes at as_of = last second of the month, `provisional=false`. Steps 2–8 read it. **Identical verdicts to today's production behaviour.** |
| In-flight close / recompute catch-up (test envs only) | Freeze at as_of = now, `provisional=true`, taken **before** rank.check/rank.bonus. GSB/MSB deductions already credited this month count (client rule); Rank Bonus / GBB / Fortune deductions written afterwards do not. This is the reported bug, fixed. |
| Re-run / `--restart` of a closed month | Header exists ⇒ same verdict; nothing recomputed. |
| Manual GBB/Fortune/Rank trigger for a closed month never frozen (e.g. a month closed before deploy) | Gate freezes it on demand at the month's last second (deterministic), logs a warning, proceeds. |
| Manual GBB/Fortune/Rank trigger for an **open** month never frozen (dev gate) | Refused with the exact command to run. The Engine Runs chain resolver also lists the freeze as a prerequisite and runs it first. |
| Recompute (full or windowed) | Wipe deletes headers/blocks from the window's month; replay re-freezes on the 1st at 00:12 (closed months) or at the catch-up (in-flight month). |
| Distributor dashboard / income page / admin distributor page (current month) | Display mode: live balance as of now, no write. |
| `RepurchaseEngineFeature` OFF | Gate: everyone clear, no rows. Freeze engine: `skipped`. Report: 404, nav hidden. |

---

## 4. File map

**Create**
- `app/app/Modules/Compensation/Database/Migrations/2026_09_14_120000_create_repurchase_wallet_month_end_freezes_table.php`
- `app/app/Modules/Compensation/Database/Migrations/2026_09_14_120001_create_repurchase_wallet_month_end_blocks_table.php`
- `app/app/Modules/Compensation/Models/RepurchaseWalletMonthEndFreeze.php`
- `app/app/Modules/Compensation/Models/RepurchaseWalletMonthEndBlock.php`
- `app/app/Modules/Compensation/Services/RepurchaseWalletFreezeService.php`
- `app/app/Modules/Compensation/Exceptions/RepurchaseWalletVerdictNotFrozen.php`
- `app/app/Modules/Compensation/Console/Commands/RepurchaseWalletFreezeCommand.php`
- `app/app/Modules/Compensation/Http/Controllers/Admin/AdminRepurchaseWalletFreezeController.php`
- `app/resources/views/admin/compensation/repurchase-wallet-freezes/index.blade.php`
- `docs/architecture/adr-0014-repurchase-wallet-month-end-freeze.md`
- Tests: `app/tests/Modules/Compensation/RepurchaseWalletFreezeServiceTest.php`, `RepurchaseWalletFreezeCommandTest.php`, `AdminRepurchaseWalletFreezeReportTest.php`

**Modify**
- `app/app/Modules/Compensation/Services/WalletService.php` (+ `repurchaseWalletHoldersAsOfPaise()` after line 633)
- `app/app/Modules/Compensation/Services/RepurchaseWalletGateService.php` (rewrite)
- `app/app/Modules/Compensation/Services/RankRequalificationGateService.php` (`passes()` → `passesSoFar()`)
- `app/app/Modules/Compensation/Services/RankStatusService.php:67`, `AogoOfferService.php:233`
- `app/app/Modules/Compensation/Services/FortuneBonusService.php:310-345` (gate before pool freeze)
- `app/app/Modules/Compensation/Services/EngineStatusService.php:342-371` (`hasDerivedProof`)
- `app/app/Modules/Compensation/Support/EngineRegistry.php` (new definition; 3 dependency edges; close description)
- `app/app/Modules/Compensation/Support/OpenMonthGuard.php:49-60` (`FREEZING_COMMANDS`)
- `app/app/Modules/Compensation/Support/DerivedTables.php` (`TABLES`, `DATE_COLUMNS`)
- `app/app/Modules/Compensation/Console/Commands/MonthlyCloseCommand.php` (`STEPS`, docblock)
- `app/app/Modules/Compensation/Services/Recompute/EngineReplayService.php` (`GUARDED_BY_WALLET_FREEZE`)
- `app/app/Modules/Compensation/Http/Controllers/Admin/AdminEngineRunsController.php:186-200`
- `app/app/Providers/AppServiceProvider.php:150-176` (register the command)
- `app/routes/web.php` (~line 700), `app/resources/views/admin/compensation/_nav.blade.php`
- `app/resources/help/compensation.md`, `docs/runbooks/artisan-commands.md`, `docs/compliance/risk-register.md`, `docs/compensation/repurchase-client-examples-2026-09-07.md`, `docs/testing/staging-qa-playbook.md`
- Tests: `RepurchaseWalletGateServiceTest.php` (rewrite), `DerivedTablesTest.php`, `MonthlyCloseCommandTest.php`, `EngineRegistryTest.php`, `EngineChainResolverTest.php`, `EngineStatusServiceTest.php`, `AdminEngineRunsControllerTest.php`, `RankRequalificationGateServiceTest.php`, `GrowthBoosterBonusServiceTest.php`, `FortuneBonusServiceTest.php`, `IncomeControllerTest.php`, `WalletServiceTest.php`, `CompensationRecomputeTest.php`

---

## 5. Tasks

### Task 1: Schema, models, DerivedTables registration

**Files:**
- Create: the two migrations and two models listed above
- Modify: `app/app/Modules/Compensation/Support/DerivedTables.php`
- Test: `app/tests/Feature/DerivedTablesTest.php`

**Interfaces:**
- Produces: `RepurchaseWalletMonthEndFreeze` (`month_start` string `Y-m-d`, `as_of` Carbon, `provisional` bool, `source` string, `blocked_count` int, `engine_run_id` ?int, `frozen_at` Carbon; `SOURCE_ENGINE = 'engine'`, `SOURCE_ON_DEMAND = 'on_demand'`; `blocks(): HasMany`), `RepurchaseWalletMonthEndBlock` (`freeze_id`, `month_start`, `distributor_id`, `balance_paise`).

- [ ] **Step 1: Write the failing DerivedTables test**

In `app/tests/Feature/DerivedTablesTest.php`, add both tables to `repurchasePlanTables()`:

```php
function repurchasePlanTables(): array
{
    return [
        'repurchase_cycles',
        'repurchase_wallet_month_end_blocks',
        'repurchase_wallet_month_end_freezes',
        'wallet_ledger_entries',
        // …existing entries unchanged…
    ];
}
```

and append a new case:

```php
it('registers the month-end wallet freeze, child rows before the header', function (): void {
    $order = DerivedTables::inTruncationOrder();

    expect(array_search('repurchase_wallet_month_end_blocks', $order, true))
        ->toBeLessThan(array_search('repurchase_wallet_month_end_freezes', $order, true));

    foreach (['repurchase_wallet_month_end_blocks', 'repurchase_wallet_month_end_freezes'] as $table) {
        expect(DerivedTables::dateFilter($table))->toBe(['column' => 'month_start', 'granularity' => 'month']);
    }
});
```

- [ ] **Step 2: Run it — expect FAIL** (`contains()` false for the new tables).

`docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db arovolife-app php artisan test --compact --filter=DerivedTablesTest`

- [ ] **Step 3: Migrations**

`2026_09_14_120000_create_repurchase_wallet_month_end_freezes_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per calendar month: the instant the month-end repurchase-wallet
     * question was answered, and whether that answer is final.
     *
     * The bonus engines gate on "did this distributor still hold repurchase
     * wallet money at the last instant of the month" (client 2026-09-05,
     * re-confirmed 2026-09-07). Read live at engine time the question answers
     * differently for each engine in one close, because the earlier engines
     * write the very deductions the later ones count. So it is answered ONCE,
     * before the first crediting engine, and every engine reads these rows.
     *
     * `as_of` is the instant the ledger was read: the month's last second for
     * a closed month; the freeze time for a month frozen in flight (testing
     * only), in which case `provisional` is true. `source` says who wrote it:
     * `engine` (repurchase:freeze-wallet) or `on_demand` (a crediting engine
     * that found a CLOSED month unfrozen — same instant, same answer).
     */
    public function up(): void
    {
        Schema::create('repurchase_wallet_month_end_freezes', function (Blueprint $table): void {
            $table->id();
            $table->date('month_start')->unique();
            $table->dateTime('as_of');
            $table->boolean('provisional')->default(false);
            $table->string('source', 16);
            $table->unsignedInteger('blocked_count')->default(0);
            $table->unsignedBigInteger('engine_run_id')->nullable();
            $table->dateTime('frozen_at');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repurchase_wallet_month_end_freezes');
    }
};
```

`2026_09_14_120001_create_repurchase_wallet_month_end_blocks_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The distributors a month-end freeze found holding repurchase-wallet
     * money, with the balance. A distributor with no row under a month that
     * HAS a header row was clear — the header is what makes absence an answer.
     */
    public function up(): void
    {
        Schema::create('repurchase_wallet_month_end_blocks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('freeze_id');
            $table->date('month_start');
            $table->unsignedBigInteger('distributor_id');
            $table->unsignedBigInteger('balance_paise');
            $table->timestamp('created_at')->nullable();

            $table->unique(['month_start', 'distributor_id']);
            $table->index('distributor_id');
            $table->foreign('freeze_id')->references('id')->on('repurchase_wallet_month_end_freezes')->cascadeOnDelete();
            $table->foreign('distributor_id')->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repurchase_wallet_month_end_blocks');
    }
};
```

- [ ] **Step 4: Models**

`app/app/Modules/Compensation/Models/RepurchaseWalletMonthEndFreeze.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The month's frozen answer to the month-end repurchase-wallet question.
 * Written once, before any crediting engine, by RepurchaseWalletFreezeService;
 * read by RepurchaseWalletGateService for Rank Bonus, Growth Booster and
 * Fortune. See ADR-0014.
 *
 * `month_start` is deliberately NOT date-cast, for the same reason as
 * GbbMonthlyPool: the cast serialises through the connection's datetime
 * format and breaks a plain `where('month_start', '2026-07-01')` on SQLite.
 *
 * @property int $id
 * @property string $month_start
 * @property Carbon $as_of
 * @property bool $provisional
 * @property string $source
 * @property int $blocked_count
 * @property int|null $engine_run_id
 * @property Carbon $frozen_at
 * @property Carbon|null $created_at
 */
final class RepurchaseWalletMonthEndFreeze extends Model
{
    public const UPDATED_AT = null;

    public const SOURCE_ENGINE = 'engine';

    public const SOURCE_ON_DEMAND = 'on_demand';

    protected $table = 'repurchase_wallet_month_end_freezes';

    protected $fillable = [
        'month_start', 'as_of', 'provisional', 'source', 'blocked_count', 'engine_run_id', 'frozen_at',
    ];

    /**
     * A verdict never moves in place. A provisional one is REPLACED — deleted
     * and re-written, audit-logged — by RepurchaseWalletFreezeService, never
     * edited, so every change to a month's answer is visible as a delete.
     */
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new LogicException('repurchase_wallet_month_end_freezes rows are frozen — a month\'s wallet verdict is never rewritten in place.');
        });
    }

    protected function casts(): array
    {
        return [
            'as_of' => 'datetime',
            'provisional' => 'boolean',
            'blocked_count' => 'integer',
            'engine_run_id' => 'integer',
            'frozen_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(RepurchaseWalletMonthEndBlock::class, 'freeze_id');
    }
}
```

`app/app/Modules/Compensation/Models/RepurchaseWalletMonthEndBlock.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One distributor the month-end freeze found holding repurchase-wallet money.
 *
 * @property int $id
 * @property int $freeze_id
 * @property string $month_start
 * @property int $distributor_id
 * @property int $balance_paise
 */
final class RepurchaseWalletMonthEndBlock extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'repurchase_wallet_month_end_blocks';

    protected $fillable = ['freeze_id', 'month_start', 'distributor_id', 'balance_paise'];

    protected function casts(): array
    {
        return [
            'freeze_id' => 'integer',
            'distributor_id' => 'integer',
            'balance_paise' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function freeze(): BelongsTo
    {
        return $this->belongsTo(RepurchaseWalletMonthEndFreeze::class, 'freeze_id');
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
```

- [ ] **Step 5: DerivedTables**

In `TABLES`, immediately before `'repurchase_cycles'`:

```php
        // The month-end repurchase-wallet verdict (ADR-0014): child rows
        // before the header they belong to. Both are a derived freeze of the
        // wallet ledger at one instant, so a replay must re-freeze them from
        // the replayed ledger — a survivor would judge the replayed month on
        // the pre-wipe balances.
        'repurchase_wallet_month_end_blocks',
        'repurchase_wallet_month_end_freezes',
```

In `DATE_COLUMNS`, after the `'repurchase_cycles'` line:

```php
        'repurchase_wallet_month_end_blocks' => ['column' => 'month_start', 'granularity' => 'month'],
        'repurchase_wallet_month_end_freezes' => ['column' => 'month_start', 'granularity' => 'month'],
```

Update the class docblock sentence "Only three real FK constraints exist among these" to four, adding `repurchase_wallet_month_end_blocks → repurchase_wallet_month_end_freezes`.

- [ ] **Step 6: Migrate the test DB and run**

`docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db arovolife-app php artisan test --compact --filter=DerivedTablesTest` — expect PASS. (RefreshDatabase migrates `arovolife_test`; also run forward-only `docker exec arovolife-app php artisan migrate` for the dev DB — additive, no confirmation needed.)

- [ ] **Step 7: Commit**

```bash
git add app/app/Modules/Compensation/Database/Migrations/2026_09_14_12000* app/app/Modules/Compensation/Models/RepurchaseWalletMonthEnd* app/app/Modules/Compensation/Support/DerivedTables.php app/tests/Feature/DerivedTablesTest.php
git commit -m "feat(compensation): tables for the frozen month-end repurchase-wallet verdict"
```

---

### Task 2: `WalletService::repurchaseWalletHoldersAsOfPaise()`

**Files:**
- Modify: `app/app/Modules/Compensation/Services/WalletService.php` (insert after line 633, i.e. after `repurchaseWalletBalancesAsOfPaise()`)
- Test: `app/tests/Modules/Compensation/WalletServiceTest.php`

**Interfaces:**
- Produces: `public function repurchaseWalletHoldersAsOfPaise(\DateTimeInterface $asOf): array` — `distributor id → balance paise`, only balances `> 0`.

- [ ] **Step 1: Failing test** (append to `WalletServiceTest.php`; use that file's existing distributor/ledger helpers or the raw insert below)

```php
it('lists every distributor holding repurchase-wallet money at an instant, and nobody else', function (): void {
    $holder = Distributor::factory()->create();
    $spent = Distributor::factory()->create();
    $later = Distributor::factory()->create();

    $insert = fn (int $id, int $paise, string $type, string $at) => DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $id, 'type' => $type,
        'amount_paise' => $type === 'repurchase_wallet_used' ? -abs($paise) : abs($paise),
        'reference_id' => null, 'reference_type' => null, 'memo' => 'test', 'created_at' => $at,
    ]);

    $insert($holder->id, 30_000, 'repurchase_deduction', '2026-06-10 09:00:00');
    $insert($holder->id, 10_000, 'repurchase_wallet_used', '2026-06-12 09:00:00');
    $insert($spent->id, 50_000, 'repurchase_deduction', '2026-06-10 09:00:00');
    $insert($spent->id, 50_000, 'repurchase_wallet_used', '2026-06-20 09:00:00');
    $insert($later->id, 50_000, 'repurchase_deduction', '2026-07-01 00:06:00');

    $holders = app(WalletService::class)->repurchaseWalletHoldersAsOfPaise(Carbon::parse('2026-06-30 23:59:59'));

    expect($holders)->toBe([$holder->id => 20_000]);
});
```

- [ ] **Step 2: Run — expect FAIL** (`--filter=WalletServiceTest`; "Call to undefined method").

- [ ] **Step 3: Implement**

```php
    /**
     * Every distributor holding repurchase-wallet money at $asOf, with the
     * balance — the population the month-end freeze writes. The same
     * arithmetic as repurchaseWalletBalancesAsOfPaise(), unfiltered by id: a
     * freeze must see everyone, not only the ids one engine happens to ask
     * about, or two engines in the same close would freeze two populations.
     *
     * @return array<int, int> distributor id → balance in paise, balances > 0 only
     */
    public function repurchaseWalletHoldersAsOfPaise(\DateTimeInterface $asOf): array
    {
        $rows = DB::table('wallet_ledger_entries')
            ->whereIn('type', ['repurchase_deduction', 'repurchase_wallet_used'])
            ->where('created_at', '<=', $asOf)
            ->groupBy('distributor_id')
            ->selectRaw("distributor_id, COALESCE(SUM(CASE WHEN type = 'repurchase_deduction' THEN amount_paise ELSE 0 END), 0) AS credits, COALESCE(SUM(CASE WHEN type = 'repurchase_wallet_used' THEN ABS(amount_paise) ELSE 0 END), 0) AS debits")
            ->havingRaw('credits > debits')
            ->orderBy('distributor_id')
            ->get();

        $holders = [];
        foreach ($rows as $row) {
            $holders[(int) $row->distributor_id] = (int) $row->credits - (int) $row->debits;
        }

        return $holders;
    }
```

- [ ] **Step 4: Run — expect PASS.** Run `--filter=WalletServiceTest` in full to confirm nothing else moved.

- [ ] **Step 5: Commit**

```bash
git add app/app/Modules/Compensation/Services/WalletService.php app/tests/Modules/Compensation/WalletServiceTest.php
git commit -m "feat(wallet): list every repurchase-wallet holder at an instant"
```

---

### Task 3: `RepurchaseWalletFreezeService` + exception

**Files:**
- Create: `app/app/Modules/Compensation/Services/RepurchaseWalletFreezeService.php`, `app/app/Modules/Compensation/Exceptions/RepurchaseWalletVerdictNotFrozen.php`
- Test: `app/tests/Modules/Compensation/RepurchaseWalletFreezeServiceTest.php`

**Interfaces:**
- Consumes: `WalletService::repurchaseWalletHoldersAsOfPaise()` (Task 2), models (Task 1), `OpenMonthGuard::isOpen()`, `EngineRunContext::activeRunId()`, `AuditLog`.
- Produces:
  - `find(Carbon $month): ?RepurchaseWalletMonthEndFreeze`
  - `freeze(Carbon $month, string $source): RepurchaseWalletMonthEndFreeze` — returns the existing header untouched when present
  - `refreezeProvisional(RepurchaseWalletMonthEndFreeze $header, string $source): string` — one of `'final' | 'kept_open' | 'kept_consumed' | 'refrozen'`
  - `RepurchaseWalletVerdictNotFrozen::forOpenMonth(Carbon $month): self`

- [ ] **Step 1: Failing tests**

```php
<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RepurchaseWalletMonthEndBlock;
use App\Modules\Compensation\Models\RepurchaseWalletMonthEndFreeze;
use App\Modules\Compensation\Services\RepurchaseWalletFreezeService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function freezeSeedWalletEntry(int $distributorId, int $amountPaise, string $type, string $createdAt): void
{
    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'type' => $type,
        'amount_paise' => $type === 'repurchase_wallet_used' ? -abs($amountPaise) : abs($amountPaise),
        'reference_id' => null,
        'reference_type' => null,
        'memo' => 'test',
        'created_at' => $createdAt,
    ]);
}

it('freezes a closed month at its last second and lists only the holders', function (): void {
    Carbon::setTestNow('2026-07-01 00:12:00');
    $holder = Distributor::factory()->create();
    $clear = Distributor::factory()->create();
    freezeSeedWalletEntry($holder->id, 50_000, 'repurchase_deduction', '2026-06-20 09:00:00');
    freezeSeedWalletEntry($clear->id, 50_000, 'repurchase_deduction', '2026-06-20 09:00:00');
    freezeSeedWalletEntry($clear->id, 50_000, 'repurchase_wallet_used', '2026-06-28 09:00:00');
    // Written by the June close on 1 July — after June's last second.
    freezeSeedWalletEntry($clear->id, 5_000, 'repurchase_deduction', '2026-07-01 00:10:00');

    $header = app(RepurchaseWalletFreezeService::class)
        ->freeze(Carbon::parse('2026-06-01'), RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);

    expect($header->month_start)->toBe('2026-06-01')
        ->and($header->as_of->format('Y-m-d H:i:s'))->toBe('2026-06-30 23:59:59')
        ->and($header->provisional)->toBeFalse()
        ->and($header->source)->toBe('engine')
        ->and($header->blocked_count)->toBe(1);

    expect(RepurchaseWalletMonthEndBlock::where('freeze_id', $header->id)->pluck('balance_paise', 'distributor_id')->all())
        ->toBe([$holder->id => 50_000]);
});

it('freezes an open month provisionally, as of now', function (): void {
    Carbon::setTestNow('2026-09-14 10:00:00');
    $dist = Distributor::factory()->create();
    freezeSeedWalletEntry($dist->id, 50_000, 'repurchase_deduction', '2026-09-02 00:10:00');

    $header = app(RepurchaseWalletFreezeService::class)
        ->freeze(Carbon::parse('2026-09-01'), RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);

    expect($header->provisional)->toBeTrue()
        ->and($header->as_of->format('Y-m-d H:i:s'))->toBe('2026-09-14 10:00:00')
        ->and($header->blocked_count)->toBe(1);
});

it('is idempotent — a second freeze returns the first header and writes nothing', function (): void {
    Carbon::setTestNow('2026-07-01 00:12:00');
    $dist = Distributor::factory()->create();
    $service = app(RepurchaseWalletFreezeService::class);

    $first = $service->freeze(Carbon::parse('2026-06-01'), RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);
    freezeSeedWalletEntry($dist->id, 50_000, 'repurchase_deduction', '2026-06-20 09:00:00');
    $second = $service->freeze(Carbon::parse('2026-06-01'), RepurchaseWalletMonthEndFreeze::SOURCE_ON_DEMAND);

    expect($second->id)->toBe($first->id)
        ->and($second->source)->toBe('engine')
        ->and(RepurchaseWalletMonthEndFreeze::count())->toBe(1)
        ->and(RepurchaseWalletMonthEndBlock::count())->toBe(0);
});

it('replaces a provisional header once the month has closed and nothing consumed it', function (): void {
    Carbon::setTestNow('2026-06-10 12:00:00');
    $dist = Distributor::factory()->create();
    freezeSeedWalletEntry($dist->id, 50_000, 'repurchase_deduction', '2026-06-05 09:00:00');
    $service = app(RepurchaseWalletFreezeService::class);
    $provisional = $service->freeze(Carbon::parse('2026-06-01'), RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);
    expect($provisional->blocked_count)->toBe(1);

    // Spent before the month actually ended.
    freezeSeedWalletEntry($dist->id, 50_000, 'repurchase_wallet_used', '2026-06-25 09:00:00');
    Carbon::setTestNow('2026-07-01 00:12:00');

    expect($service->refreezeProvisional($provisional, RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE))->toBe('refrozen');

    $final = $service->find(Carbon::parse('2026-06-01'));
    expect($final)->not->toBeNull()
        ->and($final->id)->not->toBe($provisional->id)
        ->and($final->provisional)->toBeFalse()
        ->and($final->blocked_count)->toBe(0)
        ->and(RepurchaseWalletMonthEndFreeze::count())->toBe(1);

    expect(AuditLog::where('action', 'compensation.repurchase_wallet_freeze.refrozen')->exists())->toBeTrue();
});

it('keeps a provisional header a crediting engine has already frozen a roster against', function (): void {
    Carbon::setTestNow('2026-06-10 12:00:00');
    $dist = Distributor::factory()->create();
    $service = app(RepurchaseWalletFreezeService::class);
    $provisional = $service->freeze(Carbon::parse('2026-06-01'), RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);

    DB::table('gbb_monthly_results')->insert([
        'distributor_id' => $dist->id, 'year_month' => '2026-06-01', 'status' => 'credited',
        'agp_earned' => 12, 'point_value_paise' => 800, 'gbb_gross_paise' => 9_600, 'gbb_net_paise' => 8_640,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    Carbon::setTestNow('2026-07-01 00:12:00');

    expect($service->refreezeProvisional($provisional, RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE))->toBe('kept_consumed');
    expect($service->find(Carbon::parse('2026-06-01'))->id)->toBe($provisional->id);
    expect(AuditLog::where('action', 'compensation.repurchase_wallet_freeze.provisional_kept')->exists())->toBeTrue();
});

it('keeps a provisional header while its month is still open', function (): void {
    Carbon::setTestNow('2026-09-14 10:00:00');
    $service = app(RepurchaseWalletFreezeService::class);
    $provisional = $service->freeze(Carbon::parse('2026-09-01'), RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);

    expect($service->refreezeProvisional($provisional, RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE))->toBe('kept_open');
});

it('reports a final header as final', function (): void {
    Carbon::setTestNow('2026-07-01 00:12:00');
    $service = app(RepurchaseWalletFreezeService::class);
    $final = $service->freeze(Carbon::parse('2026-06-01'), RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);

    expect($service->refreezeProvisional($final, RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE))->toBe('final');
});

it('refuses to edit a header in place', function (): void {
    Carbon::setTestNow('2026-07-01 00:12:00');
    $header = app(RepurchaseWalletFreezeService::class)
        ->freeze(Carbon::parse('2026-06-01'), RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);

    expect(fn () => $header->update(['blocked_count' => 99]))->toThrow(LogicException::class);
});
```

If `gbb_monthly_results` has NOT NULL columns beyond those listed, look at `app/app/Modules/Compensation/Database/Migrations/*create_gbb_monthly_results*` and add them to the insert; the row only needs to exist.

- [ ] **Step 2: Run — expect FAIL** (`--filter=RepurchaseWalletFreezeServiceTest`, class not found).

- [ ] **Step 3: Exception**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Exceptions;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A crediting engine asked for the month-end repurchase-wallet verdict of a
 * month that is still in flight and that nobody has frozen. A closed month
 * can be frozen on demand (its answer is fixed); an open one cannot without
 * making the answer provisional, which only a testing run may ask for.
 */
final class RepurchaseWalletVerdictNotFrozen extends RuntimeException
{
    public static function forOpenMonth(Carbon $month): self
    {
        return new self(sprintf(
            'The month-end repurchase wallet verdict for %s has not been frozen and the month has not closed, '
            .'so it cannot be frozen on demand — a freeze taken now would be provisional. '
            .'Run `php artisan repurchase:freeze-wallet --month=%s --in-flight` first (testing only), or wait for the month to close.',
            $month->format('F Y'),
            $month->format('Y-m'),
        ));
    }
}
```

- [ ] **Step 4: Service**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\RepurchaseWalletMonthEndBlock;
use App\Modules\Compensation\Models\RepurchaseWalletMonthEndFreeze;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\OpenMonthGuard;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Writes the month-end repurchase-wallet verdict — ONCE per month.
 *
 * The verdict is the wallet ledger read by `created_at` at the month's last
 * second (Asia/Kolkata). For a month still in flight — a testing close or a
 * recompute of the current month — it is read as of now and marked
 * provisional. Either way it is written before the first crediting engine
 * runs, so the deductions those engines take cannot feed back into it. See
 * ADR-0014 and RepurchaseWalletGateService, the only reader.
 */
final class RepurchaseWalletFreezeService
{
    public function __construct(
        private readonly WalletService $wallet,
    ) {}

    /** The month's header, or null when the month has not been frozen. */
    public function find(Carbon $month): ?RepurchaseWalletMonthEndFreeze
    {
        return RepurchaseWalletMonthEndFreeze::query()
            ->where('month_start', $month->copy()->startOfMonth()->toDateString())
            ->first();
    }

    /**
     * Freeze the month if it is not frozen yet. Returns the existing header
     * UNTOUCHED when it is: a re-run of the close, a manual re-trigger and a
     * crediting engine that finds the month unfrozen all end on the same rows.
     *
     * A month still in flight is frozen provisionally. Callers own that
     * decision: the command refuses an open month without --in-flight and the
     * gate refuses to freeze one at all.
     *
     * @param  string  $source  RepurchaseWalletMonthEndFreeze::SOURCE_*
     */
    public function freeze(Carbon $month, string $source): RepurchaseWalletMonthEndFreeze
    {
        $monthStart = $month->copy()->startOfMonth()->startOfDay();

        return $this->find($monthStart) ?? $this->write($monthStart, $source);
    }

    /**
     * Replace a provisional header once its month has closed and no crediting
     * engine has frozen a roster against it. Once one has, the header is KEPT:
     * frozen verdicts never move under money that was priced on them — the
     * mismatch is audit-logged and a recompute is the remedy.
     *
     * @return 'final'|'kept_open'|'kept_consumed'|'refrozen'
     */
    public function refreezeProvisional(RepurchaseWalletMonthEndFreeze $header, string $source): string
    {
        if (! $header->provisional) {
            return 'final';
        }

        $monthStart = Carbon::parse($header->month_start)->startOfMonth()->startOfDay();

        if (OpenMonthGuard::isOpen($monthStart)) {
            return 'kept_open';
        }

        if ($this->rosterFrozenAgainst($monthStart)) {
            AuditLog::create([
                'actor_id' => null,
                'action' => 'compensation.repurchase_wallet_freeze.provisional_kept',
                'subject_type' => 'platform',
                'subject_id' => 0,
                'details' => [
                    'month' => $monthStart->format('Y-m'),
                    'as_of' => $header->as_of->toDateTimeString(),
                    'reason' => 'a crediting engine froze its roster against the provisional verdict; recompute the month to replace it',
                ],
            ]);

            return 'kept_consumed';
        }

        DB::transaction(function () use ($header, $monthStart, $source): void {
            $discarded = $header->blocks()->get(['distributor_id', 'balance_paise'])
                ->map(fn (RepurchaseWalletMonthEndBlock $b): array => ['distributor_id' => $b->distributor_id, 'balance_paise' => $b->balance_paise])
                ->all();
            $previousAsOf = $header->as_of->toDateTimeString();

            $header->blocks()->delete();
            $header->delete();

            $replacement = $this->write($monthStart, $source);

            AuditLog::create([
                'actor_id' => null,
                'action' => 'compensation.repurchase_wallet_freeze.refrozen',
                'subject_type' => 'platform',
                'subject_id' => 0,
                'details' => [
                    'month' => $monthStart->format('Y-m'),
                    'previous_as_of' => $previousAsOf,
                    'as_of' => $replacement->as_of->toDateTimeString(),
                    'discarded_blocks' => $discarded,
                    'blocked_count' => $replacement->blocked_count,
                ],
            ]);
        });

        return 'refrozen';
    }

    private function write(Carbon $monthStart, string $source): RepurchaseWalletMonthEndFreeze
    {
        $monthEnd = $monthStart->copy()->timezone('Asia/Kolkata')->endOfMonth()->setTime(23, 59, 59);
        $provisional = OpenMonthGuard::isOpen($monthStart);
        $asOf = $provisional ? Carbon::now('Asia/Kolkata') : $monthEnd;

        return DB::transaction(function () use ($monthStart, $asOf, $provisional, $source): RepurchaseWalletMonthEndFreeze {
            $holders = $this->wallet->repurchaseWalletHoldersAsOfPaise($asOf);

            try {
                $header = RepurchaseWalletMonthEndFreeze::create([
                    'month_start' => $monthStart->toDateString(),
                    'as_of' => $asOf,
                    'provisional' => $provisional,
                    'source' => $source,
                    'blocked_count' => count($holders),
                    'engine_run_id' => app(EngineRunContext::class)->activeRunId(),
                    'frozen_at' => Carbon::now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent run froze the month between find() and here.
                // Theirs is the verdict — same ledger, same instant.
                return $this->find($monthStart)
                    ?? throw new RuntimeException("Month-end wallet freeze for {$monthStart->format('Y-m')} vanished mid-write.");
            }

            $rows = [];
            foreach ($holders as $distributorId => $balancePaise) {
                $rows[] = [
                    'freeze_id' => $header->id,
                    'month_start' => $monthStart->toDateString(),
                    'distributor_id' => $distributorId,
                    'balance_paise' => $balancePaise,
                    'created_at' => Carbon::now(),
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                RepurchaseWalletMonthEndBlock::insert($chunk);
            }

            Log::info('repurchase.wallet_freeze.frozen', [
                'month' => $monthStart->format('Y-m'),
                'as_of' => $asOf->toDateTimeString(),
                'provisional' => $provisional,
                'source' => $source,
                'blocked_count' => count($holders),
            ]);

            return $header;
        });
    }

    /**
     * Has any crediting engine frozen a roster against this month? Result rows
     * are written at roster freeze (pending) before any credit, so "a row
     * exists" is exactly "the verdict has been consumed".
     */
    private function rosterFrozenAgainst(Carbon $monthStart): bool
    {
        $date = $monthStart->toDateString();

        return DB::table('rank_bonus_results')->whereDate('month_start', $date)->exists()
            || DB::table('gbb_monthly_results')->whereDate('year_month', $date)->exists()
            || DB::table('fortune_bonus_results')->whereDate('month_start', $date)->exists();
    }
}
```

- [ ] **Step 5: Run — expect PASS.** Then `vendor/bin/pint --dirty` and phpstan on the three new files.

- [ ] **Step 6: Commit**

```bash
git add app/app/Modules/Compensation/Services/RepurchaseWalletFreezeService.php app/app/Modules/Compensation/Exceptions/RepurchaseWalletVerdictNotFrozen.php app/tests/Modules/Compensation/RepurchaseWalletFreezeServiceTest.php
git commit -m "feat(compensation): freeze the month-end repurchase-wallet verdict once per month" -m "Compliance-Review: compliance-officer"
```

---

### Task 4: Rewrite `RepurchaseWalletGateService` (engine mode + display mode)

**Files:**
- Modify: `app/app/Modules/Compensation/Services/RepurchaseWalletGateService.php` (full rewrite)
- Test: `app/tests/Modules/Compensation/RepurchaseWalletGateServiceTest.php` (rewrite)

**Interfaces:**
- Consumes: Task 3 service + exception; `WalletService::repurchaseWalletBalancesAsOfPaise()`.
- Produces: `clearedAtMonthEnd(array $distributorIds, Carbon $month): array<int,bool>` (engine mode, unchanged signature — GBB ×2, Fortune and the requalification gate keep calling it) and `standingAtMonthEnd(array $distributorIds, Carbon $month): array<int,bool>` (display mode).

- [ ] **Step 1: Rewrite the test file**

Keep the file header, `beforeEach`, and `gateSeedWalletEntry()` exactly as they are today; add `afterEach(fn () => Carbon::setTestNow());` and the imports `RepurchaseWalletMonthEndFreeze`, `RepurchaseWalletFreezeService`, `RepurchaseWalletVerdictNotFrozen`. Replace the cases with:

```php
it('reports everyone clear when the repurchase engine is off, and freezes nothing', function (): void {
    $dist = Distributor::factory()->create();
    gateSeedWalletEntry($dist->id, 50_000, 'repurchase_deduction', '2026-06-10 09:00:00');

    $map = app(RepurchaseWalletGateService::class)->clearedAtMonthEnd([$dist->id], Carbon::parse('2026-06-01'));

    expect($map[$dist->id])->toBeTrue()
        ->and(RepurchaseWalletMonthEndFreeze::count())->toBe(0);
});

it('freezes a closed month on demand, once, and answers from the freeze', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-07-01 00:45:00');
    $blocked = Distributor::factory()->create();
    $clear = Distributor::factory()->create();
    gateSeedWalletEntry($blocked->id, 50_000, 'repurchase_deduction', '2026-06-10 09:00:00');
    gateSeedWalletEntry($clear->id, 50_000, 'repurchase_deduction', '2026-06-10 09:00:00');
    gateSeedWalletEntry($clear->id, 50_000, 'repurchase_wallet_used', '2026-06-28 09:00:00');

    $gate = app(RepurchaseWalletGateService::class);
    $map = $gate->clearedAtMonthEnd([$blocked->id, $clear->id], Carbon::parse('2026-06-15'));

    expect($map[$blocked->id])->toBeFalse()->and($map[$clear->id])->toBeTrue();

    $header = app(RepurchaseWalletFreezeService::class)->find(Carbon::parse('2026-06-01'));
    expect($header)->not->toBeNull()
        ->and($header->source)->toBe(RepurchaseWalletMonthEndFreeze::SOURCE_ON_DEMAND)
        ->and($header->provisional)->toBeFalse();

    // The ledger moving afterwards changes nothing — the month is answered.
    gateSeedWalletEntry($clear->id, 1, 'repurchase_deduction', '2026-06-30 23:59:59');
    expect($gate->clearedAtMonthEnd([$clear->id], Carbon::parse('2026-06-01'))[$clear->id])->toBeTrue()
        ->and(RepurchaseWalletMonthEndFreeze::count())->toBe(1);
});

it('judges the last instant of the month — a deduction created on the 1st of the next month does not count', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-08-01 00:45:00');
    $dist = Distributor::factory()->create();
    gateSeedWalletEntry($dist->id, 50_000, 'repurchase_deduction', '2026-07-01 00:06:00');

    $gate = app(RepurchaseWalletGateService::class);

    expect($gate->clearedAtMonthEnd([$dist->id], Carbon::parse('2026-06-01'))[$dist->id])->toBeTrue()
        ->and($gate->clearedAtMonthEnd([$dist->id], Carbon::parse('2026-07-01'))[$dist->id])->toBeFalse();
});

it('refuses to answer for a month in flight that nobody has frozen', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-09-14 10:00:00');
    $dist = Distributor::factory()->create();

    expect(fn () => app(RepurchaseWalletGateService::class)->clearedAtMonthEnd([$dist->id], Carbon::parse('2026-09-01')))
        ->toThrow(RepurchaseWalletVerdictNotFrozen::class);
    expect(RepurchaseWalletMonthEndFreeze::count())->toBe(0);
});

it('answers an in-flight month from its freeze — a deduction written after the freeze cannot change the verdict', function (): void {
    // THE regression test for the 2026-09-14 staging bug: wallet at ₹0 when
    // the month was frozen, Rank Bonus then deducts, Growth Booster and Fortune
    // must still see a cleared wallet.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-09-14 10:00:00');
    $dist = Distributor::factory()->create();
    gateSeedWalletEntry($dist->id, 50_000, 'repurchase_deduction', '2026-09-02 00:10:00');
    gateSeedWalletEntry($dist->id, 50_000, 'repurchase_wallet_used', '2026-09-14 09:00:00');

    $header = app(RepurchaseWalletFreezeService::class)
        ->freeze(Carbon::parse('2026-09-01'), RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);
    expect($header->provisional)->toBeTrue()->and($header->blocked_count)->toBe(0);

    gateSeedWalletEntry($dist->id, 20_000, 'repurchase_deduction', '2026-09-14 10:05:00');

    expect(app(RepurchaseWalletGateService::class)->clearedAtMonthEnd([$dist->id], Carbon::parse('2026-09-01'))[$dist->id])
        ->toBeTrue();
});

it('blocks, in flight, a balance that was already standing when the month was frozen', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-09-14 10:00:00');
    $dist = Distributor::factory()->create();
    gateSeedWalletEntry($dist->id, 50_000, 'repurchase_deduction', '2026-09-02 00:10:00');

    app(RepurchaseWalletFreezeService::class)->freeze(Carbon::parse('2026-09-01'), RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);

    expect(app(RepurchaseWalletGateService::class)->clearedAtMonthEnd([$dist->id], Carbon::parse('2026-09-01'))[$dist->id])
        ->toBeFalse();
});

it('returns a verdict for every id asked about, including one with no ledger at all', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-07-01 00:45:00');
    $dist = Distributor::factory()->create();

    $map = app(RepurchaseWalletGateService::class)->clearedAtMonthEnd([$dist->id], Carbon::parse('2026-06-01'));

    expect($map)->toHaveKey($dist->id)->and($map[$dist->id])->toBeTrue();
});

it('returns an empty map for an empty id list', function (): void {
    expect(app(RepurchaseWalletGateService::class)->clearedAtMonthEnd([], Carbon::parse('2026-06-01')))->toBe([]);
});

it('standing never writes: live as of now for an open month, and the freeze once one exists', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-09-14 10:00:00');
    $dist = Distributor::factory()->create();
    gateSeedWalletEntry($dist->id, 50_000, 'repurchase_deduction', '2026-09-02 00:10:00');
    $gate = app(RepurchaseWalletGateService::class);

    expect($gate->standingAtMonthEnd([$dist->id], Carbon::parse('2026-09-01'))[$dist->id])->toBeFalse()
        ->and(RepurchaseWalletMonthEndFreeze::count())->toBe(0);

    gateSeedWalletEntry($dist->id, 50_000, 'repurchase_wallet_used', '2026-09-14 09:30:00');
    expect($gate->standingAtMonthEnd([$dist->id], Carbon::parse('2026-09-01'))[$dist->id])->toBeTrue();

    // Frozen while the wallet was clear; a later deduction does not reopen it.
    app(RepurchaseWalletFreezeService::class)->freeze(Carbon::parse('2026-09-01'), RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);
    gateSeedWalletEntry($dist->id, 20_000, 'repurchase_deduction', '2026-09-14 10:05:00');
    expect($gate->standingAtMonthEnd([$dist->id], Carbon::parse('2026-09-01'))[$dist->id])->toBeTrue();
});

it('standing for a closed, unfrozen month is the month-end balance and still writes nothing', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-07-10 10:00:00');
    $dist = Distributor::factory()->create();
    gateSeedWalletEntry($dist->id, 50_000, 'repurchase_deduction', '2026-06-10 09:00:00');
    gateSeedWalletEntry($dist->id, 50_000, 'repurchase_wallet_used', '2026-07-02 09:00:00');

    expect(app(RepurchaseWalletGateService::class)->standingAtMonthEnd([$dist->id], Carbon::parse('2026-06-01'))[$dist->id])->toBeFalse()
        ->and(RepurchaseWalletMonthEndFreeze::count())->toBe(0);
});
```

- [ ] **Step 2: Run — expect FAIL** (`--filter=RepurchaseWalletGateServiceTest`).

- [ ] **Step 3: Rewrite the service**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Exceptions\RepurchaseWalletVerdictNotFrozen;
use App\Modules\Compensation\Models\RepurchaseWalletMonthEndBlock;
use App\Modules\Compensation\Models\RepurchaseWalletMonthEndFreeze;
use App\Modules\Compensation\Support\EngineRunContext;
use App\Modules\Compensation\Support\OpenMonthGuard;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

/**
 * The single place the month-end repurchase-wallet question is answered: did
 * this distributor still hold repurchase-wallet money at the last instant of
 * the calendar month? (Client 2026-09-05, re-confirmed 2026-09-07.)
 *
 * Two modes, deliberately separate methods so no caller can pick the wrong
 * one by accident:
 *
 *  • clearedAtMonthEnd() — ENGINE mode. Reads the month's FROZEN verdict
 *    (RepurchaseWalletFreezeService, ADR-0014). Rank Bonus, Growth Booster and
 *    Fortune all read the same rows, written before any of them credited, so
 *    the 10 % deductions one engine writes can never change what the next one
 *    sees. A closed month nobody froze is frozen here on demand — its answer
 *    is fixed, so this is the freeze the close would have taken. A month still
 *    in flight is REFUSED: freezing it would make the answer provisional, and
 *    only a testing run (--in-flight) may ask for that, explicitly.
 *
 *  • standingAtMonthEnd() — DISPLAY mode, never writes. The frozen verdict
 *    when one exists; otherwise the live ledger as of min(month end, now) —
 *    what the distributor's dashboard and the AO-GO status mean by "so far
 *    this month".
 *
 * This gate is SEPARATE from the repurchase CYCLE: the cycle's verdict is a
 * per-day forfeit of group BV and never holds a monthly bonus. Gated by
 * RepurchaseEngineFeature like every repurchase effect — flag off, everyone is
 * clear and nothing is written.
 */
final class RepurchaseWalletGateService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly RepurchaseWalletFreezeService $freezes,
    ) {}

    /**
     * ENGINE mode — the frozen verdict. Every id asked about is in the answer.
     *
     * @param  int[]  $distributorIds
     * @return array<int, bool> distributor id → cleared
     *
     * @throws RepurchaseWalletVerdictNotFrozen for a month still in flight that nobody has frozen
     */
    public function clearedAtMonthEnd(array $distributorIds, Carbon $month): array
    {
        if ($distributorIds === []) {
            return [];
        }

        if (! Feature::for(null)->active(RepurchaseEngineFeature::class)) {
            return array_fill_keys($distributorIds, true);
        }

        $monthStart = $month->copy()->startOfMonth()->startOfDay();
        $header = $this->freezes->find($monthStart);

        if ($header === null) {
            if (OpenMonthGuard::isOpen($monthStart)) {
                throw RepurchaseWalletVerdictNotFrozen::forOpenMonth($monthStart);
            }

            $header = $this->freezes->freeze($monthStart, RepurchaseWalletMonthEndFreeze::SOURCE_ON_DEMAND);

            Log::warning('repurchase.wallet_gate.frozen_on_demand', [
                'month' => $monthStart->format('Y-m'),
                'engine_run_id' => app(EngineRunContext::class)->activeRunId(),
                'blocked_count' => $header->blocked_count,
            ]);
        }

        return $this->verdictsFrom($header, $distributorIds);
    }

    /**
     * DISPLAY mode — never writes.
     *
     * @param  int[]  $distributorIds
     * @return array<int, bool> distributor id → clear (so far, for an open month)
     */
    public function standingAtMonthEnd(array $distributorIds, Carbon $month): array
    {
        if ($distributorIds === []) {
            return [];
        }

        if (! Feature::for(null)->active(RepurchaseEngineFeature::class)) {
            return array_fill_keys($distributorIds, true);
        }

        $monthStart = $month->copy()->startOfMonth()->startOfDay();
        $header = $this->freezes->find($monthStart);

        if ($header !== null) {
            return $this->verdictsFrom($header, $distributorIds);
        }

        $monthEnd = $monthStart->copy()->timezone('Asia/Kolkata')->endOfMonth()->setTime(23, 59, 59);
        $asOf = Carbon::now('Asia/Kolkata')->min($monthEnd);

        $balances = $this->wallet->repurchaseWalletBalancesAsOfPaise(array_values($distributorIds), $asOf);

        $map = [];
        foreach ($distributorIds as $distributorId) {
            $map[$distributorId] = ($balances[$distributorId] ?? 0) <= 0;
        }

        return $map;
    }

    /**
     * @param  int[]  $distributorIds
     * @return array<int, bool>
     */
    private function verdictsFrom(RepurchaseWalletMonthEndFreeze $header, array $distributorIds): array
    {
        $blocked = $header->blocked_count === 0
            ? []
            : RepurchaseWalletMonthEndBlock::query()
                ->where('freeze_id', $header->id)
                ->whereIn('distributor_id', $distributorIds)
                ->pluck('distributor_id')
                ->map(fn ($id): int => (int) $id)
                ->flip()
                ->all();

        $map = [];
        foreach ($distributorIds as $distributorId) {
            $map[$distributorId] = ! isset($blocked[$distributorId]);
        }

        return $map;
    }
}
```

- [ ] **Step 4: Run — expect PASS.** Then run the three direct consumers' suites to see what the new engine mode changes:

`--filter="GrowthBoosterBonusServiceTest|FortuneBonusServiceTest|RankRequalificationGateServiceTest|AogoOfferServiceTest|RankBonusServiceTest|AdminGbbCalculationTest|AdminGbbInputOutputTest|AdminRankBonusInputOutputTest"`

Expected: tests that run an engine for a **closed** month still pass (on-demand freeze). Two kinds of failure are legitimate and must be fixed **in the test**, not the code:
  1. A test that runs an engine for an **open** month with `RepurchaseEngineFeature` active and no freeze → add `app(RepurchaseWalletFreezeService::class)->freeze(<month>, RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);` before the run (or leave the flag off if the test is not about the wallet).
  2. A test that asks the gate for the same month twice with a ledger change in between and expects the **second** answer to differ — that test asserted the defect; rewrite it to assert the frozen answer.
  Any other failure is a real regression: stop and investigate.

- [ ] **Step 5: Pint + phpstan; commit**

```bash
git add app/app/Modules/Compensation/Services/RepurchaseWalletGateService.php app/tests/Modules/Compensation/RepurchaseWalletGateServiceTest.php app/tests/Modules/Compensation/*Test.php
git commit -m "fix(compensation): month-end wallet gate reads the frozen verdict, refuses an unfrozen open month" -m "Compliance-Review: compliance-officer"
```

---

### Task 5: Requalification gate split, display callers, Fortune call order

**Files:**
- Modify: `app/app/Modules/Compensation/Services/RankRequalificationGateService.php`, `RankStatusService.php:67-71`, `AogoOfferService.php:231-235`, `FortuneBonusService.php:310-345`
- Test: `app/tests/Modules/Compensation/RankRequalificationGateServiceTest.php`, `IncomeControllerTest.php`, `FortuneBonusServiceTest.php`

**Interfaces:**
- Produces: `RankRequalificationGateService::passesSoFar(int $distributorId, Carbon $month, int $rank): bool` (display). `passMap()` unchanged (engine). `passes()` **removed**.

- [ ] **Step 1: Failing tests**

In `RankRequalificationGateServiceTest.php`: replace every `->passes($id, $month, 1)` in the existing cases with `->passMap([$id], $month, 1)[$id]` (they test the engine semantics for a closed month; with the on-demand freeze they keep their meaning). Add:

```php
it('answers the engine from the freeze — a deduction after the freeze does not fail an in-flight month', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-09-14 10:00:00');
    $dist = Distributor::factory()->create();
    requalSeedMonthlyBv($dist->id, app(CompensationPlanSettingsService::class)->rankRepurchaseBvPaise(1), '2026-09-05');

    app(\App\Modules\Compensation\Services\RepurchaseWalletFreezeService::class)
        ->freeze(Carbon::parse('2026-09-01'), \App\Modules\Compensation\Models\RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);
    requalSeedWalletEntry($dist->id, 20_000, 'repurchase_deduction', '2026-09-14 10:05:00');

    expect(app(RankRequalificationGateService::class)->passMap([$dist->id], Carbon::parse('2026-09-01'), 1)[$dist->id])->toBeTrue();
    Carbon::setTestNow();
});

it('passesSoFar reads the live wallet for an open month and never freezes it', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-09-14 10:00:00');
    $dist = Distributor::factory()->create();
    requalSeedMonthlyBv($dist->id, app(CompensationPlanSettingsService::class)->rankRepurchaseBvPaise(1), '2026-09-05');
    requalSeedWalletEntry($dist->id, 20_000, 'repurchase_deduction', '2026-09-10 10:05:00');

    $gate = app(RankRequalificationGateService::class);
    expect($gate->passesSoFar($dist->id, Carbon::parse('2026-09-01'), 1))->toBeFalse();

    requalSeedWalletEntry($dist->id, 20_000, 'repurchase_wallet_used', '2026-09-14 09:00:00');
    expect($gate->passesSoFar($dist->id, Carbon::parse('2026-09-01'), 1))->toBeTrue()
        ->and(\App\Modules\Compensation\Models\RepurchaseWalletMonthEndFreeze::count())->toBe(0);
    Carbon::setTestNow();
});
```

In `IncomeControllerTest.php` add (use that file's `incomeDistributor()` helper for the login; it returns an array — read its first lines to see which element is the `User`):

```php
it('renders the income page in an open month without freezing the month-end wallet verdict', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Feature::for(null)->activate(RankBonusFeature::class);
    [$user] = incomeDistributor();

    $this->actingAs($user)->get(route('income.dashboard'))->assertOk();

    expect(DB::table('repurchase_wallet_month_end_freezes')->count())->toBe(0);
});
```

In `FortuneBonusServiceTest.php` add:

```php
it('pays Fortune in flight when the wallet was clear at the freeze, whatever Rank Bonus deducted afterwards', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-09-14 10:00:00');
    $month = Carbon::parse('2026-09-01');
    $dist = Distributor::factory()->create();
    placeFortuneParticipant($dist->id, 1, '2026-09-01');
    seedCompanyBvForFortunePool(100_000_000, '2026-09-05');

    seedRepurchaseWalletEntryForFortune($dist->id, 50_000, 'repurchase_deduction', '2026-09-02 00:10:00');
    seedRepurchaseWalletEntryForFortune($dist->id, 50_000, 'repurchase_wallet_used', '2026-09-14 09:00:00');
    app(\App\Modules\Compensation\Services\RepurchaseWalletFreezeService::class)
        ->freeze($month, \App\Modules\Compensation\Models\RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);
    seedRepurchaseWalletEntryForFortune($dist->id, 20_000, 'repurchase_deduction', '2026-09-14 10:05:00');

    $result = app(FortuneBonusService::class)->runForMonth($month);

    expect($result['repurchase_wallet_blocked'])->toBe(0)->and($result['credited'])->toBe(1);
    Carbon::setTestNow();
});

it('refuses an unfrozen in-flight month before freezing the Fortune pool', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-09-14 10:00:00');
    $dist = Distributor::factory()->create();
    placeFortuneParticipant($dist->id, 1, '2026-09-01');
    seedCompanyBvForFortunePool(100_000_000, '2026-09-05');

    expect(fn () => app(FortuneBonusService::class)->runForMonth(Carbon::parse('2026-09-01')))
        ->toThrow(\App\Modules\Compensation\Exceptions\RepurchaseWalletVerdictNotFrozen::class);
    expect(\App\Modules\Compensation\Models\FortuneMonthlyPool::where('month_start', '2026-09-01')->exists())->toBeFalse();
    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run — expect FAIL** (`--filter="RankRequalificationGateServiceTest|IncomeControllerTest|FortuneBonusServiceTest"`).

- [ ] **Step 3: Implement**

`RankRequalificationGateService.php` — replace `passes()` (lines 61-64) with:

```php
    /**
     * DISPLAY only — "are the conditions met so far": this month's personal BV
     * against the rank's obligation, and the wallet as it stands right now
     * (RepurchaseWalletGateService::standingAtMonthEnd()). Never freezes
     * anything; the engines use passMap().
     */
    public function passesSoFar(int $distributorId, Carbon $month, int $rank): bool
    {
        $requiredBvPaise = $this->plan->rankRepurchaseBvPaise($rank);
        $bvMap = $this->monthlyPersonalBvMap([$distributorId], $month);
        $standing = $this->walletGate->standingAtMonthEnd([$distributorId], $month);

        return ($bvMap[$distributorId] ?? 0) >= $requiredBvPaise
            && ($standing[$distributorId] ?? true);
    }
```

Update the `passMap()` docblock: "ENGINE mode — the month's FROZEN wallet verdict; refuses an unfrozen open month (see RepurchaseWalletGateService::clearedAtMonthEnd())."

`RankStatusService.php:67` — `$this->requalificationGate->passes(` → `$this->requalificationGate->passesSoFar(`.

`AogoOfferService.php:233` — `met: $this->gate->passes($distributorId, $month, rank: 1),` → `met: $this->gate->passesSoFar($distributorId, $month, rank: 1),`.

`FortuneBonusService.php` — in `runForMonth()`, move the block

```php
        // The month-end repurchase wallet gate, asked once for the whole roster.
        $cleared = $this->walletGate->clearedAtMonthEnd(
            $participants->map(fn ($p): int => (int) $p->distributor_id)->all(),
            $monthStartDate,
        );
```

from after `$frozenLevels = …` to **immediately after** `$participants = FortuneBonusParticipant::where(...)->get();`, and change its comment to:

```php
        // The month-end repurchase wallet gate, asked once for the whole roster
        // — and BEFORE the pool is frozen, so an unfrozen in-flight month is
        // refused without leaving an uncredited pool behind.
```

- [ ] **Step 4: Run — expect PASS.** Also grep for any other `->passes(` on the requalification gate: `grep -rn "requalificationGate->passes(\|gate->passes(" app/app` must return nothing.

- [ ] **Step 5: Pint + phpstan; commit**

```bash
git add app/app/Modules/Compensation/Services/RankRequalificationGateService.php app/app/Modules/Compensation/Services/RankStatusService.php app/app/Modules/Compensation/Services/AogoOfferService.php app/app/Modules/Compensation/Services/FortuneBonusService.php app/tests/Modules/Compensation/RankRequalificationGateServiceTest.php app/tests/Modules/Compensation/IncomeControllerTest.php app/tests/Modules/Compensation/FortuneBonusServiceTest.php
git commit -m "fix(compensation): display paths read the wallet standing, engines the frozen verdict; Fortune asks before it freezes" -m "Compliance-Review: compliance-officer"
```

---

### Task 6: The engine — command, registry, close step, guards

**Files:**
- Create: `app/app/Modules/Compensation/Console/Commands/RepurchaseWalletFreezeCommand.php`
- Modify: `EngineRegistry.php`, `OpenMonthGuard.php`, `MonthlyCloseCommand.php`, `EngineStatusService.php:342-371`, `app/app/Providers/AppServiceProvider.php`
- Test: create `app/tests/Modules/Compensation/RepurchaseWalletFreezeCommandTest.php`; modify `MonthlyCloseCommandTest.php`, `tests/Feature/EngineRegistryTest.php`, `EngineChainResolverTest.php`, `EngineStatusServiceTest.php`

**Interfaces:**
- Produces: engine key `repurchase.wallet-freeze`, signature `repurchase:freeze-wallet {--month=} {--in-flight}`; `MonthlyCloseCommand::STEPS` becomes `public const`.

- [ ] **Step 1: Failing tests**

`RepurchaseWalletFreezeCommandTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\EngineRun;
use App\Modules\Compensation\Models\RepurchaseWalletMonthEndFreeze;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('is skipped, writing nothing, while the repurchase engine is off', function (): void {
    Carbon::setTestNow('2026-07-01 00:12:00');

    expect(Artisan::call('repurchase:freeze-wallet', ['--month' => '2026-06']))->toBe(0);
    expect(RepurchaseWalletMonthEndFreeze::count())->toBe(0);
    expect(EngineRun::where('engine_key', 'repurchase.wallet-freeze')->value('status'))->toBe(EngineRun::STATUS_SKIPPED);
});

it('freezes a closed month and records a succeeded run against it', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-07-01 00:12:00');
    $dist = Distributor::factory()->create();
    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $dist->id, 'type' => 'repurchase_deduction', 'amount_paise' => 50_000,
        'reference_id' => null, 'reference_type' => null, 'memo' => 'test', 'created_at' => '2026-06-20 09:00:00',
    ]);

    expect(Artisan::call('repurchase:freeze-wallet', ['--month' => '2026-06']))->toBe(0);

    $header = RepurchaseWalletMonthEndFreeze::sole();
    expect($header->month_start)->toBe('2026-06-01')
        ->and($header->provisional)->toBeFalse()
        ->and($header->blocked_count)->toBe(1)
        ->and($header->source)->toBe('engine')
        ->and($header->engine_run_id)->toBe(EngineRun::where('engine_key', 'repurchase.wallet-freeze')->sole()->id);
    expect(Artisan::output())->toContain('Distributors holding a balance');
});

it('refuses an open month without --in-flight and freezes it provisionally with it', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-09-14 10:00:00');

    expect(Artisan::call('repurchase:freeze-wallet', ['--month' => '2026-09']))->toBe(1);
    expect(RepurchaseWalletMonthEndFreeze::count())->toBe(0);

    expect(Artisan::call('repurchase:freeze-wallet', ['--month' => '2026-09', '--in-flight' => true]))->toBe(0);
    expect(RepurchaseWalletMonthEndFreeze::sole()->provisional)->toBeTrue();
});

it('leaves a frozen month exactly as it is on a re-run', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-07-01 00:12:00');
    Artisan::call('repurchase:freeze-wallet', ['--month' => '2026-06']);
    $first = RepurchaseWalletMonthEndFreeze::sole();

    expect(Artisan::call('repurchase:freeze-wallet', ['--month' => '2026-06']))->toBe(0);
    expect(Artisan::output())->toContain('already frozen');
    expect(RepurchaseWalletMonthEndFreeze::sole()->id)->toBe($first->id);
});

it('defaults to the month that has just closed', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-07-01 00:12:00');

    expect(Artisan::call('repurchase:freeze-wallet'))->toBe(0);
    expect(RepurchaseWalletMonthEndFreeze::sole()->month_start)->toBe('2026-06-01');
});
```

`MonthlyCloseCommandTest.php`:
- In `stubCreditingEngines()` change `$keys = [...MonthlyEngineCompletionGate::ENGINE_KEYS, 'payout.monthly'];` to `$keys = [...MonthlyCloseCommand::STEPS, 'payout.monthly'];` (import `App\Modules\Compensation\Console\Commands\MonthlyCloseCommand`).
- Rename `'runs the eight crediting engines in the declared order'` to `'runs the eight steps in the declared order — the wallet freeze first'` and make the expected list `['repurchase.wallet-freeze', 'rank.check', 'rank.bonus', 'gbb.monthly', 'fortune.enroll', 'adc.bonus', 'fortune.payout', 'offers.monthly']`.
- Wherever a test lists the full step sequence (lines ~209, ~296), prepend `'repurchase.wallet-freeze'`.
- Add:

```php
it('keeps the payout gate on the crediting engines only — the wallet freeze is a close step, not a payout precondition', function (): void {
    expect(array_slice(MonthlyCloseCommand::STEPS, 1))->toBe(MonthlyEngineCompletionGate::ENGINE_KEYS)
        ->and(MonthlyCloseCommand::STEPS[0])->toBe('repurchase.wallet-freeze')
        ->and(MonthlyEngineCompletionGate::ENGINE_KEYS)->not->toContain('repurchase.wallet-freeze');
});

it('hands --in-flight to the wallet freeze when the close itself runs in flight', function (): void {
    Carbon::setTestNow('2026-08-20 12:00:00');
    Artisan::call('compensation:monthly-close', ['--month' => '2026-08', '--in-flight' => true]);

    expect(StubEngineCommand::$calls[0])->toBe('repurchase.wallet-freeze');
    expect(StubEngineCommand::$options['repurchase.wallet-freeze']['in-flight'] ?? false)->toBeTrue();
    Carbon::setTestNow();
});
```

If `StubEngineCommand` does not record options, extend it: add `public static array $options = [];` and in its `handle()` `self::$options[$this->engineKey] = $this->options();` (then it also needs `{--in-flight}` and `{--force}` in its stub signature — add both to the `sprintf` in `stubCreditingEngines()` as `'%s {%s=} {--in-flight} {--force}'`).

`tests/Feature/EngineRegistryTest.php`: `'registers thirteen engines with unique keys and signatures'` → fourteen, and the count inside.

`EngineChainResolverTest.php` add:

```php
it('runs the wallet freeze before rank bonus, growth booster and fortune payout', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    foreach (['rank.bonus', 'gbb.monthly', 'fortune.payout'] as $key) {
        $plan = app(EngineChainResolver::class)->resolve($key, Carbon::parse('2026-06-01'));
        $keys = array_map(fn ($step) => $step->engine->key, $plan->steps);

        expect($keys)->toContain('repurchase.wallet-freeze');
        expect(array_search('repurchase.wallet-freeze', $keys, true))->toBeLessThan(array_search($key, $keys, true));
    }
});
```

(Mirror the file's existing way of activating flags and reading `$plan->steps` — copy from `'pulls the previous month rank check in for the growth booster'` at line 159.)

`EngineStatusServiceTest.php` add:

```php
it('treats a final month-end wallet freeze as proof the freeze ran', function (): void {
    $status = app(EngineStatusService::class);
    expect($status->isPeriodComputed('repurchase.wallet-freeze', Carbon::parse('2026-06-01')))->toBeFalse();

    DB::table('repurchase_wallet_month_end_freezes')->insert([
        'month_start' => '2026-06-01', 'as_of' => '2026-06-30 23:59:59', 'provisional' => false,
        'source' => 'on_demand', 'blocked_count' => 0, 'frozen_at' => '2026-07-01 00:45:00', 'created_at' => now(),
    ]);
    expect($status->isPeriodComputed('repurchase.wallet-freeze', Carbon::parse('2026-06-01')))->toBeTrue();

    DB::table('repurchase_wallet_month_end_freezes')->update(['provisional' => true]);
    expect($status->isPeriodComputed('repurchase.wallet-freeze', Carbon::parse('2026-06-01')))->toBeFalse();
});
```

- [ ] **Step 2: Run — expect FAIL** (`--filter="RepurchaseWalletFreezeCommandTest|MonthlyCloseCommandTest|EngineRegistryTest|EngineChainResolverTest|EngineStatusServiceTest"`).

- [ ] **Step 3: Command**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\RepurchaseWalletMonthEndFreeze;
use App\Modules\Compensation\Services\RepurchaseWalletFreezeService;
use App\Modules\Compensation\Support\ResolvesMonthOption;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use App\Modules\Shared\Support\IndianNumber as Number;
use Illuminate\Console\Command;
use Laravel\Pennant\Feature;

/**
 * Step 1 of the monthly close: freeze the month-end repurchase-wallet verdict
 * before any crediting engine can move the balances it is judged on. See
 * RepurchaseWalletFreezeService and ADR-0014.
 */
final class RepurchaseWalletFreezeCommand extends Command
{
    use ResolvesMonthOption;

    protected $signature = 'repurchase:freeze-wallet
                            {--month= : Month to freeze (YYYY-MM, defaults to the month that has just ended)}
                            {--in-flight : Testing only — freeze a month that has not closed; the verdict is provisional}';

    protected $description = 'Freeze which distributors still held repurchase-wallet money at the last instant of the month — run before Rank Bonus, Growth Booster and Fortune';

    public function __construct(private readonly RepurchaseWalletFreezeService $freezes)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Feature::for(null)->active(RepurchaseEngineFeature::class)) {
            $this->warn('Repurchase engine feature flag is OFF — skipping the month-end wallet freeze.');

            return self::SUCCESS;
        }

        $month = $this->resolveMonth();

        if ($month === null) {
            return self::FAILURE;
        }

        $this->info("Repurchase wallet month-end freeze — {$month->format('F Y')}");

        $existing = $this->freezes->find($month);

        if ($existing !== null) {
            $outcome = $this->freezes->refreezeProvisional($existing, RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);

            match ($outcome) {
                'final' => $this->line("Already frozen at {$existing->as_of->format('d M Y H:i:s')} — nothing to do."),
                'kept_open' => $this->warn("Provisional freeze from {$existing->as_of->format('d M Y H:i:s')} kept: the month is still open. A recompute replaces it."),
                'kept_consumed' => $this->warn('Provisional freeze kept: a crediting engine has already frozen its roster against it. Recompute the month to replace it.'),
                'refrozen' => $this->info('Provisional freeze replaced with the month-end verdict.'),
            };
        } else {
            $this->freezes->freeze($month, RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);
        }

        $header = $this->freezes->find($month);

        if ($header === null) {
            $this->error('The freeze did not persist.');

            return self::FAILURE;
        }

        $heldPaise = (int) $header->blocks()->sum('balance_paise');

        $this->table(
            ['Metric', 'Value'],
            [
                ['As of', $header->as_of->format('d M Y H:i:s')],
                ['Provisional (month still in flight)', $header->provisional ? 'yes' : 'no'],
                ['Distributors holding a balance', Number::format($header->blocked_count)],
                ['Total held', '₹'.Number::format($heldPaise / 100, 2)],
            ],
        );

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Registry**

In `EngineRegistry.php` add the imports `RepurchaseWalletFreezeCommand` and insert this definition **immediately before** the `gbb.monthly` definition:

```php
            new EngineDefinition(
                key: 'repurchase.wallet-freeze',
                label: 'Repurchase Wallet Month-End Freeze',
                description: "Answers, once for the month, which distributors still held repurchase-wallet money at the month's last instant, and freezes that answer so Rank Bonus (requalification and AO-GO), Growth Booster and Fortune all read one verdict. Impact: writes one freeze header for the month and one row per distributor holding a balance — it credits nothing and moves no money. Idempotent — a month already frozen is left exactly as it is. It must run before the crediting engines: each takes its repurchase deduction out of what it credits, so an engine reading the wallet live would count the deductions the engines before it had just written. A closed month that reaches a crediting engine unfrozen is frozen by that engine on the spot (same instant, same answer); a month still in flight is refused unless --in-flight is passed, in which case the freeze is provisional.",
                periodType: EnginePeriodType::Month,
                commandClass: RepurchaseWalletFreezeCommand::class,
                commandSignature: 'repurchase:freeze-wallet',
                periodOption: '--month',
                // A pure ledger read at a fixed instant — nothing has to have run
                // first. The edges that matter point the other way: the engines
                // that read the verdict declare THIS as a dependency.
                dependencies: [],
                featureFlagClass: RepurchaseEngineFeature::class,
                reportRouteName: 'admin.compensation.repurchase-wallet-freezes.index',
                // Between the closed month's last cut-off (00:10) and the rank
                // check (00:15); the close runs it as step 1 in any case.
                cadence: EngineCadence::monthlyOn(1, '00:12'),
                defaultPeriod: 'prev-month',
                requiresClosedPeriod: true,
                orchestratedBy: 'compensation.monthly-close',
            ),
```

Add `['key' => 'repurchase.wallet-freeze'],` as the **last** entry of the `dependencies` arrays of `gbb.monthly`, `rank.bonus` and `fortune.payout`.

In the `compensation.monthly-close` definition change the description's opening to: `'Freezes the month-end repurchase-wallet verdict, then runs the seven crediting engines for a closed month in dependency order — …'`.

Until Task 9 creates the route, `reportRouteName` will fail `EngineRegistryTest::'names feature flag classes and report routes that actually exist'`. Either do Task 9's route + controller stub first, or temporarily set `reportRouteName: 'admin.compensation.engine-runs.events'` here and switch it in Task 9. **Prefer the second** so this task stays green on its own.

- [ ] **Step 5: OpenMonthGuard**

In `FREEZING_COMMANDS`, add after `'gsb:daily-cutoff',`:

```php
        // Freezes a verdict rather than a pool, but the same rule applies: a
        // month frozen mid-flight is judged on partial activity, and the same
        // --in-flight override is how the replay and the testing gate ask for it.
        'repurchase:freeze-wallet',
```

- [ ] **Step 6: Monthly close**

In `MonthlyCloseCommand.php` replace `private const STEPS = MonthlyEngineCompletionGate::ENGINE_KEYS;` with:

```php
    /**
     * The close sequence. Order is the contract: the month-end wallet verdict
     * before anything that reads it, rank qualifications before everything that
     * reads them, and Fortune enrolment before the Fortune payout.
     *
     * The wallet freeze is a close step but NOT one of
     * MonthlyEngineCompletionGate::ENGINE_KEYS: it credits nothing, and making
     * it a payout precondition would refuse the 8th's payout for any month that
     * was closed before the freeze existed. MonthlyCloseCommandTest pins the
     * two lists to each other.
     *
     * @var list<string>
     */
    public const STEPS = [
        'repurchase.wallet-freeze',
        'rank.check',
        'rank.bonus',
        'gbb.monthly',
        'fortune.enroll',
        'adc.bonus',
        'fortune.payout',
        'offers.monthly',
    ];
```

Update the class docblock's first line to "one process, one lock, eight steps in order" and the `$description` string is fine as is.

- [ ] **Step 7: Derived proof + registration**

`EngineStatusService::hasDerivedProof()` — add before `default => false,`:

```php
            // A FINAL header is the freeze; a provisional one is a testing
            // artefact the real close must redo, so it is not proof.
            'repurchase.wallet-freeze' => RepurchaseWalletMonthEndFreeze::query()
                ->whereDate('month_start', $date)->where('provisional', false)->exists(),
```

(import the model). `AppServiceProvider.php` — add `RepurchaseWalletFreezeCommand::class,` after `RepurchaseEvaluateCommand::class,` (and the `use`).

- [ ] **Step 8: Run — expect PASS** for the five suites; then run the broader `--filter="Engine|MonthlyClose|Recompute"` set. In `CompensationRecomputeTest`, `'catches up a closed month whose scheduled run has not happened yet'` should still pass; if a test asserts an exact list of engines run on the 1st, add `repurchase:freeze-wallet` at position after `gsb:daily-cutoff`.

- [ ] **Step 9: Pint + phpstan; run the compliance-officer subagent over `git diff main...HEAD` (Tasks 1–6); commit**

```bash
git add app/app/Modules/Compensation/Console/Commands/RepurchaseWalletFreezeCommand.php app/app/Modules/Compensation/Support/EngineRegistry.php app/app/Modules/Compensation/Support/OpenMonthGuard.php app/app/Modules/Compensation/Console/Commands/MonthlyCloseCommand.php app/app/Modules/Compensation/Services/EngineStatusService.php app/app/Providers/AppServiceProvider.php app/tests/Modules/Compensation/RepurchaseWalletFreezeCommandTest.php app/tests/Modules/Compensation/MonthlyCloseCommandTest.php app/tests/Feature/EngineRegistryTest.php app/tests/Modules/Compensation/EngineChainResolverTest.php app/tests/Modules/Compensation/EngineStatusServiceTest.php app/tests/Modules/Compensation/CompensationRecomputeTest.php
git commit -m "feat(compensation): repurchase:freeze-wallet — step 1 of the monthly close" -m "Compliance-Review: compliance-officer"
```

---

### Task 7: Recompute — refuse a partial selection that leaves the freeze out

**Files:**
- Modify: `EngineReplayService.php` (after `GUARDED_BY_REPURCHASE_EVALUATE`, and after `guardedEnginesMissingEvaluate()`), `AdminEngineRunsController.php:186-200`
- Test: `AdminEngineRunsControllerTest.php`, `CompensationRecomputeTest.php`

- [ ] **Step 1: Failing tests**

`AdminEngineRunsControllerTest.php` (copy the shape of `'requires the operator to acknowledge the engines a partial replay will not rebuild'`):

```php
it('refuses a partial replay of a wallet-gated engine without the month-end wallet freeze while the repurchase engine is on', function (): void {
    config(['arovolife.recompute.enabled' => true]);
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Queue::fake();

    $this->actingAs(engineRunsUser('developer'))
        ->from(route('admin.compensation.engine-runs.index'))
        ->post(route('admin.compensation.engine-runs.recompute-all'), [
            'from' => today()->toDateString(),
            'windowed' => '1',
            'engines' => ['gbb.monthly', 'repurchase.evaluate', 'rank.check'],
            'accept_missing_engines' => '1',
        ])
        ->assertSessionHasErrors('engines');

    Queue::assertNothingPushed();

    $this->actingAs(engineRunsUser('developer'))
        ->post(route('admin.compensation.engine-runs.recompute-all'), [
            'from' => today()->toDateString(),
            'windowed' => '1',
            'engines' => ['gbb.monthly', 'repurchase.evaluate', 'rank.check', 'repurchase.wallet-freeze'],
            'accept_missing_engines' => '1',
        ])
        ->assertSessionHas('status');

    Queue::assertPushed(RecomputeAllJob::class);
});
```

`CompensationRecomputeTest.php` add:

```php
it('fires the wallet freeze on the 1st after the cut-off and before the rank check, and re-freezes after a wipe', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-09-01 00:20:00');
    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, '2026-08-20 10:00:00', 100_000);

    // A stale header from before the wipe must not survive it.
    DB::table('repurchase_wallet_month_end_freezes')->insert([
        'month_start' => '2026-08-01', 'as_of' => '2026-08-31 23:59:59', 'provisional' => false,
        'source' => 'engine', 'blocked_count' => 7, 'frozen_at' => '2026-09-01 00:12:00', 'created_at' => now(),
    ]);

    app(CompensationRecomputeRunner::class)->run(from: Carbon::parse('2026-08-20'));

    $freeze = DB::table('engine_runs')->where('engine_key', 'repurchase.wallet-freeze')->whereDate('period_start', '2026-08-01')->first();
    $rankCheck = DB::table('engine_runs')->where('engine_key', 'rank.check')->whereDate('period_start', '2026-08-01')->first();
    $cutoff = DB::table('engine_runs')->where('engine_key', 'gsb.daily-cutoff')->whereDate('period_start', '2026-08-31')->first();

    expect($freeze)->not->toBeNull()->and($rankCheck)->not->toBeNull()->and($cutoff)->not->toBeNull();
    expect($freeze->id)->toBeGreaterThan($cutoff->id)->toBeLessThan($rankCheck->id);

    $header = DB::table('repurchase_wallet_month_end_freezes')->where('month_start', '2026-08-01')->sole();
    expect((int) $header->blocked_count)->not->toBe(7)
        ->and((bool) $header->provisional)->toBeFalse()
        ->and(Carbon::parse($header->as_of)->format('Y-m-d H:i:s'))->toBe('2026-08-31 23:59:59');

    Carbon::setTestNow();
});

it('freezes the month in flight first in the catch-up, provisionally', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create();
    recomputeSeedPaidOrder($dist->id, Carbon::today()->setTime(10, 0)->toDateTimeString(), 100_000);

    app(CompensationRecomputeRunner::class)->run(from: Carbon::today());

    $monthStart = Carbon::today()->startOfMonth()->toDateString();
    $freeze = DB::table('engine_runs')->where('engine_key', 'repurchase.wallet-freeze')->whereDate('period_start', $monthStart)->first();
    $gbb = DB::table('engine_runs')->where('engine_key', 'gbb.monthly')->whereDate('period_start', $monthStart)->first();

    expect($freeze)->not->toBeNull()->and($gbb)->not->toBeNull()->and($freeze->id)->toBeLessThan($gbb->id);
    expect((bool) DB::table('repurchase_wallet_month_end_freezes')->where('month_start', $monthStart)->value('provisional'))->toBeTrue();
});
```

(If the second test's month happens to be the 1st–3rd, the existing recompute suite already notes day-of-month dependence; mirror whatever guard those tests use.)

- [ ] **Step 2: Run — expect FAIL** for the controller case (the recompute cases may already pass — that is fine; keep them as regression pins).

- [ ] **Step 3: Implement**

`EngineReplayService.php`, after `GUARDED_BY_REPURCHASE_EVALUATE`:

```php
    /**
     * Engines that read the month-end repurchase-wallet verdict. The wipe
     * deletes the window's freezes; a closed month is re-frozen on demand, but
     * the month in flight cannot be — the gate refuses to freeze an open month
     * on its own — so a selection naming one of these without the freeze
     * aborts the replay part-way. Refused before anything is deleted.
     *
     * @var list<string>
     */
    public const GUARDED_BY_WALLET_FREEZE = ['rank.bonus', 'gbb.monthly', 'fortune.payout'];
```

after `guardedEnginesMissingEvaluate()`:

```php
    /**
     * Which of the selected engines cannot run because the selection leaves
     * the month-end wallet freeze out. Empty for a full replay, and empty once
     * the freeze is selected too. The caller checks the repurchase flag.
     *
     * @param  list<string>|null  $onlyKeys
     * @return list<string>
     */
    public static function guardedEnginesMissingWalletFreeze(?array $onlyKeys): array
    {
        if ($onlyKeys === null || $onlyKeys === [] || in_array('repurchase.wallet-freeze', $onlyKeys, true)) {
            return [];
        }

        return array_values(array_intersect(self::GUARDED_BY_WALLET_FREEZE, $onlyKeys));
    }
```

`AdminEngineRunsController.php` — directly after the existing `if ($guarded !== [] && $eligibility->engineActive()) { … }` block:

```php
        $walletGuarded = EngineReplayService::guardedEnginesMissingWalletFreeze($engines === [] ? null : $engines);

        if ($walletGuarded !== [] && $eligibility->engineActive()) {
            throw ValidationException::withMessages([
                'engines' => sprintf(
                    '%s cannot be replayed without %s while the repurchase engine is on: the replay deletes this '
                        .'window\'s month-end wallet freezes, and the month in flight cannot be re-frozen by %s on its own. '
                        .'Tick %s as well, or leave every box clear to replay all engines.',
                    $this->engineLabels($walletGuarded),
                    EngineRegistry::get('repurchase.wallet-freeze')->label,
                    count($walletGuarded) === 1 ? 'it' : 'each',
                    EngineRegistry::get('repurchase.wallet-freeze')->label,
                ),
            ]);
        }
```

- [ ] **Step 4: Run — expect PASS.** Pint + phpstan.

- [ ] **Step 5: Commit**

```bash
git add app/app/Modules/Compensation/Services/Recompute/EngineReplayService.php app/app/Modules/Compensation/Http/Controllers/Admin/AdminEngineRunsController.php app/tests/Modules/Compensation/AdminEngineRunsControllerTest.php app/tests/Modules/Compensation/CompensationRecomputeTest.php
git commit -m "fix(recompute): a partial replay must include the month-end wallet freeze"
```

---

### Task 8: Engine-level regression — Growth Booster in flight

**Files:**
- Test: `app/tests/Modules/Compensation/GrowthBoosterBonusServiceTest.php`

- [ ] **Step 1: Add the regression test** (uses that file's `gbbSeedCompanyBv`, `gbbSeedCutoff`, `gbbSeedRepurchaseWalletCredit`; do NOT seed a prior-month rank — that excludes the distributor)

```php
it('pays Growth Booster in flight when the wallet was clear at the freeze, whatever Rank Bonus deducted afterwards', function () {
    // The 2026-09-14 staging bug at engine level: freeze → Rank Bonus deducts
    // → Growth Booster must still credit.
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-09-14 10:00:00');
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000, '2026-09-03');
    gbbSeedCutoff($dist->id, '2026-09-05', 1);

    gbbSeedRepurchaseWalletCredit($dist->id, 50_000, '2026-09-02 00:10:00');
    DB::table('wallet_ledger_entries')->insert([
        'distributor_id' => $dist->id, 'type' => 'repurchase_wallet_used', 'amount_paise' => -50_000,
        'reference_id' => null, 'reference_type' => null, 'memo' => 'test', 'created_at' => '2026-09-14 09:00:00',
    ]);

    app(\App\Modules\Compensation\Services\RepurchaseWalletFreezeService::class)
        ->freeze(Carbon::parse('2026-09-01'), \App\Modules\Compensation\Models\RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);

    gbbSeedRepurchaseWalletCredit($dist->id, 20_000, '2026-09-14 10:05:00');

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-09-01'));

    expect($result['wallet_blocked'])->toBe(0)->and($result['credited'])->toBe(1);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'gbb_credit')->exists())->toBeTrue();
    Carbon::setTestNow();
});

it('still forfeits, in flight, a balance that was standing when the month was frozen', function () {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    Carbon::setTestNow('2026-09-14 10:00:00');
    $dist = Distributor::factory()->create();
    gbbSeedCompanyBv(200_000, '2026-09-03');
    gbbSeedCutoff($dist->id, '2026-09-05', 1);
    gbbSeedRepurchaseWalletCredit($dist->id, 50_000, '2026-09-02 00:10:00');

    app(\App\Modules\Compensation\Services\RepurchaseWalletFreezeService::class)
        ->freeze(Carbon::parse('2026-09-01'), \App\Modules\Compensation\Models\RepurchaseWalletMonthEndFreeze::SOURCE_ENGINE);

    $result = app(GrowthBoosterBonusService::class)->runForMonth(Carbon::parse('2026-09-01'));

    expect($result['wallet_blocked'])->toBe(1)->and($result['credited'])->toBe(0);
    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run — expect PASS** (the code is already in place; this pins it). If the first fails with `RepurchaseWalletVerdictNotFrozen`, the freeze call is missing; if it fails with `wallet_blocked = 1`, the gate is still reading live — stop and investigate Task 4.

- [ ] **Step 3: Commit**

```bash
git add app/tests/Modules/Compensation/GrowthBoosterBonusServiceTest.php
git commit -m "test(compensation): growth booster reads the frozen wallet verdict in flight"
```

---

### Task 9: Admin report — Repurchase wallet, month-end freeze

**Files:**
- Create: `app/app/Modules/Compensation/Http/Controllers/Admin/AdminRepurchaseWalletFreezeController.php`, `app/resources/views/admin/compensation/repurchase-wallet-freezes/index.blade.php`, `app/tests/Modules/Compensation/AdminRepurchaseWalletFreezeReportTest.php`
- Modify: `app/routes/web.php` (inside the `compensation` group, after the `gbb-input-output/export` route ~line 701), `app/resources/views/admin/compensation/_nav.blade.php`, `EngineRegistry.php` (switch `reportRouteName` to the real route)

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

it('404s while the repurchase engine is off', function (): void {
    $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.repurchase-wallet-freezes.index'))
        ->assertNotFound();
});

it('lists the frozen months and the distributors blocked in the selected one', function (): void {
    Feature::for(null)->activate(RepurchaseEngineFeature::class);
    $dist = Distributor::factory()->create();

    $freezeId = DB::table('repurchase_wallet_month_end_freezes')->insertGetId([
        'month_start' => '2026-06-01', 'as_of' => '2026-06-30 23:59:59', 'provisional' => false,
        'source' => 'engine', 'blocked_count' => 1, 'frozen_at' => '2026-07-01 00:12:00', 'created_at' => now(),
    ]);
    DB::table('repurchase_wallet_month_end_blocks')->insert([
        'freeze_id' => $freezeId, 'month_start' => '2026-06-01', 'distributor_id' => $dist->id,
        'balance_paise' => 123_456, 'created_at' => now(),
    ]);

    $this->actingAs(engineRunsUser('admin'))
        ->get(route('admin.compensation.repurchase-wallet-freezes.index', ['month' => '2026-06']))
        ->assertOk()
        ->assertSee('June 2026')
        ->assertSee('30 Jun 2026 23:59:59')
        ->assertSee((string) $dist->adn)
        ->assertSee('1,234.56');
});
```

(`engineRunsUser()` is the helper `AdminEngineRunsControllerTest` uses — it lives in that test file; move it to `app/tests/Pest.php` or copy its three lines here under a new name if it is not global.)

- [ ] **Step 2: Run — expect FAIL** (route not defined).

- [ ] **Step 3: Controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\RepurchaseWalletMonthEndFreeze;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Read-only: the month-end repurchase-wallet verdicts (ADR-0014). One row per
 * frozen month — as-of instant, provisional or final, who wrote it — and, for
 * the selected month, every distributor it found holding a balance. This is
 * the answer to "why was X not paid Growth Booster / Fortune / requalified
 * this month"; it recomputes nothing.
 */
final class AdminRepurchaseWalletFreezeController extends Controller
{
    private const MONTHS_PER_PAGE = 24;

    private const BLOCKS_PER_PAGE = 50;

    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(RepurchaseEngineFeature::class), 404);

        $months = RepurchaseWalletMonthEndFreeze::query()
            ->orderByDesc('month_start')
            ->paginate(self::MONTHS_PER_PAGE, ['*'], 'months')
            ->withQueryString();

        $requested = (string) $request->query('month', '');
        $selectedMonth = preg_match('/^\d{4}-\d{2}$/', $requested) === 1
            ? $requested.'-01'
            : ($months->items()[0]->month_start ?? null);

        $selected = $selectedMonth === null
            ? null
            : RepurchaseWalletMonthEndFreeze::query()->where('month_start', $selectedMonth)->first();

        $blocks = $selected === null
            ? null
            : DB::table('repurchase_wallet_month_end_blocks as b')
                ->join('distributors as d', 'd.id', '=', 'b.distributor_id')
                ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
                ->where('b.freeze_id', $selected->id)
                ->select('b.distributor_id', 'b.balance_paise', 'd.adn', 'u.full_name')
                ->orderByDesc('b.balance_paise')
                ->orderBy('d.adn')
                ->paginate(self::BLOCKS_PER_PAGE, ['*'], 'blocks')
                ->withQueryString();

        $totalHeldPaise = $selected === null
            ? 0
            : (int) DB::table('repurchase_wallet_month_end_blocks')->where('freeze_id', $selected->id)->sum('balance_paise');

        return view('admin.compensation.repurchase-wallet-freezes.index', [
            'months' => $months,
            'selected' => $selected,
            'selectedMonth' => $selectedMonth === null ? null : Carbon::parse($selectedMonth),
            'blocks' => $blocks,
            'totalHeldPaise' => $totalHeldPaise,
        ]);
    }
}
```

- [ ] **Step 4: View**

```blade
@extends('admin.layouts.admin')
@section('title', 'Repurchase wallet — month-end freeze')
@section('heading', 'Repurchase wallet — month-end freeze')

@section('content')

@developer
<div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
    The month-end repurchase-wallet verdict, frozen once per month before Rank Bonus, Growth Booster and Fortune run
    (step 1 of the monthly close). Everyone <strong>not</strong> listed under a frozen month was clear at its as-of instant.
    A <strong>provisional</strong> row was frozen while the month was still in flight (testing) and is replaced by the
    close once the month ends — unless an engine has already frozen its roster against it. Nothing here is recomputed.
</div>
@enddeveloper

<form method="GET" class="flex flex-wrap items-center gap-3 mb-4">
    <input type="month" name="month" value="{{ $selectedMonth?->format('Y-m') }}"
           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
    <x-ui.button type="submit" size="sm">Show month</x-ui.button>
</form>

@if($months->isEmpty())
<x-ui.card flush>
    <x-ui.empty-state title="No month has been frozen yet."
        description="The freeze is written by the monthly close on the 1st, or by the first crediting engine that reaches a closed month." />
</x-ui.card>
@else
<div class="grid gap-4 lg:grid-cols-3">
    <x-ui.card flush class="lg:col-span-1">
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left text-gray-500 font-medium">Month</th>
                        <th class="px-3 py-2 text-left text-gray-500 font-medium">As of</th>
                        <th class="px-3 py-2 text-right text-gray-500 font-medium">Holding a balance</th>
                        <th class="px-3 py-2 text-center text-gray-500 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($months as $row)
                    <tr class="hover:bg-gray-50 {{ $selected?->id === $row->id ? 'bg-brand-50' : '' }}">
                        <td class="px-3 py-2">
                            <a href="{{ route('admin.compensation.repurchase-wallet-freezes.index', ['month' => \Illuminate\Support\Carbon::parse($row->month_start)->format('Y-m')]) }}"
                               class="text-brand-600 hover:underline font-medium">{{ \Illuminate\Support\Carbon::parse($row->month_start)->format('F Y') }}</a>
                        </td>
                        <td class="px-3 py-2 text-gray-600">{{ $row->as_of->format('d M Y H:i:s') }}</td>
                        <td class="px-3 py-2 text-right">{{ \App\Modules\Shared\Support\IndianNumber::format($row->blocked_count) }}</td>
                        <td class="px-3 py-2 text-center">
                            @if($row->provisional)
                                <span class="inline-flex px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 font-semibold">Provisional</span>
                            @else
                                <span class="inline-flex px-1.5 py-0.5 rounded bg-green-100 text-green-700 font-semibold">Final</span>
                            @endif
                            <div class="text-[10px] text-gray-400 mt-0.5">{{ $row->source === 'engine' ? 'freeze engine' : 'on demand' }}</div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2">{{ $months->links() }}</div>
    </x-ui.card>

    <x-ui.card flush class="lg:col-span-2">
        @if($selected === null)
            <x-ui.empty-state title="Pick a month." />
        @else
            <div class="px-3 py-3 border-b border-gray-100 flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
                <span class="font-semibold">{{ $selectedMonth->format('F Y') }}</span>
                <span class="text-gray-500">As of <span class="text-gray-800">{{ $selected->as_of->format('d M Y H:i:s') }}</span></span>
                <span class="text-gray-500">Holding a balance <span class="text-gray-800">{{ \App\Modules\Shared\Support\IndianNumber::format($selected->blocked_count) }}</span></span>
                <span class="text-gray-500">Total held <span class="text-gray-800">₹{{ \App\Modules\Shared\Support\IndianNumber::format($totalHeldPaise / 100, 2) }}</span></span>
                @if($selected->engine_run_id)
                    <a href="{{ route('admin.compensation.engine-runs.events', ['run' => $selected->engine_run_id]) }}" class="text-brand-600 hover:underline text-xs">Run #{{ $selected->engine_run_id }}</a>
                @endif
            </div>
            @if($blocks->isEmpty())
                <x-ui.empty-state title="Nobody held repurchase-wallet money at this instant." />
            @else
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-gray-500 font-medium">S.no</th>
                            <th class="px-3 py-2 text-left text-gray-500 font-medium">Distributor</th>
                            <th class="px-3 py-2 text-right text-gray-500 font-medium">Balance at as-of <x-help-tip text="Repurchase-wallet money still unspent at the frozen instant. This distributor was forfeited Growth Booster and Fortune for the month and fails the rank requalification wallet condition." /></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($blocks as $i => $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2 text-gray-500">{{ $blocks->firstItem() + $i }}</td>
                            <td class="px-3 py-2">
                                <a href="{{ route('admin.compensation.distributors.show', $row->distributor_id) }}"
                                   class="text-brand-600 hover:underline font-medium">{{ $row->full_name ?: 'Distributor' }}</a>
                                <span class="text-gray-400">({{ $row->adn }})</span>
                            </td>
                            <td class="px-3 py-2 text-right font-semibold">₹{{ \App\Modules\Shared\Support\IndianNumber::format($row->balance_paise / 100, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-3 py-2">{{ $blocks->links() }}</div>
            @endif
        @endif
    </x-ui.card>
</div>
@endif
@endsection
```

If the engine-runs events route does not accept a `run` query parameter, drop that anchor (check `AdminEngineRunsController::events()`).

- [ ] **Step 5: Route, nav, registry**

`routes/web.php` after line 701:

```php
        Route::get('repurchase-wallet-freezes', [AdminRepurchaseWalletFreezeController::class, 'index'])->name('repurchase-wallet-freezes.index');
```

(and the `use` at the top of the file, alphabetically with the other `Admin*Controller` imports).

`_nav.blade.php`: add `use App\Modules\Shared\Features\RepurchaseEngineFeature;` and `$repurchaseOn = Feature::for(null)->active(RepurchaseEngineFeature::class);` next to the other flags; in the `'Calculation reports'` list add, after the `ADC Calculation` line:

```php
            $repurchaseOn ? ['label' => 'Repurchase wallet — month-end freeze', 'route' => 'admin.compensation.repurchase-wallet-freezes.index', 'match' => 'admin.compensation.repurchase-wallet-freezes'] : null,
```

`EngineRegistry.php`: set the freeze engine's `reportRouteName` to `'admin.compensation.repurchase-wallet-freezes.index'`.

- [ ] **Step 6: Run — expect PASS** (`--filter="AdminRepurchaseWalletFreezeReportTest|EngineRegistryTest"`). `npm run build` is not needed (no new Tailwind classes beyond those already used elsewhere; if `bg-brand-50` is not in the build, use `bg-gray-100`). Also run `php -l` on the compiled view per the view-lint memory: `docker exec arovolife-app php artisan view:cache && docker exec arovolife-app sh -c 'for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "$f"; done'`.

- [ ] **Step 7: Pint + phpstan; commit**

```bash
git add app/app/Modules/Compensation/Http/Controllers/Admin/AdminRepurchaseWalletFreezeController.php app/resources/views/admin/compensation/repurchase-wallet-freezes/ app/routes/web.php app/resources/views/admin/compensation/_nav.blade.php app/app/Modules/Compensation/Support/EngineRegistry.php app/tests/Modules/Compensation/AdminRepurchaseWalletFreezeReportTest.php
git commit -m "feat(admin): repurchase wallet month-end freeze report"
```

---

### Task 10: Documentation, ADR, risk register, help

**Files:**
- Create: `docs/architecture/adr-0014-repurchase-wallet-month-end-freeze.md`
- Modify: `app/resources/help/compensation.md`, `docs/runbooks/artisan-commands.md`, `docs/compliance/risk-register.md`, `docs/compensation/repurchase-client-examples-2026-09-07.md`, `docs/testing/staging-qa-playbook.md`

- [ ] **Step 1: ADR-0014** — write the file with this content:

```markdown
# ADR-0014 — The month-end repurchase-wallet verdict is frozen once per month

- **Status:** Accepted
- **Date:** 2026-09-14
- **Deciders:** Platform Architect, Compliance Officer, Product Owner
- **Builds on:** ADR-0004 (double-entry ledger); the "freeze the roster, not just the pool" discipline (compensation audit 2026-09-06)
- **Supersedes:** the live-ledger gate reinstated on 2026-09-07 (commit 43f66121)

## Context

Growth Booster, Fortune and the Rank Bonus requalification / AO-GO conditions all require the distributor's
repurchase wallet to be ₹0 at the last instant of the calendar month (client 2026-09-05, re-confirmed 2026-09-07).
Every bonus credit also *writes* to that wallet: 10 % of the gross moves in as a `repurchase_deduction` at credit
time. Inside one monthly close, Rank Bonus (step 2) therefore changes the balances that Growth Booster (step 3)
and Fortune (step 6) are judged on.

Until this ADR the gate summed the ledger live on every call, windowed on `created_at <= <month end 23:59:59>`.
For the scheduled close on the 1st that happened to exclude the earlier steps' writes (they carry next month's
date). For any run whose write clock fell inside the judged month — an `--in-flight` test close, the recompute
tool's catch-up for the current month, a mid-month manual trigger — "as of month end" was a future instant and
the gate degraded to "as of right now", counting the deductions the previous engine had just written. That is the
staging defect of 2026-09-14: wallet at ₹0, GSB/MSB/Rank run, Growth Booster and Fortune forfeit everyone.

A freeze had existed (`repurchase_monthly_snapshots`, 2026-09-05). It was dropped on 09-06 when the deadline moved
onto the distributor's own cycle, and when the calendar-month gate was re-confirmed on 09-07 it came back as a
live query because its table had just gone.

## Options considered

### A. Keep the live read (status quo)
Correct in production only by accident of scheduling; non-deterministic on re-run, in flight and in replay;
its own docblock's determinism claim is false. Rejected.

### B. Defer every repurchase deduction until the whole close has run
Breaks the shipped credit-time invariant (gross / transfer / deduction written atomically with one reference);
opens a window in which the main wallet holds un-deducted gross that the Tuesday weekly payout batch would sweep to
a bank; has no meaning for the daily GSB/MSB engines, which have no "end of batch"; changes *when* money enters
the repurchase wallet, which is plan economics. Rejected.

### C. Answer the question once per month, before any crediting engine, and persist it — **chosen**
A month-typed engine `repurchase.wallet-freeze` runs as step 1 of `compensation:monthly-close` (00:12 in the
replay calendar). It writes a header row per month (`as_of`, `provisional`, `source`, `blocked_count`) and one
row per distributor holding a balance. The gate reads only those rows.

## Decision details

- **Clock.** The frozen balance is the ledger by `created_at` at `as_of = min(month-end 23:59:59 IST, freeze
  instant)`. The write clock is the client's literal rule ("holding a balance at the last instant of the month"),
  is what the repurchase-cycle verdict already uses, and leaves every production verdict unchanged. The earned
  clock (`bonus_month`) was rejected: it would add the last day's GSB deduction — credited at 00:10 the next
  morning — to the judged month, money the distributor could not have spent.
- **Two read modes, two methods.** `clearedAtMonthEnd()` (engines) reads the freeze, freezes a *closed* month on
  demand if nobody has (its answer is fixed), and **refuses** an *open* month nobody has frozen.
  `standingAtMonthEnd()` (dashboard, AO-GO status) never writes. The old `passes()` on the requalification gate is
  deleted so a caller cannot pick the wrong mode.
- **Idempotent, self-healing, never edited.** A final header is never changed (model `updating` guard). A
  provisional header is replaced by the close once the month ends, unless a crediting engine has already frozen a
  roster against it — then it is kept and audit-logged; a recompute is the remedy.
- **Not a payout precondition.** The freeze is a close step but not in `MonthlyEngineCompletionGate::ENGINE_KEYS`:
  it credits nothing, and making it one would refuse the 8th's payout for any month closed before this shipped.
- **Recompute.** Both tables are in `DerivedTables`; the replay re-freezes closed months on the 1st and the
  in-flight month at its catch-up; a partial `--only` selection naming a consumer without the freeze is refused.

## Consequences

- Rank Bonus, Growth Booster and Fortune can never disagree about a month, in any run mode.
- A re-run of a closed month is a true no-op on the verdict.
- Operators have a report naming who was blocked, at what balance, as of which instant.
- Open client questions, tracked as R-85: (1) GSB/MSB deductions credited late in a month count against that
  month's gate with one day or less to spend them; (2) since 2026-09-07 the same unspent balance is judged against
  two deadlines — the cycle's due date and the calendar month end. Neither is changed here.
```

- [ ] **Step 2: Help — `app/resources/help/compensation.md`**

In `### Repurchase interaction` (GBB), replace the sentence beginning "The verdict is decided once, when the month's roster is frozen, and a re-run never re-judges it" with:

> The verdict is frozen **once for the whole month** by the **Repurchase Wallet Month-End Freeze** — step 1 of the monthly close, before any crediting engine — and Rank Bonus, Growth Booster and Fortune all read those rows; a deduction taken by an engine that ran earlier in the same close can never change it, and a re-run never re-judges it. Who was blocked, at what balance and as of which instant is under **Compensation → Calculation reports → Repurchase wallet — month-end freeze**.

In `### Who is enrolled` (Fortune), after "The verdict is written once; a re-run skips the row." add:

> The wallet question itself is answered by the month-end freeze (step 1 of the close), so Fortune and Growth Booster always agree.

In `## Engine Runs`:
- In the bullet **The monthly engines are fired by the monthly close** replace "runs the seven crediting engines on the 1st at 00:20 IST in dependency order — rank qualifications, …" with "runs the month-end repurchase-wallet freeze and then the seven crediting engines on the 1st at 00:20 IST in dependency order — wallet freeze, rank qualifications, …".
- In the bullet **The Rank Qualification Check writes no money** replace "which is why it is step 2 of the close, ahead of all of them" with "which is why it is step 2 of the close (the month-end wallet freeze is step 1), ahead of all of them".
- In the bullet **Only closed periods can be run for the pool engines** add `repurchase:freeze-wallet` to the list of commands that refuse an open `--month`.
- Add a new bullet after **The Rank Qualification Check writes no money**:

> - **The month-end repurchase-wallet verdict is frozen before anything is credited.** `repurchase:freeze-wallet` (Repurchase Wallet Month-End Freeze) records, once per month, who still held repurchase-wallet money at the month's last second. Every engine that takes the 10 % deduction also *adds* to that wallet, so without the freeze the engines later in the close were judging distributors on deductions the earlier engines had just taken. A closed month that reaches Rank Bonus, Growth Booster or Fortune unfrozen is frozen by that engine on the spot (same instant, same answer); a month still in flight is refused until the freeze has run for it. The freeze credits nothing and is not a payout precondition.

- [ ] **Step 3: Runbook — `docs/runbooks/artisan-commands.md`**

After the `### repurchase:evaluate` section add:

````markdown
### `repurchase:freeze-wallet`

Freezes the month-end repurchase-wallet verdict — which distributors still held repurchase-wallet money at the
last second of the month (Asia/Kolkata). Step 1 of `compensation:monthly-close`; Rank Bonus, Growth Booster and
Fortune read the rows it writes (ADR-0014). Credits nothing.

```bash
php artisan repurchase:freeze-wallet                      # the month that has just ended
php artisan repurchase:freeze-wallet --month=2026-08
php artisan repurchase:freeze-wallet --month=2026-09 --in-flight   # testing only: provisional
```

- Idempotent: a frozen month is reported and left alone.
- Refuses a month that has not ended unless `--in-flight` is passed.
- A provisional freeze is replaced by the next run once the month has closed, unless a crediting engine has
  already frozen its roster against it (kept, audit-logged `compensation.repurchase_wallet_freeze.provisional_kept`).
- Report: Admin → Compensation → Calculation reports → Repurchase wallet — month-end freeze.
- Flag: `RepurchaseEngineFeature` — off ⇒ recorded as *skipped*.
````

In the `compensation:recompute-all` section, add "the month-end repurchase-wallet freezes" to **What it destroys**, and in *Reverting it after sign-off* add: "9. **Keep** `repurchase:freeze-wallet`, `RepurchaseWalletFreezeService` and both `repurchase_wallet_month_end_*` tables — they are the production fix for the 2026-09-14 gate defect, not scaffold." In **Scheduled command summary** add a row: `repurchase:freeze-wallet | orchestrated by compensation:monthly-close (step 1, 1st ~00:20; replay position 00:12) | freezes the month-end wallet verdict`.

- [ ] **Step 4: Risk register** — append row R-85:

```
| R-85 | The month-end repurchase-wallet verdict moved with the order the engines ran in | Operational / plan-integrity — High | DSR 2021 Rule 5(1)(c); client rule 2026-09-05 / 09-07; ADR-0014; staging defect 2026-09-14 | The gate summed the wallet ledger live on every call, so inside one close Rank Bonus's 10 % deductions were counted against Growth Booster and Fortune for the same month whenever the write clock fell inside the judged month (in-flight close, recompute catch-up, mid-month manual run); production was correct only because the 1st-of-month schedule writes with next month's date. **Closed by ADR-0014:** the verdict is frozen once per month, before any crediting engine, in `repurchase_wallet_month_end_freezes/_blocks`; engines read only the freeze; a closed month is frozen on demand, an open one is refused; display paths never write; both tables are wiped and re-frozen by a recompute. Residual, both **client decisions, not code**: (1) GSB/MSB deductions credited in the last days of a month count against that month's gate with a day or less to spend them; (2) since 09-07 the same unspent balance is judged against two deadlines (cycle due date and calendar month end) — the 09-06 migration note warned this "punishes one unspent balance twice". Tests: `RepurchaseWalletGateServiceTest`, `RepurchaseWalletFreezeServiceTest`, `MonthlyCloseCommandTest`, `GrowthBoosterBonusServiceTest` (in-flight order), `FortuneBonusServiceTest`. | Compliance | **Mitigated (2026-09-14)** — put (1) and (2) to the client before launch |
```

- [ ] **Step 5: Spec addendum + playbook**

`docs/compensation/repurchase-client-examples-2026-09-07.md` — under `## 8. Implementation deltas vs §4` append:

> - **2026-09-14 — month-end wallet verdict frozen once (ADR-0014).** `RepurchaseWalletGateService` no longer sums the ledger live; `repurchase:freeze-wallet` (step 1 of the close) freezes who held a balance at the month's last second and every engine reads that. Semantics of the gate are unchanged; only its determinism is. Open questions for the client: late-month GSB/MSB deductions; two deadlines on one balance.

`docs/testing/staging-qa-playbook.md` §11 — append item 10:

> 10. `repurchase_wallet_month_end_freezes`: one header per replayed month, `provisional = 1` only for the current month, `blocked_count` = its rows in `_blocks`; every `repurchase_wallet_blocked` row in `gbb_monthly_results` / `fortune_bonus_results` for a month has a matching `_blocks` row (the converse need not hold — a blocked distributor may have earned nothing).

- [ ] **Step 6: Commit**

```bash
git add docs/architecture/adr-0014-repurchase-wallet-month-end-freeze.md app/resources/help/compensation.md docs/runbooks/artisan-commands.md docs/compliance/risk-register.md docs/compensation/repurchase-client-examples-2026-09-07.md docs/testing/staging-qa-playbook.md
git commit -m "docs(compensation): ADR-0014 month-end repurchase-wallet freeze; help, runbook, R-85"
```

---

### Task 11 (OPTIONAL, separate PR, after staging verification): F125 — the replay fires the daily cut-off a day early

**Why it is related:** with the freeze in place, the only remaining way a staging replay's verdict can differ from production is the **last day's** GSB/MSB deduction: production credits it at 00:10 on the 1st (next month, excluded from the gate), the replay at 00:10 on the last day itself (inside the month, included). Production is unaffected either way; this is replay fidelity only.

**Change:**
1. `EngineCadence`: `public const PREVIOUS_DAY_NOTE = 'runs the previous day';` and `public function firesForPreviousDay(): bool { return str_contains((string) $this->note, self::PREVIOUS_DAY_NOTE); }`. Move `EngineHealthService::PREVIOUS_DAY_NOTE` there (delete the private copy).
2. `EngineDefinition::periodForFireOn(Carbon $firedOn): Carbon` = `$this->periodRelativeTo($firedOn)` then `->subDay()` when `$this->cadence->firesForPreviousDay()`. `EngineHealthService::periodForFire()` delegates to it.
3. `EngineReplayService`: use `periodForFireOn()` at lines 170, 306 and 611 instead of `periodRelativeTo()`. After the day loop, when `$to` is before today (a historical window), fire every scheduled, non-orchestrator, selected engine whose cadence `firesForPreviousDay()` once more for period `$to` at `$definition->cadence->atOn($to->copy()->addDay())` — otherwise the window's last day is never cut off.
4. Tests: `CompensationRecomputeTest::'freezes the period in flight at the real clock…'` becomes "cuts off yesterday, not today, when the window ends today" (assert a `gsb.daily-cutoff` run for yesterday stamped at today 00:10 clamped to now, and **no** run for today); `'catches up a closed month…'` keeps its assertions (Aug 31 cut-off fired Sep 1 00:10); add "a historical window cuts off its last day at 00:10 the following morning". `EngineHealthDigestTest` unchanged.
5. Remove item 9 ("Never run a recompute between 00:00 and 00:10 IST") from playbook §11 and the F125 lines in `docs/testing/staging-qa-2026-09-10/deploy-checklist.md` §4a.

Commit as `fix(recompute): fire the daily cut-off for day D at D+1 00:10, as the scheduler does (F125)`.

---

## 6. Verification and deploy

### Local (before asking to merge)
1. `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db arovolife-app php artisan test --compact --filter="Repurchase|Wallet|MonthlyClose|Engine|Recompute|GrowthBooster|Fortune|RankBonus|Rank|Aogo|Income|Dashboard|DerivedTables|Admin"` — all green.
2. Full suite once: `docker exec -e DB_CONNECTION=mysql -e DB_DATABASE=arovolife_test -e DB_HOST=db arovolife-app php artisan test --compact` (the two known env-dependent failures `GoogleAnalyticsConsentGateTest` / `HardeningTest` SEC-08 are pre-existing).
3. `vendor/bin/pint --test`; `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` over every changed `app/` file.
4. Reproduce the bug end to end on the dev DB (repurchase flag ON, `COMP_RECOMPUTE_ENABLED` open): bring one distributor's wallet to ₹0 with a repurchase order, then `php artisan compensation:monthly-close --month=<current> --in-flight`. Expect: step 1 freeze provisional, that distributor **credited** by GBB/Fortune (if otherwise eligible); a distributor with an unspent GSB deduction from earlier in the month **blocked**. Then re-run the close: every step "already succeeded — resuming past it".
5. Confirm with the user before pushing or merging (solo-dev rule).

### Staging
1. Deploy; `php artisan migrate` (two additive tables); restart the queue workers and the scheduler (WorkerFreshness).
2. `php artisan repurchase:freeze-wallet --month=<last closed month>` — final header; open the report.
3. Full recompute from the Engine Runs page (not between 00:00–00:10 IST until Task 11 ships); verify playbook §11 items 1–10.
4. In-flight close for the current month via the developer gate; verify the user's scenario as in Local step 4.

### Production
- **Window:** not between 00:00 and 04:30 IST on the 1st, nor 03:30–04:30 IST on the 8th.
- Deploy; `php artisan migrate`; restart workers + scheduler. **If `RepurchaseEngineFeature` is OFF in production, behaviour is unchanged in every respect** (gate all-clear, freeze recorded `skipped`, report 404).
- If it is ON: nothing to run by hand. On the next 1st, check Engine Runs: `Repurchase Wallet Month-End Freeze` succeeded before `Rank Qualification Check`; the report's month row is *Final* with as-of = last second of the month; GBB/Fortune `wallet_blocked` counts ≤ the header's `blocked_count`.
- **Rollback:** revert the merge commit. The two tables may stay (unused, harmless); do not drop them under a live close.

## 7. Open client questions (not blocking; put to the client before launch)

1. GSB/MSB deductions credited on the last days of a month count against that month's gate, leaving a day or less to spend them. Intended?
2. Since 2026-09-07 one unspent balance is judged against **two** deadlines — the cycle's due date and the calendar month end. The 09-06 migration explicitly warned this "punishes one unspent balance twice". Intended?

Neither changes this plan; both are recorded in R-85 and ADR-0014.
