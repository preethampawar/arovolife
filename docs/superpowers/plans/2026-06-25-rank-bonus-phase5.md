# Rank Bonus (Phase 5) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the 9-rank monthly Rank Bonus engine (qualification checks, pool distribution, wallet credits, lifetime awards) with admin and distributor UI, behind feature flags.

**Architecture:** Three independent services — `RankQualificationService` (writes occurrence records), `RankBonusService` (reads occurrences, computes pool share, credits wallet), `AdminLifetimeAwardsController` (marks physical awards delivered). Feature flags `RankBonusFeature` and `LifetimeAwardsFeature` already exist. All money flows through the existing `WalletService::credit()` with a new `rank_credit` enum value. Four new tables: `rank_qualifications`, `rank_bonus_results`, `lifetime_award_milestones`, plus an enum migration on `wallet_ledger_entries`.

**Tech Stack:** Laravel 13, PHP 8.4, Pest v4, Tailwind v4, MySQL-guarded DDL, Pennant feature flags, existing `WalletService` and `GroupBvAccumulatorService` query patterns.

---

## File Map

**New migrations (app/database/migrations/ and app/app/Modules/Compensation/Database/Migrations/):**
- `app/database/migrations/2026_06_25_200001_add_rank_credit_to_wallet_ledger_entries.php` — add `rank_credit` to wallet_ledger_entries enum (MySQL-only ALTER)
- `app/app/Modules/Compensation/Database/Migrations/2026_06_25_200002_create_rank_qualifications_table.php`
- `app/app/Modules/Compensation/Database/Migrations/2026_06_25_200003_create_rank_bonus_results_table.php`
- `app/app/Modules/Compensation/Database/Migrations/2026_06_25_200004_create_lifetime_award_milestones_table.php`

**Also modify:**
- `app/app/Modules/Compensation/Database/Migrations/2026_06_24_100005_create_wallet_ledger_entries_table.php` — add `rank_credit` to the enum array so SQLite tests see it

**New models:**
- `app/app/Modules/Compensation/Models/RankQualification.php`
- `app/app/Modules/Compensation/Models/RankBonusResult.php`
- `app/app/Modules/Compensation/Models/LifetimeAwardMilestone.php`

**New services:**
- `app/app/Modules/Compensation/Services/RankQualificationService.php`
- `app/app/Modules/Compensation/Services/RankBonusService.php`

**New commands:**
- `app/app/Modules/Compensation/Console/Commands/RankCheckCommand.php`
- `app/app/Modules/Compensation/Console/Commands/RankBonusRunCommand.php`

**Modified:**
- `app/routes/console.php` — schedule `rank:monthly-run` on 8th
- `app/routes/web.php` — add admin rank-bonus routes, admin lifetime-awards routes, distributor rank-bonus route

**New controllers:**
- `app/app/Modules/Compensation/Http/Controllers/Admin/AdminRankBonusController.php`
- `app/app/Modules/Admin/Http/Controllers/AdminLifetimeAwardsController.php`

**Modified:**
- `app/app/Modules/Compensation/Http/Controllers/IncomeController.php` — add `rankBonus()` method

**New views:**
- `app/resources/views/admin/compensation/rank-bonus/index.blade.php`
- `app/resources/views/admin/compensation/rank-bonus/show.blade.php`
- `app/resources/views/admin/lifetime-awards/index.blade.php`
- `app/resources/views/income/rank-bonus.blade.php`

**Modified views:**
- `app/resources/views/income/_tabs.blade.php` — add Rank Bonus tab
- `app/resources/views/admin/compensation/overview.blade.php` — add quick links

**New tests:**
- `app/tests/Modules/Compensation/RankQualificationServiceTest.php`
- `app/tests/Modules/Compensation/RankBonusServiceTest.php`

---

## Task 1: Migrations — wallet enum + three new tables

**Files:**
- Create: `app/database/migrations/2026_06_25_200001_add_rank_credit_to_wallet_ledger_entries.php`
- Modify: `app/app/Modules/Compensation/Database/Migrations/2026_06_24_100005_create_wallet_ledger_entries_table.php`
- Create: `app/app/Modules/Compensation/Database/Migrations/2026_06_25_200002_create_rank_qualifications_table.php`
- Create: `app/app/Modules/Compensation/Database/Migrations/2026_06_25_200003_create_rank_bonus_results_table.php`
- Create: `app/app/Modules/Compensation/Database/Migrations/2026_06_25_200004_create_lifetime_award_milestones_table.php`

- [ ] **Step 1: Add `rank_credit` to the original wallet_ledger_entries migration (SQLite test compat)**

Edit `app/app/Modules/Compensation/Database/Migrations/2026_06_24_100005_create_wallet_ledger_entries_table.php`.
Find the `enum('type', [...])` call and add `'rank_credit'` to the array:

```php
$table->enum('type', [
    'gsb_credit',
    'mb_credit',
    'gbb_credit',
    'rank_credit',
    'payout_debit',
    'repurchase_deduction',
    'manual_credit',
    'reversal',
]);
```

- [ ] **Step 2: Create the MySQL ALTER migration for wallet_ledger_entries**

Create `app/database/migrations/2026_06_25_200001_add_rank_credit_to_wallet_ledger_entries.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite stores enums as unconstrained strings — MODIFY COLUMN is MySQL-only.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','rank_credit','payout_debit','repurchase_deduction','manual_credit','reversal') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE wallet_ledger_entries MODIFY COLUMN type ENUM('gsb_credit','mb_credit','gbb_credit','payout_debit','repurchase_deduction','manual_credit','reversal') NOT NULL");
        }
    }
};
```

- [ ] **Step 3: Create rank_qualifications migration**

Create `app/app/Modules/Compensation/Database/Migrations/2026_06_25_200002_create_rank_qualifications_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_qualifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->unsignedTinyInteger('rank_number');
            $table->date('month_start');
            $table->unsignedBigInteger('left_genos_bv_paise')->nullable();
            $table->unsignedBigInteger('right_genos_bv_paise')->nullable();
            $table->unsignedTinyInteger('occurrence_in_month')->default(1);
            $table->boolean('is_carry_forward')->default(false);
            $table->date('carry_forward_from_month')->nullable();
            $table->enum('status', ['qualified', 'voided'])->default('qualified');
            $table->timestamps();

            $table->unique(
                ['distributor_id', 'rank_number', 'month_start', 'occurrence_in_month'],
                'uq_rank_qual_dist_rank_month_occ',
            );
            $table->index(['distributor_id', 'month_start'], 'idx_rank_qual_dist_month');
            $table->index(['month_start', 'rank_number', 'status'], 'idx_rank_qual_month_rank_status');

            $table->foreign('distributor_id', 'fk_rank_qual_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_qualifications');
    }
};
```

- [ ] **Step 4: Create rank_bonus_results migration**

Create `app/app/Modules/Compensation/Database/Migrations/2026_06_25_200003_create_rank_bonus_results_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_bonus_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->date('month_start');
            $table->unsignedTinyInteger('rank_number');
            $table->unsignedBigInteger('company_turnover_paise');
            $table->unsignedBigInteger('pool_paise');
            $table->unsignedInteger('qualifier_count');
            $table->unsignedBigInteger('gross_paise');
            $table->unsignedBigInteger('admin_charge_paise');
            $table->unsignedBigInteger('tds_paise');
            $table->unsignedBigInteger('net_paise');
            $table->enum('status', ['pending', 'credited', 'reversed'])->default('pending');
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['distributor_id', 'rank_number', 'month_start'],
                'uq_rank_result_dist_rank_month',
            );
            $table->index(['month_start', 'rank_number'], 'idx_rank_result_month_rank');
            $table->index(['distributor_id', 'month_start'], 'idx_rank_result_dist_month');

            $table->foreign('distributor_id', 'fk_rank_result_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_bonus_results');
    }
};
```

- [ ] **Step 5: Create lifetime_award_milestones migration**

Create `app/app/Modules/Compensation/Database/Migrations/2026_06_25_200004_create_lifetime_award_milestones_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifetime_award_milestones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->unsignedTinyInteger('rank_number');
            $table->date('triggered_month');
            $table->string('award_description');
            $table->enum('status', ['pending', 'delivered', 'cancelled'])->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['distributor_id', 'rank_number'],
                'uq_lifetime_award_dist_rank',
            );
            $table->index('distributor_id', 'idx_lifetime_award_dist');
            $table->index('status', 'idx_lifetime_award_status');

            $table->foreign('distributor_id', 'fk_lifetime_award_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifetime_award_milestones');
    }
};
```

- [ ] **Step 6: Run migrations against test DB and verify**

```bash
docker exec -e DB_DATABASE=arovolife_test arovolife-app php artisan migrate --force
```

Expected: each migration runs without error.

- [ ] **Step 7: Commit**

```bash
git add app/database/migrations/2026_06_25_200001_add_rank_credit_to_wallet_ledger_entries.php \
        app/app/Modules/Compensation/Database/Migrations/2026_06_24_100005_create_wallet_ledger_entries_table.php \
        app/app/Modules/Compensation/Database/Migrations/2026_06_25_200002_create_rank_qualifications_table.php \
        app/app/Modules/Compensation/Database/Migrations/2026_06_25_200003_create_rank_bonus_results_table.php \
        app/app/Modules/Compensation/Database/Migrations/2026_06_25_200004_create_lifetime_award_milestones_table.php
git commit -m "feat(compensation): migrations for rank_qualifications, rank_bonus_results, lifetime_award_milestones + rank_credit wallet type"
```

---

## Task 2: Models

**Files:**
- Create: `app/app/Modules/Compensation/Models/RankQualification.php`
- Create: `app/app/Modules/Compensation/Models/RankBonusResult.php`
- Create: `app/app/Modules/Compensation/Models/LifetimeAwardMilestone.php`

- [ ] **Step 1: Create RankQualification model**

