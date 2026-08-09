# Fortune Bonus Level-Cascade Distribution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Fortune Bonus single-global-point-value distribution with KP's 2026-08-09 level-cascade model: a ₹30 guarantee for every qualifier, per-matrix-level caps (₹30k/₹30k/₹30k/₹30k/₹20k/₹10k/₹5k for levels 0–6), a per-level recomputed point value, one shared residual value for levels 7–8, flat ₹30 at level 9, and new per-depth points 9/8/7/6/5/4/3/2/1.

**Architecture:** The distribution math moves out of `FortuneBonusService` into a new pure calculator class (`FortuneDistributionCalculator`) that is unit-tested against KP's full worked example. The service keeps its existing two-phase shape — freeze the month's economics, then credit idempotently — but the freeze now snapshots **per-level** economics into a new `fortune_monthly_pool_levels` child table. Enrolment (gates, FCFS placement, matrix geometry) is untouched: KP's new doc restates the already-shipped 7 rules verbatim.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit/Pest 4 (SQLite `:memory:` for tests), Pint, Larastan L7.

## What is NOT changing (verified against the shipped code — do not touch)

- Eligibility gates: `fortune_bonus_tiers` already holds new_joiner (3,000 BV + slab 1), non_ranked (600 BV + title + 1 slab), rank 1–5 = 1,000/1,100/1,200/1,300/1,400 BV with 8/11/14/17/20 slab-achievements. Ranks 6–9 excluded. Matches KP's new rules 1–7 exactly.
- FCFS 3×9 matrix placement by first GSB credit date, monthly reset, post-freeze enrolment refusal.
- Pool base: 5% (`comp.fortune.pool_rate_bp`) of the month's company BV via `GsbDailyPoolService::companyBvPaiseBetween()`.
- Points flow upward from the enrolled downline only (`buildPointsByDistributor` walk); only the per-depth values change.
- FB Monthly Calculation report columns (S.No/ADN/Arete Center/Name/Title/Rank/Date/Level/FB Points/Value/Income + ADN/name search) — already match KP's mock.
- Admin charge + TDS at payout time; `fortune_credit` stays in Group B and the monthly cap set.
- Feature flag `FortuneBonusFeature` stays **OFF** (DSA §6.2 notice period, risk R-37).

## Confirmed decisions (user, 2026-08-09)

1. **Sparse months use absolute levels.** Caps always belong to matrix levels 0–6, residual split to 7–8, flat ₹30 to 9 — even when the month fills only a few levels. Unspent pool stays as leftover.
2. **₹30 guarantee pro-rates on shortfall.** If pool < ₹30 × qualifiers, each qualifier gets `floor_to_whole_rupee(pool ÷ qualifiers)` and nothing else; remainder is leftover; pool is never overspent. A ₹0 pool month pays ₹0 (rows recorded as skipped).

## The cascade algorithm (normative — validated against KP's ₹36cr example)

All amounts in **paise**. `floor_rupee(x) = intdiv(intdiv(x, divisor), 100) * 100` style whole-rupee flooring, clamped ≥ 0.

```
N        = enrolled participants
pool     = max(0, company_bv × rate_bp / 10000)
minC     = comp.fortune.min_commission_paise (default 3000 = ₹30)

if N == 0: nothing to do (freeze pool row with zero economics)

if pool < minC × N:                       # shortfall month
    perHead = floor_rupee(pool / N)       # whole rupees
    every participant income = perHead
    leftover = pool − perHead × N
else:
    remaining       = pool − minC × N     # every income below already includes minC
    remainingPoints = Σ points of ALL participants
    for each CAPPED level L (mode 'capped'), ascending:
        value_L = remainingPoints > 0 ? floor_rupee(remaining / remainingPoints) : 0
        for each participant p at L:
            pointEarn = min(points_p × value_L, cap_L − minC)
            income_p  = minC + pointEarn
            remaining −= pointEarn
        remainingPoints −= Σ points at L
    # residual levels share ONE value computed over their COMBINED points
    residualPoints = Σ points of all 'residual'-mode levels present
    value_R = residualPoints > 0 ? floor_rupee(remaining / residualPoints) : 0
    for each participant p at a residual level:
        income_p = minC + points_p × value_R          # no cap
    for each participant p at a 'flat_min' level:
        income_p = minC
    leftover = pool − Σ income_p
```