Create `app/app/Modules/Compensation/Models/RankQualification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $distributor_id
 * @property int $rank_number
 * @property string $month_start
 * @property int|null $left_genos_bv_paise
 * @property int|null $right_genos_bv_paise
 * @property int $occurrence_in_month
 * @property bool $is_carry_forward
 * @property string|null $carry_forward_from_month
 * @property string $status
 */
final class RankQualification extends Model
{
    public const string STATUS_QUALIFIED = 'qualified';
    public const string STATUS_VOIDED = 'voided';

    /** Human-readable rank names (1-indexed). */
    public const array RANK_NAMES = [
        1 => 'Silver Partner',
        2 => 'Pearl Partner',
        3 => 'Emerald Partner',
        4 => 'Gold Partner',
        5 => 'Diamond Partner',
        6 => 'Blue Diamond Partner',
        7 => 'Royal Diamond Partner',
        8 => 'Crown Diamond Partner',
        9 => 'Elite Diamond Partner',
    ];

    /** Pool percentage per rank (as float, e.g. 7.0 = 7%). */
    public const array POOL_PCT = [
        1 => 7.0,
        2 => 4.0,
        3 => 3.0,
        4 => 2.3,
        5 => 1.7,
        6 => 1.2,
        7 => 0.9,
        8 => 0.6,
        9 => 0.3,
    ];

    /**
     * Number of PYP occurrences required in a month to be paid.
     * Ranks 1-2: 1 occurrence (standard monthly qualification).
     * Ranks 3-5: 2 occurrences.
     * Ranks 6-9: 3 occurrences.
     */
    public const array PYP_REQUIRED = [
        1 => 1,
        2 => 1,
        3 => 2,
        4 => 2,
        5 => 2,
        6 => 3,
        7 => 3,
        8 => 3,
        9 => 3,
    ];

    /**
     * Minimum personal BV (paise) required for each rank.
     * Ranks 1: Dealer (500k). Rank 2: Wholesaler (1.5M). Ranks 3+: see spec.
     */
    public const array PERSONAL_BV_REQUIRED = [
        1 => 500_000,
        2 => 1_500_000,
        3 => 5_000_000,
        4 => 10_000_000,
        5 => 20_000_000,
        6 => 30_000_000,
        7 => 30_000_000,
        8 => 30_000_000,
        9 => 30_000_000,
    ];

    /** Monthly group BV thresholds (paise) for ranks 1 and 2 (left AND right must meet). */
    public const array GROUP_BV_REQUIRED = [
        1 => 30_000_000,  // 3L BV per side
        2 => 50_000_000,  // 5L BV per side
    ];

    protected $fillable = [
        'distributor_id',
        'rank_number',
        'month_start',
        'left_genos_bv_paise',
        'right_genos_bv_paise',
        'occurrence_in_month',
        'is_carry_forward',
        'carry_forward_from_month',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'rank_number' => 'int',
            'left_genos_bv_paise' => 'int',
            'right_genos_bv_paise' => 'int',
            'occurrence_in_month' => 'int',
            'is_carry_forward' => 'bool',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
```

- [ ] **Step 2: Create RankBonusResult model**

Create `app/app/Modules/Compensation/Models/RankBonusResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property string $month_start
 * @property int $rank_number
 * @property int $company_turnover_paise
 * @property int $pool_paise
 * @property int $qualifier_count
 * @property int $gross_paise
 * @property int $admin_charge_paise
 * @property int $tds_paise
 * @property int $net_paise
 * @property string $status
 * @property Carbon|null $credited_at
 */
final class RankBonusResult extends Model
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_CREDITED = 'credited';
    public const string STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'distributor_id',
        'month_start',
        'rank_number',
        'company_turnover_paise',
        'pool_paise',
        'qualifier_count',
        'gross_paise',
        'admin_charge_paise',
        'tds_paise',
        'net_paise',
        'status',
        'credited_at',
    ];

    protected function casts(): array
    {
        return [
            'rank_number' => 'int',
            'company_turnover_paise' => 'int',
            'pool_paise' => 'int',
            'qualifier_count' => 'int',
            'gross_paise' => 'int',
            'admin_charge_paise' => 'int',
            'tds_paise' => 'int',
            'net_paise' => 'int',
            'credited_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
```

- [ ] **Step 3: Create LifetimeAwardMilestone model**

Create `app/app/Modules/Compensation/Models/LifetimeAwardMilestone.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property int $rank_number
 * @property string $triggered_month
 * @property string $award_description
 * @property string $status
 * @property Carbon|null $delivered_at
 * @property string|null $notes
 */
final class LifetimeAwardMilestone extends Model
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_DELIVERED = 'delivered';
    public const string STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'distributor_id',
        'rank_number',
        'triggered_month',
        'award_description',
        'status',
        'delivered_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'rank_number' => 'int',
            'delivered_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }
}
```

- [ ] **Step 4: Run pint**

```bash
cd /path/to/project/app && vendor/bin/pint --dirty --format agent
```

- [ ] **Step 5: Commit**

```bash
git add app/app/Modules/Compensation/Models/RankQualification.php \
        app/app/Modules/Compensation/Models/RankBonusResult.php \
        app/app/Modules/Compensation/Models/LifetimeAwardMilestone.php
git commit -m "feat(compensation): RankQualification, RankBonusResult, LifetimeAwardMilestone models"
```

---

## Task 3: RankQualificationService

**Files:**
- Create: `app/app/Modules/Compensation/Services/RankQualificationService.php`

- [ ] **Step 1: Write the failing tests first** (see Task 7 — write tests before service)

Actually create the test file now (Task 7 Step 1 inline), then come back here.

Skip to Task 7, Steps 1-3 only, then return here.

- [ ] **Step 2: Create RankQualificationService**

Create `app/app/Modules/Compensation/Services/RankQualificationService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\RankQualification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Checks rank qualification for a given calendar month.
 *
 * Run once per month for occurrence 1 (standard qualification).
 * Run up to 2 more times in the same month for PYP (ranks 3-9).
 *
 * Cascade order: ranks 1-2 from raw BV, ranks 3-9 from prior rank qualifiers.
 * The 1+2 rule: qualifying at rank 1 or 2 creates carry-forward records
 * for M+1 and M+2. A rank-2 qualification voids any pending rank-1 carry-forwards.
 */
final class RankQualificationService
{
    /**
     * Run qualification checks for the given month and occurrence number.
     *
     * @return array{rank_1_count: int, rank_2_count: int, rank_3_count: int,
     *               rank_4_count: int, rank_5_count: int, rank_6_count: int,
     *               rank_7_count: int, rank_8_count: int, rank_9_count: int,
     *               total_qualifications: int}
     */
    public function checkForMonth(Carbon $month, int $occurrenceNumber = 1): array
    {
        $monthStart = $month->copy()->startOfMonth()->toDateString();
        $monthEnd = $month->copy()->endOfMonth()->toDateString();

        $counts = array_fill_keys(
            ['rank_1_count', 'rank_2_count', 'rank_3_count', 'rank_4_count',
             'rank_5_count', 'rank_6_count', 'rank_7_count', 'rank_8_count',
             'rank_9_count', 'total_qualifications'],
            0,
        );

        // Build personal BV map: distributor_id => lifetime_personal_bv_paise
        $personalBvMap = $this->buildPersonalBvMap();

        // Ranks 1-2: qualify on monthly group BV + personal BV title.
        $rank1Ids = $this->checkRanks1And2(
            rank: 1,
            monthStart: $monthStart,
            monthEnd: $monthEnd,
            occurrenceNumber: $occurrenceNumber,
            personalBvMap: $personalBvMap,
        );
        $counts['rank_1_count'] = count($rank1Ids);

        $rank2Ids = $this->checkRanks1And2(
            rank: 2,
            monthStart: $monthStart,
            monthEnd: $monthEnd,
            occurrenceNumber: $occurrenceNumber,
            personalBvMap: $personalBvMap,
        );
        $counts['rank_2_count'] = count($rank2Ids);

        // 1+2 rule: create carry-forwards for newly qualified rank-1 and rank-2.
        // Rank-2 voids any pending rank-1 carry-forwards for M+1 and M+2.
        if ($occurrenceNumber === 1) {
            $this->createCarryForwards($rank1Ids, rank: 1, sourceMonth: $monthStart);
            $this->createCarryForwards($rank2Ids, rank: 2, sourceMonth: $monthStart);
            $this->voidRank1CarryForwardsForRank2Qualifiers($rank2Ids, $monthStart);
        }

        // Ranks 3-9: cascade. Each rank needs qualifiers of the REQUIRED lower rank.
        // Rank 3 requires Pearl (rank 2) qualifiers; rank 4 requires Emerald (rank 3), etc.
        $previousRankQualifierIds = array_merge($rank1Ids, $rank2Ids);

        // For ranks 3-9, "lower rank" means one below. We need all qualifiers
        // at the prerequisite rank this month.
        $cascadeMap = [
            3 => 2, // Emerald requires Pearl (rank 2)
            4 => 3, // Gold requires Emerald (rank 3)
            5 => 4, // Diamond requires Gold (rank 4)
            6 => 5, // Blue Diamond requires Diamond (rank 5)
            7 => 6, // Royal Diamond requires Blue Diamond (rank 6)
            8 => 7, // Crown Diamond requires Royal Diamond (rank 7)
            9 => 8, // Elite Diamond requires Crown Diamond (rank 8)
        ];

        // Cache qualifier IDs per rank so higher ranks can look them up.
        $rankQualifierIds = [1 => $rank1Ids, 2 => $rank2Ids];

        foreach (range(3, 9) as $rank) {
            $requiredLowerRank = $cascadeMap[$rank];
            $lowerRankQualifierIds = $rankQualifierIds[$requiredLowerRank] ?? [];

            if (empty($lowerRankQualifierIds)) {
                $rankQualifierIds[$rank] = [];

                continue;
            }

            $newIds = $this->checkHigherRank(
                rank: $rank,
                lowerRankQualifierIds: $lowerRankQualifierIds,
                monthStart: $monthStart,
                occurrenceNumber: $occurrenceNumber,
                personalBvMap: $personalBvMap,
            );

            $rankQualifierIds[$rank] = $newIds;
            $counts['rank_'.$rank.'_count'] = count($newIds);
        }

        $counts['total_qualifications'] = array_sum(array_filter(
            $counts,
            fn (string $key) => str_ends_with($key, '_count') && $key !== 'total_qualifications',
            ARRAY_FILTER_USE_KEY,
        ));

        return $counts;
    }

    /**
     * Build lifetime personal BV map: distributor_id => sum(bv_paise) for type='accrual'.
     *
     * @return array<int, int>
     */
    private function buildPersonalBvMap(): array
    {
        $rows = DB::table('bv_ledger_entries')
            ->where('type', 'accrual')
            ->select('distributor_id', DB::raw('SUM(bv_paise) as total_bv'))
            ->groupBy('distributor_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->distributor_id] = (int) $row->total_bv;
        }

        return $map;
    }

    /**
     * Check ranks 1 and 2 (monthly group BV + personal BV title).
     * Creates/updates RankQualification records.
     *
     * @param  array<int, int>  $personalBvMap
     * @return int[] distributor IDs that newly qualified
     */
    private function checkRanks1And2(
        int $rank,
        string $monthStart,
        string $monthEnd,
        int $occurrenceNumber,
        array $personalBvMap,
    ): array {
        $personalBvRequired = RankQualification::PERSONAL_BV_REQUIRED[$rank];
        $groupBvRequired = RankQualification::GROUP_BV_REQUIRED[$rank];

        // Sum monthly group BV per distributor.
        $groupBvRows = DB::table('group_bv_daily')
            ->whereBetween('date', [$monthStart, $monthEnd])
            ->select(
                'distributor_id',
                DB::raw('SUM(left_bv_paise) as left_bv'),
                DB::raw('SUM(right_bv_paise) as right_bv'),
            )
            ->groupBy('distributor_id')
            ->get();

        $qualifiedIds = [];

        foreach ($groupBvRows as $row) {
            $distributorId = (int) $row->distributor_id;
            $leftBv = (int) $row->left_bv;
            $rightBv = (int) $row->right_bv;
            $personalBv = $personalBvMap[$distributorId] ?? 0;

            if ($personalBv < $personalBvRequired) {
                continue;
            }
            if ($leftBv < $groupBvRequired || $rightBv < $groupBvRequired) {
                continue;
            }

            // Write qualification record (idempotent via updateOrCreate).
            RankQualification::updateOrCreate(
                [
                    'distributor_id' => $distributorId,
                    'rank_number' => $rank,
                    'month_start' => $monthStart,
                    'occurrence_in_month' => $occurrenceNumber,
                ],
                [
                    'left_genos_bv_paise' => $leftBv,
                    'right_genos_bv_paise' => $rightBv,
                    'is_carry_forward' => false,
                    'status' => RankQualification::STATUS_QUALIFIED,
                ],
            );

            $qualifiedIds[] = $distributorId;
        }

        return $qualifiedIds;
    }

    /**
     * Check higher ranks (3-9) by counting prerequisite-rank qualifiers on each
     * side of the Genos tree for each candidate distributor.
     *
     * A candidate qualifies if they have >= 2 prerequisite-rank qualifiers
     * on their LEFT side AND >= 2 on their RIGHT side, plus sufficient personal BV.
     *
     * Uses the same side-detection query as GroupBvAccumulatorService.
     *
     * @param  int[]  $lowerRankQualifierIds  distributor IDs who hold the prerequisite rank
     * @param  array<int, int>  $personalBvMap
     * @return int[]  distributor IDs that qualified for $rank
     */
    private function checkHigherRank(
        int $rank,
        array $lowerRankQualifierIds,
        string $monthStart,
        int $occurrenceNumber,
        array $personalBvMap,
    ): array {
        if (empty($lowerRankQualifierIds)) {
            return [];
        }

        $personalBvRequired = RankQualification::PERSONAL_BV_REQUIRED[$rank];

        // For each lower-rank qualifier, find all their ancestors and which side
        // they are on. This mirrors GroupBvAccumulatorService::propagate().
        $rows = DB::table('genealogy_closure as gc_anc')
            ->join('genealogy_closure as gc_child', function ($join): void {
                $join->on('gc_child.descendant_id', '=', 'gc_anc.descendant_id')
                    ->whereRaw('gc_child.depth = gc_anc.depth - 1');
            })
            ->join('distributors as dc', function ($join): void {
                $join->on('dc.id', '=', 'gc_child.ancestor_id')
                    ->on('dc.placement_parent_id', '=', 'gc_anc.ancestor_id');
            })
            ->whereIn('gc_anc.descendant_id', $lowerRankQualifierIds)
            ->where('gc_anc.depth', '>', 0)
            ->whereIn('dc.placement_side', ['L', 'R'])
            ->select('gc_anc.ancestor_id', 'gc_anc.descendant_id', 'dc.placement_side as side')
            ->get();

        // Build map: ancestor_id => ['L' => count, 'R' => count]
        /** @var array<int, array{L: int, R: int}> $sideCountMap */
        $sideCountMap = [];
        foreach ($rows as $row) {
            $ancestorId = (int) $row->ancestor_id;
            $side = $row->side;
            $sideCountMap[$ancestorId] ??= ['L' => 0, 'R' => 0];
            $sideCountMap[$ancestorId][$side]++;
        }

        $qualifiedIds = [];

        foreach ($sideCountMap as $distributorId => $sides) {
            if ($sides['L'] < 2 || $sides['R'] < 2) {
                continue;
            }
            $personalBv = $personalBvMap[$distributorId] ?? 0;
            if ($personalBv < $personalBvRequired) {
                continue;
            }

            RankQualification::updateOrCreate(
                [
                    'distributor_id' => $distributorId,
                    'rank_number' => $rank,
                    'month_start' => $monthStart,
                    'occurrence_in_month' => $occurrenceNumber,
                ],
                [
                    'left_genos_bv_paise' => null,
                    'right_genos_bv_paise' => null,
                    'is_carry_forward' => false,
                    'status' => RankQualification::STATUS_QUALIFIED,
                ],
            );

            $qualifiedIds[] = $distributorId;
        }

        return $qualifiedIds;
    }

    /**
     * Create carry-forward qualification records for M+1 and M+2.
     * Idempotent: skips if a record already exists for that month.
     *
     * @param  int[]  $distributorIds
     */
    private function createCarryForwards(array $distributorIds, int $rank, string $sourceMonth): void
    {
        if (empty($distributorIds)) {
            return;
        }

        $source = Carbon::parse($sourceMonth);

        foreach ([1, 2] as $offset) {
            $targetMonth = $source->copy()->addMonths($offset)->startOfMonth()->toDateString();

            foreach ($distributorIds as $distributorId) {
                // Check if already exists (natural qualification may have been created).
                $alreadyExists = RankQualification::where('distributor_id', $distributorId)
                    ->where('rank_number', $rank)
                    ->where('month_start', $targetMonth)
                    ->where('is_carry_forward', true)
                    ->where('carry_forward_from_month', $sourceMonth)
                    ->exists();

                if ($alreadyExists) {
                    continue;
                }

                RankQualification::create([
                    'distributor_id' => $distributorId,
                    'rank_number' => $rank,
                    'month_start' => $targetMonth,
                    'occurrence_in_month' => 1,
                    'is_carry_forward' => true,
                    'carry_forward_from_month' => $sourceMonth,
                    'status' => RankQualification::STATUS_QUALIFIED,
                ]);
            }
        }
    }

    /**
     * When a distributor achieves rank 2, void any pending rank-1 carry-forwards
     * for M+1 and M+2 that originated from an earlier source month.
     *
     * @param  int[]  $rank2DistributorIds
     */
    private function voidRank1CarryForwardsForRank2Qualifiers(
        array $rank2DistributorIds,
        string $currentMonth,
    ): void {
        if (empty($rank2DistributorIds)) {
            return;
        }

        $source = Carbon::parse($currentMonth);
        $futureMontHs = [
            $source->copy()->addMonth()->startOfMonth()->toDateString(),
            $source->copy()->addMonths(2)->startOfMonth()->toDateString(),
        ];

        RankQualification::whereIn('distributor_id', $rank2DistributorIds)
            ->where('rank_number', 1)
            ->whereIn('month_start', $futureMontHs)
            ->where('is_carry_forward', true)
            ->where('status', RankQualification::STATUS_QUALIFIED)
            ->update(['status' => RankQualification::STATUS_VOIDED]);
    }
}
```

- [ ] **Step 3: Run pint**

```bash
cd /path/to/project/app && vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Commit**

```bash
git add app/app/Modules/Compensation/Services/RankQualificationService.php
git commit -m "feat(compensation): RankQualificationService — monthly rank check, 1+2 carry-forward, PYP occurrences"
```

---

## Task 4: RankBonusService

**Files:**
- Create: `app/app/Modules/Compensation/Services/RankBonusService.php`

- [ ] **Step 1: Create RankBonusService**

Create `app/app/Modules/Compensation/Services/RankBonusService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankQualification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly Rank Bonus engine.
 *
 * Pool per rank = company_turnover * pool_pct[rank] / 100.
 * Per-distributor gross = floor(pool / qualifier_count).
 * Admin charge = min(floor(gross * 0.03), 3_000_000).
 * TDS = round(gross * 0.05) — applied to gross, NOT to (gross - admin_charge).
 * Net = gross - admin_charge - tds.
 *
 * A lifetime award milestone is created the first time a distributor qualifies
 * for a given rank (across all months).
 */
final class RankBonusService
{
    private const float ADMIN_CHARGE_RATE = 0.03;
    private const int ADMIN_CHARGE_CAP_PAISE = 3_000_000; // ₹30,000
    private const float TDS_RATE = 0.05;

    public function __construct(private readonly WalletService $wallet) {}