Key subtleties the example proves:
- The capped-level denominator is **all remaining points** (including residual levels' points), recomputed after every capped level. Example values: L0→₹13, L1→₹13, L2→₹14, L3→₹15, L4→₹16, L5→₹18, L6→₹19.
- Levels 7 and 8 get **one shared** value (₹20 in the example), *not* per-level recomputation.
- Only the point-earnings part (income − minC) is deducted from `remaining`, because minC × N was reserved up front.
- The cap **includes** the ₹30 (`"maximum of ₹30,000, including their personal account ₹30"`), hence `cap_L − minC` as the point-earnings ceiling.
- Zero-points participants at any paying level still receive ₹30 and are **credited**, not skipped. `skipped` remains only for genuinely-zero incomes (₹0-pool months).

## Global Constraints

- `declare(strict_types=1);` in every PHP file; Pint (`vendor/bin/pint --dirty --format agent`) before finalizing; Larastan level 7 must pass (`vendor/bin/phpstan analyse` per project config).
- All money is integer **paise**; all display via `IndianNumber::format` / `@bv` — never `Number::format`.
- Point values floor to whole rupees (multiples of 100 paise), consistent with GSB/MSB/GBB/Rank pools.
- Frozen economics: pool + per-level values written once before any credit, never recomputed; re-runs price against the snapshot.
- One concern per migration, named `2026_08_09_HHMMSS_<verb>_<noun>.php`, under `app/Modules/Compensation/Database/Migrations/`.
- Tests must run against the isolated test DB (SQLite `:memory:` via phpunit config) — never against the `arovolife` dev DB.
- No "luck"/"chance" wording in ANY user-facing copy (compliance R-37); describe FB factually as a participation-based monthly pool.
- Commits: Conventional Commits; every commit touching this engine carries trailer `Compliance-Review: compliance-officer` (run the subagent before merge, Task 10).
- Working dir for all commands: `/Users/preetham/Documents/arovolife/arovolife/arovolife-code/app`.
- Follow existing sibling-file conventions (final classes, promoted constructors, PHPDoc array shapes).

---

### Task 1: Level config schema + new defaults (migration, seeder, settings service)

**Files:**
- Create: `app/Modules/Compensation/Database/Migrations/2026_08_09_100001_add_cascade_config_to_fortune_bonus_levels.php`
- Modify: `database/seeders/FortuneBonusLevelsSeeder.php`
- Modify: `app/Modules/Compensation/Services/CompensationPlanSettingsService.php`
- Test: `tests/Modules/Compensation/FortuneBonusServiceTest.php` (add settings-accessor cases; follow the file's existing style)

**Interfaces:**
- Produces: `CompensationPlanSettingsService::fortuneMinCommissionPaise(): int`, `CompensationPlanSettingsService::fortuneLevelConfigs(): array` returning `array<int, array{payout_mode: string, cap_paise: ?int, points_per_member: int}>` keyed by level 0–9, and the existing `fortunePointsForDepth(int $depth): int` (unchanged signature, new seeded values 9/8/7/6/5/4/3/2/1).
- Note the table's double duty: `points_per_member` on row *d* is read by **relative depth** (`fortunePointsForDepth`), while `payout_mode`/`cap_paise` on row *L* are read by **absolute matrix level**. Document this in the seeder PHPDoc.

- [ ] **Step 1: Migration** — add `payout_mode` (string, default `'capped'`) and `cap_paise` (nullable unsignedBigInteger) to `fortune_bonus_levels`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KP 2026-08-09 Fortune cascade: each ABSOLUTE matrix level now carries a
 * payout mode — 'capped' (per-level value, per-member rupee ceiling),
 * 'residual' (one shared value over the residual levels' combined points,
 * no cap) or 'flat_min' (the ₹30 minimum only) — and, for capped levels,
 * the per-member cap in paise (cap INCLUDES the ₹30 minimum).
 *
 * A plain string column, not an enum: SQLite CHECK constraints make enum
 * widening painful (see gbb engine note); values are validated at the
 * admin form and defaulted in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fortune_bonus_levels', function (Blueprint $table): void {
            $table->string('payout_mode', 16)->default('capped')->after('points_per_member');
            $table->unsignedBigInteger('cap_paise')->nullable()->after('payout_mode');
        });
    }

    public function down(): void
    {
        Schema::table('fortune_bonus_levels', function (Blueprint $table): void {
            $table->dropColumn(['payout_mode', 'cap_paise']);
        });
    }
};
```

- [ ] **Step 2: Reseed** — replace the `$pointsByLevel` block in `FortuneBonusLevelsSeeder` with the new per-depth points AND per-absolute-level modes/caps (upsert keyed on `level`, updating `points_per_member`, `payout_mode`, `cap_paise`, `is_active`, `updated_at`):

```php
// level => [points_per_member (read by relative DEPTH), payout_mode, cap_paise]
// KP 2026-08-09: depth points 9/8/7/6/5/4/3/2/1; absolute-level caps
// ₹30k (L0–L3), ₹20k (L4), ₹10k (L5), ₹5k (L6); L7–L8 residual; L9 flat ₹30.
$levels = [
    0 => [0, 'capped', 3_000_000],
    1 => [9, 'capped', 3_000_000],
    2 => [8, 'capped', 3_000_000],
    3 => [7, 'capped', 3_000_000],
    4 => [6, 'capped', 2_000_000],
    5 => [5, 'capped', 1_000_000],
    6 => [4, 'capped', 500_000],
    7 => [3, 'residual', null],
    8 => [2, 'residual', null],
    9 => [1, 'flat_min', null],
];
```

Update the seeder PHPDoc: points are keyed by relative depth (1L→9P … 9L→1P), modes/caps by absolute level; cap includes the ₹30 minimum.

- [ ] **Step 3: Settings service** — in `CompensationPlanSettingsService`:
  - Add to `DEFAULTS` (next to `'comp.fortune.pool_rate_bp' => 500`): `'comp.fortune.min_commission_paise' => 3000,`.
  - Add accessor mirroring `fortunePoolRateBp()`:

```php
/** The ₹30 minimum commission every FB qualifier receives (KP 2026-08-09). */
public function fortuneMinCommissionPaise(): int
{
    return $this->scalarInt('comp.fortune.min_commission_paise');
}
```

  - Extend the existing `fortune_bonus_levels` cache (`fortuneLevelPoints()` region) with:

```php
/**
 * Full per-level cascade config keyed by ABSOLUTE matrix level 0–9.
 *
 * @return array<int, array{payout_mode: string, cap_paise: ?int, points_per_member: int}>
 */
public function fortuneLevelConfigs(): array
{
    if ($this->fortuneLevelConfigCache === null) {
        $this->fortuneLevelConfigCache = [];
        foreach (DB::table('fortune_bonus_levels')->orderBy('level')->get() as $row) {
            $this->fortuneLevelConfigCache[(int) $row->level] = [
                'payout_mode' => (string) $row->payout_mode,
                'cap_paise' => $row->cap_paise === null ? null : (int) $row->cap_paise,
                'points_per_member' => (int) $row->points_per_member,
            ];
        }
    }

    return $this->fortuneLevelConfigCache;
}
```

with a `private ?array $fortuneLevelConfigCache = null;` property, and reset it wherever `fortunePointsCache` is reset (search the class for the cache-clear method used after settings writes).

- [ ] **Step 4: Run migration + seeder on dev** (forward-only, additive — no confirmation needed): `php artisan migrate && php artisan db:seed --class=FortuneBonusLevelsSeeder`.
- [ ] **Step 5: Tests** — add cases asserting `fortunePointsForDepth(1) === 9 … fortunePointsForDepth(9) === 1`, `fortuneLevelConfigs()[0]['cap_paise'] === 3_000_000`, `fortuneLevelConfigs()[7]['payout_mode'] === 'residual'`, `fortuneMinCommissionPaise() === 3000` (seed the levels table in the test the way the existing tests do). Run: `php artisan test --compact --filter=FortuneBonus`. Expected: new cases pass; existing distribution tests MAY now fail (old 9/9/9… expectations) — do not fix them here, they are rewritten in Task 4.
- [ ] **Step 6: Commit** — `feat(compensation): fortune level cascade config — modes, caps, 9..1 depth points` + `Compliance-Review: compliance-officer` trailer.

---

### Task 2: Pure allocator `FortuneDistributionCalculator` + KP worked-example regression test

**Files:**
- Create: `app/Modules/Compensation/Services/FortuneDistributionCalculator.php`
- Create: `tests/Modules/Compensation/FortuneDistributionCalculatorTest.php`

**Interfaces:**
- Consumes: level configs shaped as Task 1's `fortuneLevelConfigs()` output.
- Produces:

```php
/**
 * @param array<int, array{position: int, matrix_level: int, points: int}> $participants
 * @param array<int, array{payout_mode: string, cap_paise: ?int}> $levelConfigs keyed by absolute level
 * @return array{
 *   incomes: array<int, int>,            // position → gross paise (includes the minimum)
 *   levels: array<int, array{payout_mode: string, cap_paise: ?int, participants: int, points: int, point_value_paise: int, paid_paise: int}>,
 *   guaranteed_total_paise: int,
 *   leftover_paise: int,
 *   is_shortfall: bool,
 *   shortfall_per_head_paise: ?int,
 * }
 */
public function allocate(array $participants, int $poolPaise, int $minCommissionPaise, array $levelConfigs): array
```

- [ ] **Step 1: Write the failing test** — the centrepiece is KP's exact ₹36cr example over the full 29,524-member matrix. Build participants synthetically (no DB):

```php
<?php