    /**
     * Run the Rank Bonus for the given calendar month.
     * Idempotent: skips distributors already credited for that month+rank.
     *
     * @return array{
     *     turnover_paise: int,
     *     credited: int,
     *     by_rank: array<int, array{qualifiers: int, pool_paise: int, net_total: int}>
     * }
     */
    public function runForMonth(Carbon $month): array
    {
        $monthStart = $month->copy()->startOfMonth()->toDateString();
        $monthStartCarbon = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $turnoverPaise = $this->companyTurnoverPaise($monthStartCarbon, $monthEnd);

        $credited = 0;
        $byRank = [];

        DB::transaction(function () use (
            $monthStart, $turnoverPaise, &$credited, &$byRank,
        ): void {
            foreach (range(1, 9) as $rank) {
                $poolPct = RankQualification::POOL_PCT[$rank];
                $poolPaise = (int) round($turnoverPaise * $poolPct / 100);
                $pypRequired = RankQualification::PYP_REQUIRED[$rank];

                // Qualified distributors: enough occurrences OR valid carry-forwards.
                $qualifierIds = RankQualification::where('month_start', $monthStart)
                    ->where('rank_number', $rank)
                    ->where('status', RankQualification::STATUS_QUALIFIED)
                    ->where('occurrence_in_month', '>=', $pypRequired)
                    ->distinct()
                    ->pluck('distributor_id')
                    ->map(fn ($id) => (int) $id)
                    ->toArray();

                // Also include carry-forwards (occurrence_in_month = 1 with is_carry_forward = true).
                $carryForwardIds = RankQualification::where('month_start', $monthStart)
                    ->where('rank_number', $rank)
                    ->where('status', RankQualification::STATUS_QUALIFIED)
                    ->where('is_carry_forward', true)
                    ->distinct()
                    ->pluck('distributor_id')
                    ->map(fn ($id) => (int) $id)
                    ->toArray();

                $allQualifierIds = array_unique(array_merge($qualifierIds, $carryForwardIds));
                $qualifierCount = count($allQualifierIds);

                $byRank[$rank] = [
                    'qualifiers' => $qualifierCount,
                    'pool_paise' => $poolPaise,
                    'net_total' => 0,
                ];

                if ($qualifierCount === 0 || $poolPaise === 0) {
                    continue;
                }

                $grossPerDistributor = (int) floor($poolPaise / $qualifierCount);

                foreach ($allQualifierIds as $distributorId) {
                    // Idempotent: skip if already credited.
                    $alreadyCredited = RankBonusResult::where('distributor_id', $distributorId)
                        ->where('month_start', $monthStart)
                        ->where('rank_number', $rank)
                        ->where('status', RankBonusResult::STATUS_CREDITED)
                        ->exists();

                    if ($alreadyCredited) {
                        continue;
                    }

                    $adminCharge = min((int) floor($grossPerDistributor * self::ADMIN_CHARGE_RATE), self::ADMIN_CHARGE_CAP_PAISE);
                    $tds = (int) round($grossPerDistributor * self::TDS_RATE);
                    $net = $grossPerDistributor - $adminCharge - $tds;

                    $result = RankBonusResult::updateOrCreate(
                        [
                            'distributor_id' => $distributorId,
                            'month_start' => $monthStart,
                            'rank_number' => $rank,
                        ],
                        [
                            'company_turnover_paise' => $this->companyTurnoverPaise(
                                Carbon::parse($monthStart),
                                Carbon::parse($monthStart)->endOfMonth(),
                            ),
                            'pool_paise' => $poolPaise,
                            'qualifier_count' => $qualifierCount,
                            'gross_paise' => $grossPerDistributor,
                            'admin_charge_paise' => $adminCharge,
                            'tds_paise' => $tds,
                            'net_paise' => max(0, $net),
                            'status' => RankBonusResult::STATUS_PENDING,
                        ],
                    );

                    if ($net > 0) {
                        $rankName = RankQualification::RANK_NAMES[$rank];
                        $this->wallet->credit(
                            distributorId: $distributorId,
                            amountPaise: $net,
                            type: 'rank_credit',
                            referenceId: $result->id,
                            referenceType: 'rank_bonus_result',
                            memo: $rankName.' Bonus '.$monthStart,
                        );

                        $result->update([
                            'status' => RankBonusResult::STATUS_CREDITED,
                            'credited_at' => now(),
                        ]);

                        $byRank[$rank]['net_total'] += $net;
                        $credited++;
                    }

                    // Create lifetime award milestone (first time only — unique constraint).
                    $this->maybeCreateLifetimeAward($distributorId, $rank, $monthStart);
                }
            }
        });

        return [
            'turnover_paise' => $turnoverPaise,
            'credited' => $credited,
            'by_rank' => $byRank,
        ];
    }

    /**
     * Create a LifetimeAwardMilestone if this is the first time the distributor
     * has qualified for this rank. Silently ignores duplicate constraint violations.
     */
    private function maybeCreateLifetimeAward(
        int $distributorId,
        int $rank,
        string $monthStart,
    ): void {
        $alreadyExists = LifetimeAwardMilestone::where('distributor_id', $distributorId)
            ->where('rank_number', $rank)
            ->exists();

        if ($alreadyExists) {
            return;
        }

        $rankName = RankQualification::RANK_NAMES[$rank];

        LifetimeAwardMilestone::create([
            'distributor_id' => $distributorId,
            'rank_number' => $rank,
            'triggered_month' => $monthStart,
            'award_description' => $rankName.' — non-cash reward per plan',
            'status' => LifetimeAwardMilestone::STATUS_PENDING,
        ]);
    }

    /**
     * Sum of total_paise for all paid (non-cancelled, non-refunded) orders in the month.
     */
    private function companyTurnoverPaise(Carbon $monthStart, Carbon $monthEnd): int
    {
        return (int) Order::whereBetween('created_at', [$monthStart, $monthEnd->endOfDay()])
            ->whereNotIn('status', [
                Order::STATUS_DRAFT,
                Order::STATUS_PLACED,
                Order::STATUS_CANCELLED,
                Order::STATUS_REFUND_REQUESTED,
                Order::STATUS_REFUND_INSPECTION,
                Order::STATUS_REFUND_APPROVED,
                Order::STATUS_REFUNDED,
            ])
            ->sum('total_paise');
    }
}
```

- [ ] **Step 2: Run pint**

```bash
cd /path/to/project/app && vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Commit**

```bash
git add app/app/Modules/Compensation/Services/RankBonusService.php
git commit -m "feat(compensation): RankBonusService — monthly rank pool distribution, admin charge, TDS, wallet credit, lifetime awards"
```

---

## Task 5: Artisan Commands + Schedule

**Files:**
- Create: `app/app/Modules/Compensation/Console/Commands/RankCheckCommand.php`
- Create: `app/app/Modules/Compensation/Console/Commands/RankBonusRunCommand.php`
- Modify: `app/routes/console.php`

- [ ] **Step 1: Create RankCheckCommand**

Create `app/app/Modules/Compensation/Console/Commands/RankCheckCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\RankQualificationService;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class RankCheckCommand extends Command
{
    protected $signature = 'rank:check-qualifications
                            {--month= : Month to check (YYYY-MM, defaults to current month)}
                            {--occurrence=1 : PYP occurrence number (1-3)}';

    protected $description = 'Check and record rank qualifications for a calendar month (PYP-aware)';

    public function __construct(private readonly RankQualificationService $rankQual)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Feature::for(null)->active(RankBonusFeature::class)) {
            $this->warn('Rank Bonus feature flag is OFF — skipping qualification check.');

            return self::SUCCESS;
        }

        $month = $this->option('month')
            ? Carbon::parse((string) $this->option('month').'-01')
            : Carbon::today()->startOfMonth();

        $occurrence = (int) ($this->option('occurrence') ?? 1);

        $this->info("Rank Qualification Check — {$month->format('F Y')} (occurrence #{$occurrence})");

        $result = $this->rankQual->checkForMonth($month, $occurrence);

        $rows = [];
        foreach (range(1, 9) as $rank) {
            $key = 'rank_'.$rank.'_count';
            $rows[] = ['Rank '.$rank, $result[$key]];
        }
        $rows[] = ['Total', $result['total_qualifications']];

        $this->table(['Rank', 'Qualifiers'], $rows);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Create RankBonusRunCommand**

Create `app/app/Modules/Compensation/Console/Commands/RankBonusRunCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\RankBonusService;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class RankBonusRunCommand extends Command
{
    protected $signature = 'rank:monthly-run
                            {--month= : Month to run (YYYY-MM, defaults to previous month)}';

    protected $description = 'Calculate and credit the Rank Bonus for a calendar month (runs on 8th)';

    public function __construct(private readonly RankBonusService $rankBonus)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Feature::for(null)->active(RankBonusFeature::class)) {
            $this->warn('Rank Bonus feature flag is OFF — skipping run.');

            return self::SUCCESS;
        }

        $month = $this->option('month')
            ? Carbon::parse((string) $this->option('month').'-01')
            : Carbon::today()->subMonth()->startOfMonth();

        $this->info("Rank Bonus — {$month->format('F Y')}");

        $result = $this->rankBonus->runForMonth($month);

        $this->line('Company turnover: ₹'.number_format($result['turnover_paise'] / 100, 2));
        $this->line('Distributors credited: '.$result['credited']);
        $this->newLine();

        $rows = [];
        foreach ($result['by_rank'] as $rank => $data) {
            $rankName = RankQualification::RANK_NAMES[$rank];
            $rows[] = [
                $rank,
                $rankName,
                $data['qualifiers'],
                '₹'.number_format($data['pool_paise'] / 100, 2),
                '₹'.number_format($data['net_total'] / 100, 2),
            ];
        }

        $this->table(['Rank', 'Name', 'Qualifiers', 'Pool', 'Net Credited'], $rows);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 3: Add schedule entry to routes/console.php**

Open `app/routes/console.php` and add at the bottom:

```php
use App\Modules\Compensation\Console\Commands\RankBonusRunCommand;

// Rank Bonus runs on the 8th of each month at 08:00 IST.
Schedule::command(RankBonusRunCommand::class)
    ->monthlyOn(8, '08:00')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();
```

- [ ] **Step 4: Run pint**

```bash
cd /path/to/project/app && vendor/bin/pint --dirty --format agent
```

- [ ] **Step 5: Verify commands are registered**

```bash
docker exec arovolife-app php artisan list | grep rank
```

Expected output:
```
rank:check-qualifications
rank:monthly-run
```

- [ ] **Step 6: Commit**

```bash
git add app/app/Modules/Compensation/Console/Commands/RankCheckCommand.php \
        app/app/Modules/Compensation/Console/Commands/RankBonusRunCommand.php \
        app/routes/console.php
git commit -m "feat(compensation): rank:check-qualifications and rank:monthly-run commands + schedule on 8th"
```