declare(strict_types=1);

// Match the existing test file's base class / Pest style in tests/Modules/Compensation/.

/**
 * Uniform full-matrix points: a member at absolute level L earns from the
 * min(9, 9−L) levels below: Σ_{d=1..min(9,9−L)} 3^d × pointsPerDepth(d),
 * pointsPerDepth = [1=>9, 2=>8, 3=>7, 4=>6, 5=>5, 6=>4, 7=>3, 8=>2, 9=>1].
 */
function fullMatrixParticipants(): array
{
    $depthPoints = [1 => 9, 2 => 8, 3 => 7, 4 => 6, 5 => 5, 6 => 4, 7 => 3, 8 => 2, 9 => 1];
    $participants = [];
    $position = 1;
    foreach (range(0, 9) as $level) {
        $points = 0;
        for ($d = 1; $d <= min(9, 9 - $level); $d++) {
            $points += (3 ** $d) * $depthPoints[$d];
        }
        for ($i = 0; $i < 3 ** $level; $i++) {
            $participants[] = ['position' => $position++, 'matrix_level' => $level, 'points' => $points];
        }
    }

    return $participants;
}

function kpLevelConfigs(): array
{
    return [
        0 => ['payout_mode' => 'capped', 'cap_paise' => 3_000_000],
        1 => ['payout_mode' => 'capped', 'cap_paise' => 3_000_000],
        2 => ['payout_mode' => 'capped', 'cap_paise' => 3_000_000],
        3 => ['payout_mode' => 'capped', 'cap_paise' => 3_000_000],
        4 => ['payout_mode' => 'capped', 'cap_paise' => 2_000_000],
        5 => ['payout_mode' => 'capped', 'cap_paise' => 1_000_000],
        6 => ['payout_mode' => 'capped', 'cap_paise' => 500_000],
        7 => ['payout_mode' => 'residual', 'cap_paise' => null],
        8 => ['payout_mode' => 'residual', 'cap_paise' => null],
        9 => ['payout_mode' => 'flat_min', 'cap_paise' => null],
    ];
}
```

Assertions for `allocate(fullMatrixParticipants(), 1_80_00_000_00, 3000, kpLevelConfigs())` — every number from KP's sheet:
  - per-member points sanity: level totals 44,271 / 73,764 / 1,03,194 / 1,32,435 / 1,61,109 / 1,88,082 / 2,09,952 / 2,16,513 / 1,77,147 (Σ participants' points per level); total 13,06,467.
  - `guaranteed_total_paise === 29_524 * 3000` (₹8,85,720).
  - per-level `point_value_paise`: L0 1300, L1 1300, L2 1400, L3 1500, L4 1600, L5 1800, L6 1900, L7 2000, L8 2000, L9 0.
  - incomes (pick one member per level): L0–L3 members `3_000_000` each; L4 `2_000_000`; L5 `1_000_000`; L6 `500_000`; every L7 member `2010_00` (99 pts × ₹20 + ₹30); every L8 member `570_00` (27 pts × ₹20 + ₹30); every L9 member `3000`.
  - per-level `paid_paise`: L7 `43_95_870_00`, L8 `37_39_770_00`, L9 `5_90_490_00`.
  - `leftover_paise === 3_78_870_00` and `Σ incomes + leftover === pool`.
  - `is_shortfall === false`, `shortfall_per_head_paise === null`.

Also write (same file) the edge cases:
  - **Sparse month, absolute levels:** 5 participants (positions 1–5 → levels 0,1,1,1,2 per `levelFromPosition`), points from the real depth walk (position 1 has 3 depth-1 members ×9 + 1 depth-2 member ×8 = 35 pts; positions 2–4: position 2 is parent of position 5 → 9 pts, positions 3,4 → 0; position 5 → 0). Pool ₹1,00,000, minC ₹30: value_L0 = floor(99,850/44) = wait — compute in test with explicit expected numbers: remaining = 10_000_000 − 5×3000 = 99_85_000; totalPoints = 44; value_L0 = floor_rupee(99_85_000/44) = 2_26_900 (₹2,269); L0 income = min(3000 + 35×2_26_900, 3_000_000) = 3_000_000 (capped). remaining = 99_85_000 − 2_99_7000… (compute carefully: cap−minC = 2_99_7000 → remaining 96_88_000, points left 9); value_L1 = floor_rupee(96_88_000/9) = 1_07_600 (₹1,076); position 2 income = min(3000 + 9×1_07_600, 3_000_000) = 9_71_400; positions 3,4 income = 3000 each (zero points); position 5 (level 2, capped, 0 pts) = 3000. leftover = pool − Σ. Assert Σ + leftover === pool and each income exactly.
  - **Shortfall:** 3 participants, pool 5000, minC 3000 → 9000 > 5000 → each income `floor_rupee(5000/3) = 1600` (₹16), leftover 200, `is_shortfall === true`, `shortfall_per_head_paise === 1600`.
  - **Zero pool:** pool 0 → every income 0, leftover 0, shortfall true, per-head 0.
  - **Empty month:** `allocate([], …)` → empty incomes, leftover === pool, no division by zero.
  - **Residual-points zero:** all participants at flat/zero-point positions → value 0, incomes = minC, leftover = pool − N×minC.

- [ ] **Step 2: Run to verify failure** — `php artisan test --compact --filter=FortuneDistributionCalculator`. Expected: FAIL, class not found.
- [ ] **Step 3: Implement**:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

/**
 * Pure Fortune Bonus cascade allocator (KP 2026-08-09). No DB, no clock —
 * everything it needs comes in as arguments so the exact KP worked example
 * is unit-testable and the frozen snapshot is reproducible on re-runs.
 *
 * Modes per ABSOLUTE matrix level:
 *  - capped:   value = floor_rupee(remaining ÷ ALL remaining points), income
 *              = min(minC + points × value, cap); recomputed per level.
 *  - residual: ONE shared value over the residual levels' combined points,
 *              income = minC + points × value, no cap.
 *  - flat_min: income = minC.
 *
 * The minimum commission (minC × N) is reserved off the pool up front; when
 * the pool cannot cover it, everyone gets floor_rupee(pool ÷ N) and nothing
 * else (user decision 2026-08-09 — the pool is never overspent).
 */
final class FortuneDistributionCalculator
{
    private const string MODE_CAPPED = 'capped';

    private const string MODE_RESIDUAL = 'residual';

    public function allocate(array $participants, int $poolPaise, int $minCommissionPaise, array $levelConfigs): array
    {
        // Implementation outline (write it single-pass and readable):
        // 1. Group participant indexes + point sums by matrix_level (ascending).
        // 2. N == 0 → zero result early-return.
        // 3. pool < minC×N → shortfall branch (floor_rupee(pool/N) per head,
        //    level rows still emitted with point_value_paise = per-head? NO —
        //    emit point_value_paise 0 and paid_paise = per-head × members).
        // 4. Otherwise walk levels ascending: capped levels as spec'd;
        //    collect residual levels first, compute their shared value once
        //    from the remaining pool AFTER the last capped level, then pay
        //    them and flat levels.
        // floor_rupee(int $amount, int $points): int
        //   => $points > 0 ? max(0, intdiv(intdiv($amount, $points), 100) * 100) : 0
    }
}
```

Write the real method (no outline comments left behind) following that logic exactly; keep it one public method plus small private helpers (`floorRupee()`, `groupByLevel()`).

- [ ] **Step 4: Run tests to green** — `php artisan test --compact --filter=FortuneDistributionCalculator`. Expected: PASS, including every KP number.
- [ ] **Step 5: Commit** — `feat(compensation): pure fortune cascade allocator with KP ₹36cr regression fixture` + compliance trailer.

---

### Task 3: Snapshot schema — pool columns + `fortune_monthly_pool_levels`

**Files:**
- Create: `app/Modules/Compensation/Database/Migrations/2026_08_09_100002_add_cascade_columns_to_fortune_monthly_pools.php`
- Create: `app/Modules/Compensation/Database/Migrations/2026_08_09_100003_create_fortune_monthly_pool_levels_table.php`
- Create: `app/Modules/Compensation/Models/FortuneMonthlyPoolLevel.php`
- Modify: `app/Modules/Compensation/Models/FortuneMonthlyPool.php`
- Create: `app/Modules/Compensation/Database/Migrations/2026_08_09_100004_add_cascade_columns_to_fortune_bonus_results.php`
- Modify: `app/Modules/Compensation/Models/FortuneBonusResult.php`

**Interfaces:**
- Produces: `FortuneMonthlyPool` new nullable columns `min_commission_paise`, `guaranteed_total_paise`, `is_shortfall` (bool default false), `shortfall_per_head_paise` (nullable); existing `point_value_paise` made **nullable** (legacy single-value months keep theirs; cascade months write NULL). `FortuneMonthlyPool::levels(): HasMany` → `FortuneMonthlyPoolLevel`.
- `fortune_monthly_pool_levels` columns: `id`, `fortune_monthly_pool_id` (FK, cascade delete), `matrix_level` (unsignedTinyInteger), `payout_mode` (string 16), `cap_paise` (nullable unsignedBigInteger), `participants` (unsignedInteger), `points` (unsignedBigInteger), `point_value_paise` (unsignedBigInteger), `paid_paise` (unsignedBigInteger), timestamps, unique `(fortune_monthly_pool_id, matrix_level)`.
- `fortune_bonus_results` gains `min_commission_paise` (unsignedInteger default 0) and `cap_paise` (nullable unsignedBigInteger) after `point_value_paise`; `point_value_paise` semantics become "the value applied at this row's level".