---

## Task 6: Admin Controllers + Distributor Controller Method

**Files:**
- Create: `app/app/Modules/Compensation/Http/Controllers/Admin/AdminRankBonusController.php`
- Create: `app/app/Modules/Admin/Http/Controllers/AdminLifetimeAwardsController.php`
- Modify: `app/app/Modules/Compensation/Http/Controllers/IncomeController.php`

- [ ] **Step 1: Create AdminRankBonusController**

Create `app/app/Modules/Compensation/Http/Controllers/Admin/AdminRankBonusController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers\Admin;

use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;

final class AdminRankBonusController extends Controller
{
    public function index(): View
    {
        abort_unless(Feature::for(null)->active(RankBonusFeature::class), 404);

        $months = RankBonusResult::query()
            ->selectRaw('
                month_start,
                COUNT(DISTINCT distributor_id) as qualifier_count,
                SUM(net_paise) as total_net_paise,
                MAX(credited_at) as credited_at
            ')
            ->where('status', RankBonusResult::STATUS_CREDITED)
            ->groupBy('month_start')
            ->orderByDesc('month_start')
            ->get();

        return view('admin.compensation.rank-bonus.index', compact('months'));
    }

    public function show(string $month): View
    {
        abort_unless(Feature::for(null)->active(RankBonusFeature::class), 404);

        $date = Carbon::parse($month.'-01');

        // Per-rank summary for the month.
        $rankSummaries = RankBonusResult::query()
            ->selectRaw('
                rank_number,
                COUNT(*) as qualifier_count,
                MAX(pool_paise) as pool_paise,
                SUM(gross_paise) as total_gross_paise,
                SUM(admin_charge_paise) as total_admin_paise,
                SUM(tds_paise) as total_tds_paise,
                SUM(net_paise) as total_net_paise
            ')
            ->where('month_start', $date->toDateString())
            ->groupBy('rank_number')
            ->orderBy('rank_number')
            ->get()
            ->keyBy('rank_number');

        $rows = RankBonusResult::with('distributor')
            ->where('month_start', $date->toDateString())
            ->orderBy('rank_number')
            ->orderByDesc('gross_paise')
            ->paginate(50)
            ->withQueryString();

        $rankNames = RankQualification::RANK_NAMES;

        return view('admin.compensation.rank-bonus.show', compact('rows', 'rankSummaries', 'date', 'rankNames'));
    }
}
```

- [ ] **Step 2: Create AdminLifetimeAwardsController**

Create `app/app/Modules/Admin/Http/Controllers/AdminLifetimeAwardsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Pennant\Feature;

final class AdminLifetimeAwardsController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(Feature::for(null)->active(LifetimeAwardsFeature::class), 404);

        $milestones = LifetimeAwardMilestone::with('distributor')
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->input('status')),
            )
            ->orderByDesc('triggered_month')
            ->orderBy('rank_number')
            ->paginate(50)
            ->withQueryString();

        $rankNames = RankQualification::RANK_NAMES;

        return view('admin.lifetime-awards.index', compact('milestones', 'rankNames'));
    }

    public function markDelivered(int $id, Request $request): RedirectResponse
    {
        abort_unless(Feature::for(null)->active(LifetimeAwardsFeature::class), 404);

        $milestone = LifetimeAwardMilestone::findOrFail($id);

        abort_if($milestone->status === LifetimeAwardMilestone::STATUS_DELIVERED, 409);

        $milestone->update([
            'status' => LifetimeAwardMilestone::STATUS_DELIVERED,
            'delivered_at' => now(),
            'notes' => $request->input('notes'),
        ]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'admin.lifetime_award.delivered',
            'subject_type' => 'lifetime_award_milestone',
            'subject_id' => $milestone->id,
            'details' => [
                'distributor_id' => $milestone->distributor_id,
                'rank_number' => $milestone->rank_number,
                'award_description' => $milestone->award_description,
            ],
            'ip' => $request->ip(),
        ]);

        return redirect()
            ->route('admin.lifetime-awards.index')
            ->with('success', 'Lifetime award marked as delivered.');
    }
}
```

- [ ] **Step 3: Add rankBonus() method to IncomeController**

Open `app/app/Modules/Compensation/Http/Controllers/IncomeController.php`.

Add these imports at the top (after the existing use statements):

```php
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Shared\Features\RankBonusFeature;
```

Add this method after `growthBooster()`:

```php
public function rankBonus(Request $request): View
{
    abort_unless(Feature::for(null)->active(RankBonusFeature::class), 404);

    $distributor = $request->user()?->distributor;
    abort_unless($distributor !== null, 403);

    try {
        $rows = RankBonusResult::where('distributor_id', $distributor->id)
            ->where('status', RankBonusResult::STATUS_CREDITED)
            ->when($request->filled('from'), fn ($q) => $q->where('month_start', '>=', $request->input('from').'-01'))
            ->when($request->filled('to'), fn ($q) => $q->where('month_start', '<=', $request->input('to').'-01'))
            ->orderByDesc('month_start')
            ->orderBy('rank_number')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $totalNet = $rows->getCollection()->sum('net_paise');
    } catch (\Illuminate\Database\QueryException) {
        $rows = collect();
        $totalNet = 0;
    }

    return view('income.rank-bonus', compact('distributor', 'rows', 'totalNet'));
}
```

- [ ] **Step 4: Run pint**

```bash
cd /path/to/project/app && vendor/bin/pint --dirty --format agent
```

- [ ] **Step 5: Commit**

```bash
git add app/app/Modules/Compensation/Http/Controllers/Admin/AdminRankBonusController.php \
        app/app/Modules/Admin/Http/Controllers/AdminLifetimeAwardsController.php \
        app/app/Modules/Compensation/Http/Controllers/IncomeController.php
git commit -m "feat(compensation): AdminRankBonusController, AdminLifetimeAwardsController, IncomeController::rankBonus()"
```

---

## Task 7: Routes

**Files:**
- Modify: `app/routes/web.php`

- [ ] **Step 1: Add imports at the top of web.php**

Open `app/routes/web.php`. Add these `use` statements in the existing import block:

```php
use App\Modules\Admin\Http\Controllers\AdminLifetimeAwardsController;
use App\Modules\Compensation\Http\Controllers\Admin\AdminRankBonusController;
```

- [ ] **Step 2: Add admin rank-bonus + lifetime-awards routes**

Inside the `Route::prefix('compensation')->name('compensation.')->group(...)` block (after the `gbb` prefix group, around line 303), add:

```php
Route::prefix('rank-bonus')->name('rank-bonus.')->group(function (): void {
    Route::get('/', [AdminRankBonusController::class, 'index'])->name('index');
    Route::get('/{month}', [AdminRankBonusController::class, 'show'])->name('show')->where('month', '\d{4}-\d{2}');
});
```

Still inside the admin middleware group (but outside the `compensation` prefix), add the lifetime-awards routes:

```php
Route::prefix('lifetime-awards')->name('lifetime-awards.')->group(function (): void {
    Route::get('/', [AdminLifetimeAwardsController::class, 'index'])->name('index');
    Route::post('/{milestone}/deliver', [AdminLifetimeAwardsController::class, 'markDelivered'])->name('deliver')->whereNumber('milestone');
});
```

- [ ] **Step 3: Add distributor rank-bonus route**

Inside the authenticated distributor route group (near the other `income.*` routes around line 502), add:

```php
Route::get('/income/rank-bonus', [IncomeController::class, 'rankBonus'])->name('income.rank-bonus');
```

- [ ] **Step 4: Verify routes**

```bash
docker exec arovolife-app php artisan route:list --name=rank-bonus
docker exec arovolife-app php artisan route:list --name=lifetime-awards
```

Expected: both groups of routes appear without errors.

- [ ] **Step 5: Commit**

```bash
git add app/routes/web.php
git commit -m "feat(compensation): add rank-bonus and lifetime-awards routes (admin + distributor)"
```

---

## Task 8: Views

**Files:**
- Create: `app/resources/views/admin/compensation/rank-bonus/index.blade.php`
- Create: `app/resources/views/admin/compensation/rank-bonus/show.blade.php`
- Create: `app/resources/views/admin/lifetime-awards/index.blade.php`
- Create: `app/resources/views/income/rank-bonus.blade.php`
- Modify: `app/resources/views/income/_tabs.blade.php`
- Modify: `app/resources/views/admin/compensation/overview.blade.php`

- [ ] **Step 1: Create admin rank-bonus index view**

Create directory: `app/resources/views/admin/compensation/rank-bonus/`

Create `app/resources/views/admin/compensation/rank-bonus/index.blade.php`:

```blade
@extends('admin.layouts.admin')
@section('title', 'Rank Bonus')
@section('heading', 'Rank Bonus')

@section('content')

<div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
    The Rank Bonus is distributed monthly from 9 separate pools (one per rank). Each pool is a fixed percentage of company monthly turnover. Qualified distributors share their rank's pool equally. Runs automatically on the 8th of each month via <code class="font-mono bg-blue-100 px-1 rounded">php artisan rank:monthly-run</code>.
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($months->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-400 text-center">No Rank Bonus batches yet — engine has not yet run.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-500">Month</th>
                    <th class="px-4 py-2 text-right text-gray-500">Distributors credited</th>
                    <th class="px-4 py-2 text-right text-gray-500">Net credited</th>
                    <th class="px-4 py-2 text-right text-gray-500">Credited at</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($months as $m)
                <tr>
                    <td class="px-4 py-2 font-medium">{{ \Illuminate\Support\Carbon::parse($m->month_start)->format('F Y') }}</td>
                    <td class="px-4 py-2 text-right">{{ number_format($m->qualifier_count) }}</td>
                    <td class="px-4 py-2 text-right font-semibold text-green-700">₹{{ number_format($m->total_net_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right text-gray-500">
                        {{ $m->credited_at ? \Illuminate\Support\Carbon::parse($m->credited_at)->format('d M Y H:i') : '—' }}
                    </td>
                    <td class="px-4 py-2">
                        <a href="{{ route('admin.compensation.rank-bonus.show', \Illuminate\Support\Carbon::parse($m->month_start)->format('Y-m')) }}"
                           class="text-brand-600 text-xs hover:underline">View →</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

@endsection
```