- [ ] **Step 1:** Write the three migrations (one concern each; `->change()` for the nullable `point_value_paise` — works on both MySQL and SQLite in Laravel 13). Add casts + `$fillable` entries on both models; add the `levels()` relation and `FortuneMonthlyPoolLevel` model (`final`, guarded fillable, integer casts, `belongsTo(FortuneMonthlyPool::class)`).
- [ ] **Step 2:** `php artisan migrate` (forward-only, additive) and `php artisan test --compact --filter=Fortune` to confirm nothing regressed structurally.
- [ ] **Step 3: Commit** — `feat(compensation): per-level fortune pool snapshot schema` + compliance trailer.

---

### Task 4: Rewire `FortuneBonusService::runForMonth()` onto the allocator

**Files:**
- Modify: `app/Modules/Compensation/Services/FortuneBonusService.php`
- Modify: `app/Modules/Compensation/Console/Commands/FortuneBonusRunCommand.php` (summary keys)
- Test: `tests/Modules/Compensation/FortuneBonusServiceTest.php`

**Interfaces:**
- Consumes: `FortuneDistributionCalculator::allocate()` (Task 2 shape), `CompensationPlanSettingsService::fortuneLevelConfigs()` / `fortuneMinCommissionPaise()` (Task 1), snapshot models (Task 3).
- Produces: `runForMonth(Carbon $month): array{credited: int, skipped_zero_income: int, total_net_paise: int, pool_paise: int, total_points: int, guaranteed_total_paise: int, leftover_paise: int, is_shortfall: bool}` (the `point_value_paise` key is gone — values are per level now).