- [ ] **Step 2: Create admin rank-bonus show view**

Create `app/resources/views/admin/compensation/rank-bonus/show.blade.php`:

```blade
@extends('admin.layouts.admin')
@section('title', 'Rank Bonus — '.$date->format('F Y'))
@section('heading', 'Rank Bonus — '.$date->format('F Y'))

@section('content')

{{-- Per-rank summary cards --}}
@if($rankSummaries->isNotEmpty())
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-6">
    @foreach($rankSummaries as $rankNum => $summary)
    <div class="bg-white rounded-xl border border-gray-200 p-3 text-center">
        <p class="text-[10px] text-gray-500 mb-1 font-medium uppercase tracking-wide">{{ $rankNames[$rankNum] ?? 'Rank '.$rankNum }}</p>
        <p class="text-sm font-bold text-indigo-700">₹{{ number_format($summary->pool_paise / 100, 0) }}</p>
        <p class="text-[10px] text-gray-400">pool · {{ $summary->qualifier_count }} qualifiers</p>
        <p class="text-xs font-semibold text-green-700 mt-1">₹{{ number_format($summary->total_net_paise / 100, 0) }} net</p>
    </div>
    @endforeach
</div>
@endif

{{-- Per-distributor table --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($rows->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-400 text-center">No Rank Bonus results for this month.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-500">ADN</th>
                    <th class="px-4 py-2 text-left text-gray-500">Rank</th>
                    <th class="px-4 py-2 text-right text-gray-500">Gross</th>
                    <th class="px-4 py-2 text-right text-gray-500">
                        Admin <x-help-tip text="min(3% of gross, ₹30,000)" />
                    </th>
                    <th class="px-4 py-2 text-right text-gray-500">TDS (5%)</th>
                    <th class="px-4 py-2 text-right text-gray-500">Net</th>
                    <th class="px-4 py-2 text-center text-gray-500">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($rows as $row)
                @php
                $sc = ['credited' => 'bg-green-100 text-green-700', 'reversed' => 'bg-red-100 text-red-700', 'pending' => 'bg-gray-100 text-gray-600'];
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2">
                        <a href="{{ route('admin.compensation.distributors.show', $row->distributor_id) }}"
                           class="text-brand-600 hover:underline font-mono">{{ $row->distributor?->adn ?? '—' }}</a>
                    </td>
                    <td class="px-4 py-2">
                        <span class="inline-flex px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-medium">
                            {{ $rankNames[$row->rank_number] ?? 'Rank '.$row->rank_number }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-right font-mono">₹{{ number_format($row->gross_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right font-mono text-gray-500">₹{{ number_format($row->admin_charge_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right font-mono text-gray-500">₹{{ number_format($row->tds_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-right font-mono font-semibold text-green-700">₹{{ number_format($row->net_paise / 100, 2) }}</td>
                    <td class="px-4 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $sc[$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ ucfirst($row->status) }}
                        </span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $rows->links() }}</div>
    @endif
</div>

@endsection
```

- [ ] **Step 3: Create admin lifetime-awards index view**

Create directory: `app/resources/views/admin/lifetime-awards/`

Create `app/resources/views/admin/lifetime-awards/index.blade.php`:

```blade
@extends('admin.layouts.admin')
@section('title', 'Lifetime Awards')
@section('heading', 'Lifetime Awards & Milestones')

@section('content')

<div class="mb-6 rounded-lg border border-purple-200 bg-purple-50 p-4 text-sm text-purple-800">
    Lifetime awards are non-cash rewards issued the first time a distributor achieves a given rank. Mark them as delivered once the physical award has been dispatched.
</div>

{{-- Filter --}}
<form method="GET" class="flex gap-3 mb-6 items-end">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
            <option value="">All</option>
            <option value="pending" @selected(request('status') === 'pending')>Pending</option>
            <option value="delivered" @selected(request('status') === 'delivered')>Delivered</option>
            <option value="cancelled" @selected(request('status') === 'cancelled')>Cancelled</option>
        </select>
    </div>
    <button type="submit" class="px-4 py-1.5 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600">Filter</button>
    @if(request('status'))
        <a href="{{ route('admin.lifetime-awards.index') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
    @endif
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    @if($milestones->isEmpty())
        <p class="px-6 py-10 text-sm text-gray-400 text-center">No lifetime award milestones yet.</p>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-gray-500">ADN</th>
                    <th class="px-4 py-2 text-left text-gray-500">Rank</th>
                    <th class="px-4 py-2 text-left text-gray-500">Triggered</th>
                    <th class="px-4 py-2 text-left text-gray-500">Award</th>
                    <th class="px-4 py-2 text-center text-gray-500">Status</th>
                    <th class="px-4 py-2 text-left text-gray-500">Delivered at</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($milestones as $milestone)
                @php
                $sc = ['pending' => 'bg-amber-100 text-amber-700', 'delivered' => 'bg-green-100 text-green-700', 'cancelled' => 'bg-red-100 text-red-700'];
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 font-mono">{{ $milestone->distributor?->adn ?? '—' }}</td>
                    <td class="px-4 py-2">
                        <span class="inline-flex px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-medium">
                            {{ $rankNames[$milestone->rank_number] ?? 'Rank '.$milestone->rank_number }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-gray-600">{{ \Illuminate\Support\Carbon::parse($milestone->triggered_month)->format('M Y') }}</td>
                    <td class="px-4 py-2 text-gray-700">{{ $milestone->award_description }}</td>
                    <td class="px-4 py-2 text-center">
                        <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $sc[$milestone->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ ucfirst($milestone->status) }}
                        </span>
                    </td>
                    <td class="px-4 py-2 text-gray-500">
                        {{ $milestone->delivered_at ? $milestone->delivered_at->format('d M Y') : '—' }}
                    </td>
                    <td class="px-4 py-2">
                        @if($milestone->status === 'pending')
                        <form method="POST" action="{{ route('admin.lifetime-awards.deliver', $milestone->id) }}"
                              onsubmit="return confirm('Mark this lifetime award as delivered?')">
                            @csrf
                            <button type="submit"
                                    class="px-2 py-1 rounded bg-green-100 text-green-700 hover:bg-green-200 text-[10px] font-medium">
                                Mark Delivered
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-gray-100">{{ $milestones->links() }}</div>
    @endif
</div>

@endsection
```

- [ ] **Step 4: Create distributor income/rank-bonus view**

Create `app/resources/views/income/rank-bonus.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'My Income — Rank Bonus')

@section('content')
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-2">My Income</h1>

    @include('income._tabs')

    {{-- Page note --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 text-sm text-blue-800 mb-6">
        The Rank Bonus is your share of a monthly pool set aside for your qualifying rank. Each rank has its own pool (a fixed % of company turnover). Your share is pool ÷ number of qualifiers. Admin charge (min of 3%, max ₹30,000) and 5% TDS are deducted. Credited on the 8th of the following month.
    </div>

    {{-- Summary card --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">Net Rank Bonus earned (page)</p>
            <p class="text-2xl font-bold text-gray-900">
                {{ $rows->isEmpty() ? '—' : '₹'.number_format($totalNet / 100, 0) }}
            </p>
        </div>
        <div class="bg-white rounded-2xl border border-gray-200 p-5 text-center">
            <p class="text-xs text-gray-500 mb-1">Months credited</p>
            <p class="text-2xl font-bold text-gray-900">
                {{ $rows instanceof \Illuminate\Pagination\LengthAwarePaginator ? number_format($rows->total()) : count($rows) }}
            </p>
        </div>
    </div>

    {{-- Filter --}}
    <form method="GET" class="flex flex-wrap gap-3 mb-6 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">From (YYYY-MM)</label>
            <input type="month" name="from" value="{{ request('from') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To (YYYY-MM)</label>
            <input type="month" name="to" value="{{ request('to') }}" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
        </div>
        <button type="submit" class="px-4 py-1.5 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600 transition-colors">Filter</button>
        @if(request('from') || request('to'))
            <a href="{{ route('income.rank-bonus') }}" class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-800">Clear</a>
        @endif
    </form>

    @if($rows->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center">
            <p class="text-gray-500 font-medium">No Rank Bonus yet.</p>
            <p class="text-sm text-gray-400 mt-1">Your Rank Bonus will appear here once you qualify for a rank in a calendar month.</p>
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm min-w-[600px]">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Month</th>
                        <th class="text-left px-4 py-3 font-semibold text-gray-600">Rank</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Gross</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">
                            <span class="flex items-center justify-end gap-1">
                                Admin <x-help-tip text="min(3% of gross, ₹30,000)" />
                            </span>
                        </th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">TDS (5%)</th>
                        <th class="text-right px-4 py-3 font-semibold text-gray-600">Net</th>
                        <th class="text-center px-4 py-3 font-semibold text-gray-600">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($rows as $row)
                    @php
                    $rankNames = \App\Modules\Compensation\Models\RankQualification::RANK_NAMES;
                    $sc = ['credited' => 'bg-green-100 text-green-700', 'reversed' => 'bg-red-100 text-red-700', 'pending' => 'bg-gray-100 text-gray-600'];
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800">
                            {{ \Illuminate\Support\Carbon::parse($row->month_start)->format('F Y') }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700">
                                {{ $rankNames[$row->rank_number] ?? 'Rank '.$row->rank_number }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono">₹{{ number_format($row->gross_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-500">₹{{ number_format($row->admin_charge_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono text-gray-500">₹{{ number_format($row->tds_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold text-green-700">₹{{ number_format($row->net_paise / 100, 2) }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $sc[$row->status] ?? 'bg-gray-100 text-gray-600' }}">
                                {{ ucfirst($row->status) }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if(method_exists($rows, 'links'))
            <div class="mt-4">{{ $rows->links() }}</div>
        @endif
    @endif
</div>
@endsection
```

- [ ] **Step 5: Update income/_tabs.blade.php**

Open `app/resources/views/income/_tabs.blade.php`. Replace the `@php` block:

```blade
@php
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Laravel\Pennant\Feature;

$tabs = [
    ['route' => 'income.dashboard',   'label' => 'Dashboard', 'visible' => true],
    ['route' => 'income.genos-bv',    'label' => 'Genos BV',  'visible' => true],
    ['route' => 'income.gsb-history', 'label' => 'GSB History', 'visible' => true],
    ['route' => 'income.mentorship',  'label' => 'Mentorship', 'visible' => Feature::for(null)->active(MentorshipBonusFeature::class)],
    ['route' => 'income.growth-booster', 'label' => 'Growth Booster', 'visible' => Feature::for(null)->active(GrowthBoosterBonusFeature::class)],
    ['route' => 'income.rank-bonus',  'label' => 'Rank Bonus', 'visible' => Feature::for(null)->active(RankBonusFeature::class)],
    ['route' => 'income.wallet',         'label' => 'Wallet & Payouts', 'visible' => true],
];
@endphp
```

- [ ] **Step 6: Update admin/compensation/overview.blade.php**

Open `app/resources/views/admin/compensation/overview.blade.php`. Find the `@php` block for quick-links (around line 70) and add the two new imports and links:

```blade
@php
    use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
    use App\Modules\Shared\Features\LifetimeAwardsFeature;
    use App\Modules\Shared\Features\RankBonusFeature;
    use Laravel\Pennant\Feature;
@endphp
```

In the quick-links flex div, after the GBB link:

```blade
@if(Feature::for(null)->active(RankBonusFeature::class))
<a href="{{ route('admin.compensation.rank-bonus.index') }}" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white text-xs text-gray-700 hover:bg-gray-50">Rank Bonus →</a>
@endif
@if(Feature::for(null)->active(LifetimeAwardsFeature::class))
<a href="{{ route('admin.lifetime-awards.index') }}" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white text-xs text-gray-700 hover:bg-gray-50">Lifetime Awards →</a>
@endif
```

- [ ] **Step 7: Commit**

```bash
git add app/resources/views/admin/compensation/rank-bonus/ \
        app/resources/views/admin/lifetime-awards/ \
        app/resources/views/income/rank-bonus.blade.php \
        app/resources/views/income/_tabs.blade.php \
        app/resources/views/admin/compensation/overview.blade.php
git commit -m "feat(compensation): rank-bonus and lifetime-awards views, income tabs, admin overview quick links"
```

---

## Task 9: Tests

**Files:**
- Create: `app/tests/Modules/Compensation/RankQualificationServiceTest.php`
- Create: `app/tests/Modules/Compensation/RankBonusServiceTest.php`

- [ ] **Step 1: Create RankQualificationServiceTest**

Create `app/tests/Modules/Compensation/RankQualificationServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Services\RankQualificationService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/**
 * Seed a bv_ledger_entries accrual row for a distributor.
 */
function seedPersonalBv(int $distributorId, int $bvPaise): void
{
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $distributorId,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

/**
 * Seed group_bv_daily for a distributor on a given date.
 */
function seedGroupBv(int $distributorId, string $date, int $leftBv, int $rightBv): void
{
    DB::table('group_bv_daily')->insert([
        'distributor_id' => $distributorId,
        'date' => $date,
        'left_bv_paise' => $leftBv,
        'right_bv_paise' => $rightBv,
    ]);
}

/**
 * Seed genealogy_closure entries so $childId appears as a descendant of $ancestorId
 * on the given $side.
 */
function seedGenealogyAndSide(int $ancestorId, int $childId, string $side, int $depth = 1): void
{
    // Insert the closure row.
    DB::table('genealogy_closure')->insertOrIgnore([
        'ancestor_id' => $ancestorId,
        'descendant_id' => $childId,
        'depth' => $depth,
    ]);
    // Self-reference for the child.
    DB::table('genealogy_closure')->insertOrIgnore([
        'ancestor_id' => $childId,
        'descendant_id' => $childId,
        'depth' => 0,
    ]);
    // The side-detection query needs a gc_child row where depth = ancestor_depth - 1 = 0,
    // and the distributor record must have placement_parent_id = ancestorId and placement_side = side.
    DB::table('distributors')->where('id', $childId)->update([
        'placement_parent_id' => $ancestorId,
        'placement_side' => $side,
    ]);
    // gc_child: gc_child.descendant_id = childId AND gc_child.depth = 0 (self-row).
    // gc_child.ancestor_id = childId → dc.id = childId → dc.placement_parent_id = ancestorId.
    // This matches gc_anc.ancestor_id = ancestorId. Correct.
}

it('returns zero qualifications when no group BV data exists', function (): void {
    $month = Carbon::parse('2026-06-01');

    $svc = app(RankQualificationService::class);
    $result = $svc->checkForMonth($month);

    expect($result['total_qualifications'])->toBe(0);
    expect($result['rank_1_count'])->toBe(0);
});

it('qualifies a distributor with sufficient monthly group BV and Dealer personal BV for rank 1 (Silver)', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // Personal BV >= 500,000 paise (Dealer threshold).
    seedPersonalBv($dist->id, 600_000);

    // Monthly group BV >= 30,000,000 per side (Rank 1 threshold).
    seedGroupBv($dist->id, '2026-06-10', 31_000_000, 31_000_000);

    $svc = app(RankQualificationService::class);
    $result = $svc->checkForMonth($month, occurrenceNumber: 1);

    expect($result['rank_1_count'])->toBe(1);
    expect($result['total_qualifications'])->toBeGreaterThanOrEqual(1);

    $record = RankQualification::where('distributor_id', $dist->id)
        ->where('rank_number', 1)
        ->first();

    expect($record)->not->toBeNull();
    expect($record->status)->toBe(RankQualification::STATUS_QUALIFIED);
    expect($record->occurrence_in_month)->toBe(1);
    expect($record->is_carry_forward)->toBeFalse();
});

it('does not qualify a distributor whose personal BV is below rank-1 minimum', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    // Personal BV = 400,000 — below Dealer (500,000).
    seedPersonalBv($dist->id, 400_000);

    // Group BV is sufficient.
    seedGroupBv($dist->id, '2026-06-10', 31_000_000, 31_000_000);

    $svc = app(RankQualificationService::class);
    $result = $svc->checkForMonth($month);

    expect($result['rank_1_count'])->toBe(0);
    expect(RankQualification::count())->toBe(0);
});

it('does not qualify for rank 1 when only one side meets the group BV threshold', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBv($dist->id, 600_000);
    // Left side meets, right side does not.
    seedGroupBv($dist->id, '2026-06-10', 31_000_000, 10_000_000);

    $svc = app(RankQualificationService::class);
    $result = $svc->checkForMonth($month);

    expect($result['rank_1_count'])->toBe(0);
});

it('creates carry-forward records for M+1 and M+2 when rank 1 is achieved', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    seedPersonalBv($dist->id, 600_000);
    seedGroupBv($dist->id, '2026-06-10', 31_000_000, 31_000_000);

    $svc = app(RankQualificationService::class);
    $svc->checkForMonth($month, occurrenceNumber: 1);

    // Should have: 1 natural + 2 carry-forwards = 3 records total.
    $records = RankQualification::where('distributor_id', $dist->id)
        ->where('rank_number', 1)
        ->get();

    expect($records)->toHaveCount(3);

    $carryForwards = $records->where('is_carry_forward', true);
    expect($carryForwards)->toHaveCount(2);

    $months = $carryForwards->pluck('month_start')->sort()->values();
    expect($months[0])->toBe('2026-07-01');
    expect($months[1])->toBe('2026-08-01');
});

it('qualifies a distributor for rank 3 (Emerald) when they have 2+ Pearl qualifiers on each Genos side', function (): void {
    // Create the candidate for Emerald.
    $candidate = Distributor::factory()->create();
    // Create 4 Pearl qualifiers: 2 on left, 2 on right under candidate.
    $leftQual1 = Distributor::factory()->create();
    $leftQual2 = Distributor::factory()->create();
    $rightQual1 = Distributor::factory()->create();
    $rightQual2 = Distributor::factory()->create();

    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    // Candidate personal BV >= 5,000,000 paise (Distributor title).
    seedPersonalBv($candidate->id, 6_000_000);

    // Wire up the binary tree: left qualifiers on 'L' side, right on 'R'.
    seedGenealogyAndSide($candidate->id, $leftQual1->id, 'L');
    seedGenealogyAndSide($candidate->id, $leftQual2->id, 'L');
    seedGenealogyAndSide($candidate->id, $rightQual1->id, 'R');
    seedGenealogyAndSide($candidate->id, $rightQual2->id, 'R');

    // Create Pearl (rank 2) qualification records for all 4 qualifiers.
    $pearlIds = [$leftQual1->id, $leftQual2->id, $rightQual1->id, $rightQual2->id];
    foreach ($pearlIds as $pearlId) {
        RankQualification::create([
            'distributor_id' => $pearlId,
            'rank_number' => 2,
            'month_start' => $monthStart,
            'occurrence_in_month' => 1,
            'is_carry_forward' => false,
            'status' => RankQualification::STATUS_QUALIFIED,
        ]);
    }

    $svc = app(RankQualificationService::class);
    $result = $svc->checkForMonth($month);

    expect($result['rank_3_count'])->toBeGreaterThanOrEqual(1);

    $emeraldRecord = RankQualification::where('distributor_id', $candidate->id)
        ->where('rank_number', 3)
        ->first();

    expect($emeraldRecord)->not->toBeNull();
    expect($emeraldRecord->status)->toBe(RankQualification::STATUS_QUALIFIED);
});
```

- [ ] **Step 2: Run RankQualificationServiceTest (expect failures — service not yet wired)**

```bash
docker exec -e DB_DATABASE=arovolife_test arovolife-app php artisan test --compact --filter=RankQualificationServiceTest
```

Expected: Tests run (some may fail if seedGenealogyAndSide helpers need table adjustment — note errors).

- [ ] **Step 3: Create RankBonusServiceTest**

Create `app/tests/Modules/Compensation/RankBonusServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Models\LifetimeAwardMilestone;
use App\Modules\Compensation\Models\RankBonusResult;
use App\Modules\Compensation\Models\RankQualification;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\RankBonusService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/**
 * Seed a paid Order in the given month.
 */
function seedRankOrder(int $totalPaise, Carbon $createdAt): void
{
    Order::create([
        'order_no' => 'ORD-'.rand(10000, 99999),
        'customer_id' => 1,
        'attributed_distributor_id' => null,
        'status' => Order::STATUS_DELIVERED,
        'payment_method' => 'cod',
        'subtotal_paise' => $totalPaise,
        'gst_paise' => 0,
        'discount_paise' => 0,
        'shipping_paise' => 0,
        'total_paise' => $totalPaise,
        'self_consumption' => false,
        'idempotency_key' => Str::uuid()->toString(),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

/**
 * Seed a qualified RankQualification record.
 */
function seedRankQualification(int $distributorId, int $rank, string $monthStart, int $occurrence = 1, bool $carryForward = false): void
{
    RankQualification::create([
        'distributor_id' => $distributorId,
        'rank_number' => $rank,
        'month_start' => $monthStart,
        'occurrence_in_month' => $occurrence,
        'is_carry_forward' => $carryForward,
        'status' => RankQualification::STATUS_QUALIFIED,
    ]);
}

it('returns zero credited when no qualifiers exist', function (): void {
    $month = Carbon::parse('2026-06-01');
    seedRankOrder(10_000_000, $month->copy()->addDays(5));

    $svc = app(RankBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['credited'])->toBe(0);
    expect(RankBonusResult::count())->toBe(0);
});

it('calculates correct pool as percentage of company turnover', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    // Turnover = 100,000,000 paise (₹10 lakh).
    // Rank 1 pool = 7% = 7,000,000 paise.
    seedRankOrder(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $result = RankBonusResult::where('distributor_id', $dist->id)
        ->where('rank_number', 1)
        ->first();

    expect($result)->not->toBeNull();
    expect($result->pool_paise)->toBe(7_000_000);
    expect($result->gross_paise)->toBe(7_000_000); // sole qualifier
});

it('applies admin charge as min(3% of gross, ₹30,000)', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    // Small pool: 7% of 1,000,000 = 70,000 paise → gross = 70,000.
    // Admin charge = floor(70,000 * 0.03) = 2,100 (less than cap of 3,000,000).
    seedRankOrder(1_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $result = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->first();
    $expectedAdminCharge = min((int) floor($result->gross_paise * 0.03), 3_000_000);

    expect($result->admin_charge_paise)->toBe($expectedAdminCharge);
    expect($result->admin_charge_paise)->toBeLessThanOrEqual(3_000_000);
});

it('caps admin charge at ₹30,000 (3,000,000 paise) for very large gross amounts', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    // Turnover = 10,000,000,000 paise → rank-1 pool = 700,000,000 paise.
    // 3% of 700,000,000 = 21,000,000 → capped at 3,000,000.
    seedRankOrder(10_000_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $result = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->first();

    expect($result->admin_charge_paise)->toBe(3_000_000);
});

it('applies 5% TDS on gross (not on gross minus admin charge)', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    seedRankOrder(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $result = RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->first();
    $expectedTds = (int) round($result->gross_paise * 0.05);

    expect($result->tds_paise)->toBe($expectedTds);
    expect($result->net_paise)->toBe($result->gross_paise - $result->admin_charge_paise - $result->tds_paise);
});

it('credits wallet with rank_credit type', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    seedRankOrder(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $ledger = WalletLedgerEntry::where('distributor_id', $dist->id)
        ->where('type', 'rank_credit')
        ->first();

    expect($ledger)->not->toBeNull();
    expect($ledger->amount_paise)->toBeGreaterThan(0);
});

it('is idempotent — re-running the same month does not double-credit', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    seedRankOrder(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);
    $svc->runForMonth($month); // second run

    expect(RankBonusResult::where('distributor_id', $dist->id)->where('rank_number', 1)->count())->toBe(1);
    expect(WalletLedgerEntry::where('distributor_id', $dist->id)->where('type', 'rank_credit')->count())->toBe(1);
});

it('creates a LifetimeAwardMilestone on first rank achievement', function (): void {
    $dist = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');
    $monthStart = '2026-06-01';

    seedRankOrder(100_000_000, $month->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: $monthStart, occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month);

    $milestone = LifetimeAwardMilestone::where('distributor_id', $dist->id)
        ->where('rank_number', 1)
        ->first();

    expect($milestone)->not->toBeNull();
    expect($milestone->status)->toBe(LifetimeAwardMilestone::STATUS_PENDING);
    expect($milestone->award_description)->toContain('Silver Partner');
});

it('does not create a duplicate LifetimeAwardMilestone on second qualification', function (): void {
    $dist = Distributor::factory()->create();
    $month1 = Carbon::parse('2026-06-01');
    $month2 = Carbon::parse('2026-07-01');

    seedRankOrder(100_000_000, $month1->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-06-01', occurrence: 1);

    seedRankOrder(100_000_000, $month2->copy()->addDays(5));
    seedRankQualification($dist->id, rank: 1, monthStart: '2026-07-01', occurrence: 1);

    $svc = app(RankBonusService::class);
    $svc->runForMonth($month1);
    $svc->runForMonth($month2);

    expect(LifetimeAwardMilestone::where('distributor_id', $dist->id)->where('rank_number', 1)->count())->toBe(1);
});
```

- [ ] **Step 4: Run all tests**

```bash
docker exec -e DB_DATABASE=arovolife_test arovolife-app php artisan test --compact --filter=RankQualificationServiceTest
docker exec -e DB_DATABASE=arovolife_test arovolife-app php artisan test --compact --filter=RankBonusServiceTest
```

Expected: all tests PASS. Fix any failures before moving on.

- [ ] **Step 5: Run pint**

```bash
cd /path/to/project/app && vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/tests/Modules/Compensation/RankQualificationServiceTest.php \
        app/tests/Modules/Compensation/RankBonusServiceTest.php
git commit -m "test(compensation): RankQualificationService and RankBonusService Pest tests"
```

---

## Task 10: Final Verification

- [ ] **Step 1: Run full test suite for compensation module**

```bash
docker exec -e DB_DATABASE=arovolife_test arovolife-app php artisan test --compact --filter=RankQualification
docker exec -e DB_DATABASE=arovolife_test arovolife-app php artisan test --compact --filter=RankBonus
```

Expected: all tests PASS.

- [ ] **Step 2: Verify artisan commands list correctly**

```bash
docker exec arovolife-app php artisan list | grep rank
```

Expected:
```
rank:check-qualifications   Check and record rank qualifications...
rank:monthly-run            Calculate and credit the Rank Bonus...
```

- [ ] **Step 3: Verify routes**

```bash
docker exec arovolife-app php artisan route:list --name=rank-bonus
docker exec arovolife-app php artisan route:list --name=lifetime-awards
docker exec arovolife-app php artisan route:list --name=income.rank-bonus
```

- [ ] **Step 4: Final commit if any pint changes remain**

```bash
cd /path/to/project/app && vendor/bin/pint --dirty --format agent
git add -A && git commit -m "style(compensation): pint formatting pass on rank bonus implementation"
```

---

## Self-Review Against Spec

**Spec coverage check:**

| Requirement | Task |
|---|---|
| wallet_ledger_entries enum migration | Task 1 |
| rank_qualifications table | Task 1 |
| rank_bonus_results table | Task 1 |
| lifetime_award_milestones table | Task 1 |
| RankQualification model with all constants | Task 2 |
| RankBonusResult model | Task 2 |
| LifetimeAwardMilestone model | Task 2 |
| RankQualificationService::checkForMonth() | Task 3 |
| Ranks 1-2 monthly BV check | Task 3 |
| Ranks 3-9 cascade side-detection query | Task 3 |
| 1+2 carry-forward rule | Task 3 |
| Void rank-1 CFs when rank-2 achieved | Task 3 |
| RankBonusService::runForMonth() | Task 4 |
| Pool = turnover * pct / 100 | Task 4 |
| Admin charge = min(3%, ₹30k) | Task 4 |
| TDS = 5% of gross | Task 4 |
| Idempotent wallet credit | Task 4 |
| LifetimeAwardMilestone creation | Task 4 |
| rank:check-qualifications command | Task 5 |
| rank:monthly-run command | Task 5 |
| Schedule on 8th at 08:00 IST | Task 5 |
| AdminRankBonusController index+show | Task 6 |
| AdminLifetimeAwardsController index+markDelivered | Task 6 |
| IncomeController::rankBonus() | Task 6 |
| Audit log on markDelivered | Task 6 |
| Admin rank-bonus routes | Task 7 |
| Admin lifetime-awards routes | Task 7 |
| Distributor income/rank-bonus route | Task 7 |
| Admin rank-bonus index view | Task 8 |
| Admin rank-bonus show view | Task 8 |
| Admin lifetime-awards index view | Task 8 |
| Distributor rank-bonus view | Task 8 |
| income/_tabs.blade.php updated | Task 8 |
| admin/compensation/overview.blade.php updated | Task 8 |
| RankQualificationServiceTest (5 tests) | Task 9 |
| RankBonusServiceTest (8 tests) | Task 9 |

**Known implementation decisions:**

1. `RankBonusService::runForMonth()` calls `companyTurnoverPaise()` twice per distributor-rank pair (once for the result record, once for the outer calculation). Extract to a local variable before the transaction to fix this — or accept the minor inefficiency since it queries the same date range.
2. The carry-forward logic creates occurrence_in_month=1 records in future months. Carry-forward qualifiers bypass the PYP check (they were already paid once in the origin month). This matches the spec ("automatically receive the bonus in M+1 and M+2").
3. The `seedGenealogyAndSide` test helper inserts self-reference rows for the child and sets `placement_parent_id` + `placement_side` on the distributor record. The side-detection query in `checkHigherRank()` uses `gc_child.depth = gc_anc.depth - 1` — which for depth=1 means gc_child.depth=0 (the self-reference row). This mirrors how GroupBvAccumulatorService works in production.
4. LifetimeAwardsFeature guards the Lifetime Awards admin controller. RankBonusFeature guards the rank bonus admin + distributor views. Both flags exist already.