- [ ] **Step 1: Rewrite the failing tests first.** In `FortuneBonusServiceTest`, replace the old single-value distribution expectations with cascade expectations (keep every enrolment/gate test untouched). Cover at minimum, using seeded Task-1 level configs:
  - a 5-participant month (as in Task 2's sparse case, but end-to-end through the service with real GSB/BV fixtures the file already builds): assert each `fortune_bonus_results` row's `points`, `point_value_paise` (their level's value), `min_commission_paise = 3000`, `cap_paise`, `gross_paise`, wallet `fortune_credit` entries equal gross, and pool row + 3 `fortune_monthly_pool_levels` rows (levels 0,1,2) with the exact values from the Task-2 sparse arithmetic.
  - zero-points participant → `gross_paise = 3000`, status **credited**, wallet entry written.
  - shortfall month → every row = per-head, `is_shortfall` true on the pool, `shortfall_per_head_paise` recorded; zero-pool month → rows `skipped`, no wallet entries.
  - idempotent re-run: run twice, assert wallet entries and results unchanged; delete one result row, re-run, assert that distributor is re-credited at the **frozen** per-level value.
  - post-freeze enrolment still refused (existing test — keep green).
- [ ] **Step 2: Run to verify the new tests fail** — `php artisan test --compact --filter=FortuneBonusService`.
- [ ] **Step 3: Implement.** In `runForMonth()`:
  - Build `$pointsByDistributor` (unchanged), then a participants array `[{position, matrix_level, points}]`.
  - Replace `freezePoolForMonth(...)` internals: compute pool as today; call `allocate($participantRows, $poolPaise, $this->plan->fortuneMinCommissionPaise(), $this->plan->fortuneLevelConfigs())`; create the `FortuneMonthlyPool` row with `point_value_paise = null`, `payout_paise = Σ incomes`, `leftover_paise`, `min_commission_paise`, `guaranteed_total_paise`, `is_shortfall`, `shortfall_per_head_paise`; create one `FortuneMonthlyPoolLevel` per level present; keep the `fortune.pool.frozen` Log + AuditLog with the per-level breakdown in `details`.
  - **Idempotent reconstruction:** when the pool row already exists, do NOT re-allocate from live config — rebuild each participant's income from the frozen `fortune_monthly_pool_levels` rows: capped → `min(minC + points × level.point_value_paise, level.cap_paise)`; residual → `minC + points × level.point_value_paise`; flat → `minC`; shortfall month → `shortfall_per_head_paise` (minC = the pool row's frozen `min_commission_paise`). Extract this into a private `incomeFromFrozenLevels(FortuneMonthlyPool $pool, int $matrixLevel, int $points): int` used by both fresh runs (sanity: fresh runs just use the allocator's `incomes` map) and re-runs.
  - Credit loop: unchanged skeleton; `gross = income`; write `min_commission_paise` and `cap_paise` onto the result row; `status = skipped` only when `gross === 0`; wallet memo: `'Fortune Bonus L'.$level.' '.$points.' pts @ ₹'.IndianNumber::format($levelValue / 100, 2).' + ₹'.IndianNumber::format($minC / 100, 2).' min '.$monthStart` (shortfall months: `'Fortune Bonus pro-rated minimum '.$monthStart`).
  - Update the return array keys; update `FortuneBonusRunCommand` to print the new keys (`skipped_zero_income`, `guaranteed_total_paise`, `leftover_paise`, `is_shortfall`, no single point value).
  - Update the class-level PHPDoc (the ENTITLEMENT/FROZEN ECONOMICS paragraphs) to describe the cascade.
- [ ] **Step 4: Run tests to green** — `php artisan test --compact --filter=Fortune` (service + calculator + admin reports). Fix any admin-report test fallout only if it's about the changed return keys; view fallout belongs to Task 6.
- [ ] **Step 5: Commit** — `feat(compensation): fortune level-cascade distribution engine (KP 2026-08-09)` + compliance trailer.

---

### Task 5: Admin plan-settings — edit modes, caps and the ₹30 minimum

**Files:**
- Modify: `app/Modules/Compensation/Http/Controllers/Admin/AdminPlanSettingsController.php` (`updateFortuneLevel`)
- Modify: `resources/views/admin/compensation/plan-settings/index.blade.php` (fortune levels section + fortune scalar section)

**Interfaces:**
- Consumes: Task 1 columns. Mirror exactly how `comp.fortune.pool_rate_bp` is registered/rendered/authorized for the new `comp.fortune.min_commission_paise` scalar (same ownership: per-key settings ownership, developer-owned like its siblings — copy whatever `pool_rate_bp` does).

- [ ] **Step 1:** Extend `updateFortuneLevel` validation:

```php
$data = $request->validate([
    'points_per_member' => ['required', 'integer', 'min:0', 'max:1000000'],
    'payout_mode' => ['required', 'in:capped,residual,flat_min'],
    'cap_paise' => ['nullable', 'required_if:payout_mode,capped', 'integer', 'min:0', 'max:100000000000'],
    'is_active' => ['nullable', 'boolean'],
]);
```

Add a `withValidator`-style check (or inline after validate): when mode is `capped`, `cap_paise` must be `>=` the current `comp.fortune.min_commission_paise` (the cap includes the minimum). Persist `payout_mode` and `cap_paise` (`null` unless capped) through the same `persistRow` call so the audit log captures before/after.

- [ ] **Step 2:** In the plan-settings blade, add the mode `<select>` and cap input (₹, converted to paise the way sibling paise fields on that page do) to each fortune-level row, and the min-commission scalar next to the fortune pool-rate field. Follow the page's existing hover-tooltip + confirmation-modal conventions (platform UI memory).
- [ ] **Step 3:** Extend the existing plan-settings feature test (find the test covering `updateFortuneLevel`) with: capped mode without cap → 422; cap below min commission → validation error; residual mode nulls the cap. Run the file's filter.
- [ ] **Step 4: Commit** — `feat(admin): fortune cascade config on plan-settings` + compliance trailer.

---

### Task 6: Admin economics surfaces (fortune-bonus index/show, FB calculation report)

**Files:**
- Modify: `app/Modules/Compensation/Http/Controllers/Admin/AdminFortuneBonusController.php`
- Modify: `resources/views/admin/compensation/fortune-bonus/index.blade.php`, `resources/views/admin/compensation/fortune-bonus/show.blade.php`
- Modify: `resources/views/admin/compensation/fb-calculation/index.blade.php` (copy only)
- Test: `tests/Modules/Compensation/AdminFortuneReportsTest.php`

- [ ] **Step 1:** Wherever the views/controller display the month's single `point_value_paise`, branch: cascade months (`point_value_paise === null`) render a per-level economics table from `$pool->levels` — columns Level / Mode / Members / Points / Point value / Cap / Paid — plus the guarantee line ("Minimum ₹30 × N = ₹X reserved"), shortfall notice when `is_shortfall`, and leftover. Legacy months keep the old single-value rendering (historical rows must not break).
- [ ] **Step 2:** FB calculation report: the per-row `Value` column already reads `fortune_bonus_results.point_value_paise`, which now holds the level value — update the explainer copy on `fb-calculation/index.blade.php` (and the controller PHPDoc) from "the month's point value" to "the value applied at the distributor's matrix level; income includes the ₹30 minimum and is capped per level". No "luck/chance" wording anywhere.
- [ ] **Step 3:** Update `AdminFortuneReportsTest` for the new rendering (assert a cascade month shows the per-level table and a ₹30 minimum line; assert legacy single-value months still render). Run its filter to green.
- [ ] **Step 4: Commit** — `feat(admin): per-level fortune pool economics on admin surfaces` + compliance trailer.

---

### Task 7: Distributor income page copy

**Files:**
- Modify: `resources/views/income/fortune-bonus.blade.php` (and its controller only if it passes the single point value)

- [ ] **Step 1:** Update the explainer paragraph (see current screenshot copy: "Your bonus is your FB points multiplied by the month's point value...") to the cascade truth, factually and without projections: FB points from the enrolled distributors below you; a ₹30 minimum for every qualifier; a per-level point value; per-level maximums (₹30,000 levels 0–3, ₹20,000 level 4, ₹10,000 level 5, ₹5,000 level 6); levels 7–8 share the remaining pool by points; level 9 receives the ₹30 minimum. If the view shows a per-month "point value" figure per historical row, keep reading the row's own `point_value_paise`. Follow `arovolife-ux-writing` — no earnings projections, no "luck"/"chance", lowercase "arovolife".
- [ ] **Step 2:** Eyeball via the existing blade tests if any cover this page; otherwise `php artisan view:cache` compile check (`php artisan view:clear && php artisan view:cache`) to prove the blade parses, then clear the cache again.
- [ ] **Step 3: Commit** — `feat(ui): fortune bonus cascade copy on distributor income page` + compliance trailer.

---

### Task 8: Docs — help page, skill supersession, risk register

**Files:**
- Modify: `resources/help/compensation.md` (fortune section)
- Modify: `.claude/skills/arovolife-compensation-plan/SKILL.md` (repo root) — the "FORTUNE BONUS SUPERSEDED by KP's 2026-08-07 rework" banner
- Modify: `docs/compliance/risk-register.md` (R-37 entry)

- [ ] **Step 1:** Help page: rewrite the fortune "how the month pays" steps to the cascade (guarantee → capped levels with recomputed values → shared residual value for levels 7–8 → flat ₹30 at level 9 → leftover unspent; shortfall pro-rating). Same-change rule per the help-docs memory.
- [ ] **Step 2:** Skill banner: retitle to "KP's 2026-08-09 cascade" and replace the formula block with the normative algorithm from this plan's header (per-depth points 9→1, minimum ₹30, per-level caps, per-level floored values, shared residual value, absolute-level treatment in sparse months, shortfall pro-rating, `fortune_monthly_pool_levels` snapshot). Keep the compliance sentence about never using "luck or chance" in public copy.
- [ ] **Step 3:** R-37: note the 2026-08-09 algorithm change is a further plan amendment covered by the same DSA §6.2 notice requirement; flag remains OFF until published.
- [ ] **Step 4: Commit** — `docs(compensation): fortune cascade — help, skill, risk register` + compliance trailer.

---

### Task 9: Full verification

- [ ] **Step 1:** `vendor/bin/pint --dirty --format agent`.
- [ ] **Step 2:** Larastan: run the project's configured command (check `composer.json` scripts; typically `vendor/bin/phpstan analyse`) — level 7 must pass.
- [ ] **Step 3:** `php artisan test --compact --filter=Fortune` and then the wider compensation suite `php artisan test --compact tests/Modules/Compensation` — all green on the isolated test DB.
- [ ] **Step 4:** Commit any stragglers (`chore` / `test` prefixed).

---

### Task 10: Compliance review + handoff

- [ ] **Step 1:** Run the `compliance-officer` subagent over the full diff (mandatory: this touches money). Address Critical findings before anything else.
- [ ] **Step 2:** Confirm `FortuneBonusFeature` is still OFF everywhere (no seeder/config flips in the diff).
- [ ] **Step 3:** Summarize for the user: what changed, KP-example regression proof, the two confirmed decisions (absolute levels in sparse months; ₹30 pro-rating on shortfall), and that merge + any push wait for their say-so (solo-dev rule: never push without confirmation).

## Open items to flag to KP (do not block implementation)

1. Sparse-month treatment and shortfall pro-rating were decided by the product owner's proxy on 2026-08-09 (absolute levels; pro-rate) — worth a one-line confirmation from KP.
2. KP's doc says the matrix is "created based on the company's main tree" — the shipped (and retained) rule is FCFS by first GSB credit date, which matches his own "1st distributor to qualify is level 0" sequencing. Confirm no Genos-derived ordering is intended.
3. In a full-matrix month the caps leave a large structural leftover only at levels 7–8 flooring (₹3,78,870 in his own example) — confirm leftover stays with the company (consistent with every other pool).
