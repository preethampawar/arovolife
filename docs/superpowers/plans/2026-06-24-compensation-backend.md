# Compensation Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Compensation module backend — DB schema, models, services, jobs, and console commands that power the GSB daily cut-off, Mentorship Bonus calculation, wallet ledger, and weekly payout batch.

**Architecture:** New `app/Modules/Compensation/` module following the modular-monolith pattern. The `PropagateGroupBvJob` (queued, triggered by `OrderStatusChanged`) accumulates group BV into `group_bv_daily`. The `gsb:daily-cutoff` command reads those accumulators at 23:59, writes to `gsb_cutoff_results`, updates `gsb_carryforward`, and credits `wallet_ledger_entries`. The `gsb:weekly-payout` command drains wallet balances every Tuesday. All amounts: BV in **bv_paise** (BV × 100), money in **money_paise** (₹ × 100).

**Tech Stack:** PHP 8.4, Laravel 13, MySQL, Pest v4, Tailwind v4. Queued jobs use the `database` driver (Phase 1 setting).

---

## Scope check

This spec covers three independent subsystems; this plan covers **backend only**. See:
- `2026-06-24-compensation-admin-ui.md` — 8 admin pages (depends on this plan's tables being migrated)
- `2026-06-24-compensation-distributor-ui.md` — 5 distributor pages (depends on this plan)

---

## File structure

### New files (Compensation module)

```
app/app/Modules/Compensation/
  Database/Migrations/
    2026_06_24_100000_add_gsb_frozen_at_to_distributors.php
    2026_06_24_100001_create_group_bv_daily_table.php
    2026_06_24_100002_create_gsb_carryforward_table.php
    2026_06_24_100003_create_gsb_cutoff_results_table.php
    2026_06_24_100004_create_mentorship_bonus_results_table.php
    2026_06_24_100005_create_wallet_ledger_entries_table.php
    2026_06_24_100006_create_payout_batches_table.php
    2026_06_24_100007_create_payout_line_items_table.php
  Models/
    GroupBvDaily.php
    GsbCarryforward.php
    GsbCutoffResult.php
    MentorshipBonusResult.php
    WalletLedgerEntry.php
    PayoutBatch.php
    PayoutLineItem.php
  Services/
    DTOs/
      TitleResult.php
    PersonalBvTitleService.php
    GroupBvAccumulatorService.php
    GsbCutoffService.php
    MentorshipBonusService.php
    WalletService.php
    PayoutService.php
  Jobs/
    PropagateGroupBvJob.php
  Listeners/
    PropagateGroupBvOnOrderPaid.php
  Console/
    Commands/
      GsbDailyCutoffCommand.php
      GsbWeeklyPayoutCommand.php

app/tests/Modules/Compensation/
  PersonalBvTitleServiceTest.php
  GroupBvAccumulatorServiceTest.php
  GsbCutoffServiceTest.php
  MentorshipBonusServiceTest.php
  WalletServiceTest.php
```

### Modified files

```
app/routes/console.php                          — schedule daily cut-off + weekly payout
app/app/Providers/AppServiceProvider.php        — register listener PropagateGroupBvOnOrderPaid
```

---

## Task 0: Commit the design spec to git

**Files:**
- Commit: `docs/superpowers/specs/2026-06-24-compensation-monitoring-design.md`

- [ ] **Step 1: Stage and commit**

```bash
cd /Users/preetham/Documents/arovolife/arovolife/arovolife-code/app
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add docs/superpowers/specs/2026-06-24-compensation-monitoring-design.md
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "docs: add compensation monitoring & reporting design spec (Phase 4 Part 1)"
```

---

## Task 1: Database migrations

**Files:**
- Create: all 8 migration files in `app/app/Modules/Compensation/Database/Migrations/`

- [ ] **Step 1: Create migration for `gsb_frozen_at` on `distributors`**

```php
// 2026_06_24_100000_add_gsb_frozen_at_to_distributors.php
<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('distributors', function (Blueprint $table) {
            $table->timestamp('gsb_frozen_at')->nullable()->after('status');
        });
    }
    public function down(): void
    {
        Schema::table('distributors', function (Blueprint $table) {
            $table->dropColumn('gsb_frozen_at');
        });
    }
};
```

- [ ] **Step 2: Create `group_bv_daily` table**

```php
// 2026_06_24_100001_create_group_bv_daily_table.php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('group_bv_daily', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->date('date');
            $table->bigInteger('left_bv_paise')->default(0);
            $table->bigInteger('right_bv_paise')->default(0);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['distributor_id', 'date'], 'uniq_group_bv_daily');
            $table->index('date', 'idx_group_bv_daily_date');
            $table->foreign('distributor_id', 'fk_group_bv_daily_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('group_bv_daily'); }
};
```

- [ ] **Step 3: Create `gsb_carryforward` table**

```php
// 2026_06_24_100002_create_gsb_carryforward_table.php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('gsb_carryforward', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id')->unique('uniq_gsb_carryforward_dist');
            // BV in paise (BV × 100). Power side is the stronger leg carried forward.
            $table->bigInteger('power_side_bv_paise')->default(0);
            $table->enum('power_side', ['L', 'R'])->nullable();
            // Slab-1 weaker side accumulates indefinitely until the 15,000 BV match.
            $table->bigInteger('slab1_weaker_bv_paise')->default(0);
            $table->timestamps();

            $table->foreign('distributor_id', 'fk_gsb_carryforward_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('gsb_carryforward'); }
};
```

- [ ] **Step 4: Create `gsb_cutoff_results` table**

```php
// 2026_06_24_100003_create_gsb_cutoff_results_table.php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('gsb_cutoff_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->date('cutoff_date');
            // Today's fresh group BV (excluding carry-forward).
            $table->bigInteger('left_bv_paise')->default(0);
            $table->bigInteger('right_bv_paise')->default(0);
            $table->bigInteger('weaker_bv_paise')->default(0);   // including slab1 CF
            $table->tinyInteger('slab')->unsigned()->nullable();  // 1–7, null = no match
            // Money in paise (₹ × 100).
            $table->bigInteger('gross_gsb_paise')->default(0);
            $table->bigInteger('admin_charge_paise')->default(0);
            $table->bigInteger('tds_paise')->default(0);
            $table->bigInteger('net_gsb_paise')->default(0);
            // Carry-forward state before and after this cut-off.
            $table->bigInteger('power_cf_before_paise')->default(0);
            $table->bigInteger('power_cf_after_paise')->default(0);
            $table->enum('power_side_after', ['L', 'R'])->nullable();
            $table->bigInteger('slab1_weaker_cf_before_paise')->default(0);
            $table->bigInteger('slab1_weaker_cf_after_paise')->default(0);
            $table->enum('status', [
                'no_match', 'calculated', 'credited', 'failed', 'frozen', 'below_600bv',
            ])->default('no_match');
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['distributor_id', 'cutoff_date'], 'uniq_gsb_cutoff');
            $table->index(['cutoff_date', 'status'], 'idx_gsb_cutoff_date_status');
            $table->foreign('distributor_id', 'fk_gsb_cutoff_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('gsb_cutoff_results'); }
};
```

- [ ] **Step 5: Create `mentorship_bonus_results` table**

```php
// 2026_06_24_100004_create_mentorship_bonus_results_table.php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mentorship_bonus_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sponsor_id');
            $table->unsignedBigInteger('sponsee_id');
            $table->date('cutoff_date');
            $table->bigInteger('sponsee_gsb_paise');          // the GSB the sponsee earned
            $table->unsignedTinyInteger('mb_rate_pct');       // 1–10 (whole %)
            $table->bigInteger('mb_paise');                   // MB credited to sponsor wallet
            $table->bigInteger('sponsee_cumulative_gsb_paise');  // lifetime GSB for sponsee, determines rate
            $table->enum('status', ['credited', 'failed'])->default('credited');
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['sponsor_id', 'sponsee_id', 'cutoff_date'], 'uniq_mb_result');
            $table->index(['cutoff_date', 'sponsor_id'], 'idx_mb_result_date_sponsor');
            $table->foreign('sponsor_id', 'fk_mb_result_sponsor')
                ->references('id')->on('distributors')->cascadeOnDelete();
            $table->foreign('sponsee_id', 'fk_mb_result_sponsee')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('mentorship_bonus_results'); }
};
```

- [ ] **Step 6: Create `wallet_ledger_entries` table (Phase 3 wallet — double-entry)**

```php
// 2026_06_24_100005_create_wallet_ledger_entries_table.php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallet_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('distributor_id');
            $table->enum('type', [
                'gsb_credit',       // +
                'mb_credit',        // +
                'payout_debit',     // -
                'repurchase_deduction', // -
                'manual_credit',    // +
                'reversal',         // - (admin reverses a previous credit)
            ]);
            $table->bigInteger('amount_paise');     // signed: positive = credit, negative = debit
            $table->unsignedBigInteger('reference_id')->nullable();   // gsb_cutoff_result.id etc.
            $table->string('reference_type', 50)->nullable();         // 'gsb_cutoff_result', etc.
            $table->text('memo')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['distributor_id', 'created_at'], 'idx_wallet_dist_created');
            $table->index(['reference_type', 'reference_id'], 'idx_wallet_ref');
            $table->foreign('distributor_id', 'fk_wallet_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('wallet_ledger_entries'); }
};
```

- [ ] **Step 7: Create `payout_batches` table**

```php
// 2026_06_24_100006_create_payout_batches_table.php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('payout_batches', function (Blueprint $table) {
            $table->id();
            $table->date('batch_date')->unique('uniq_payout_batch_date');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->bigInteger('total_gross_paise')->default(0);
            $table->bigInteger('total_deductions_paise')->default(0);
            $table->bigInteger('total_net_paise')->default(0);
            $table->unsignedInteger('distributor_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('payout_batches'); }
};
```

- [ ] **Step 8: Create `payout_line_items` table**

```php
// 2026_06_24_100007_create_payout_line_items_table.php
return new class extends Migration {
    public function up(): void
    {
        Schema::create('payout_line_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payout_batch_id');
            $table->unsignedBigInteger('distributor_id');
            $table->bigInteger('wallet_balance_paise');
            $table->bigInteger('repurchase_deduction_paise')->default(0);
            $table->bigInteger('net_transferred_paise');
            $table->string('bank_account_last4', 4)->nullable();
            $table->string('utr_number', 64)->nullable();
            $table->enum('status', ['pending', 'transferred', 'failed', 'below_minimum'])->default('pending');
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index('payout_batch_id', 'idx_payout_line_batch');
            $table->index('distributor_id', 'idx_payout_line_dist');
            $table->unique(['payout_batch_id', 'distributor_id'], 'uniq_payout_line');
            $table->foreign('payout_batch_id', 'fk_payout_line_batch')
                ->references('id')->on('payout_batches')->cascadeOnDelete();
            $table->foreign('distributor_id', 'fk_payout_line_dist')
                ->references('id')->on('distributors')->cascadeOnDelete();
        });
    }
    public function down(): void { Schema::dropIfExists('payout_line_items'); }
};
```

- [ ] **Step 9: Run migrations**

```bash
cd /Users/preetham/Documents/arovolife/arovolife/arovolife-code/app
php artisan migrate --compact
```

Expected: 8 new migrations applied. No errors.

- [ ] **Step 10: Commit**

```bash
cd /Users/preetham/Documents/arovolife/arovolife/arovolife-code/app
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add app/Modules/Compensation/Database/
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): add Phase 4 DB schema — group BV, cut-off results, carry-forward, wallet, payouts"
```

---

## Task 2: Models

**Files:**
- Create: 7 model files in `app/app/Modules/Compensation/Models/`

- [ ] **Step 1: Create `GroupBvDaily` model**

```php
// app/Modules/Compensation/Models/GroupBvDaily.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $distributor_id
 * @property string $date  (YYYY-MM-DD)
 * @property int $left_bv_paise
 * @property int $right_bv_paise
 */
final class GroupBvDaily extends Model
{
    public $timestamps = false;
    protected $table = 'group_bv_daily';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'left_bv_paise' => 'integer',
            'right_bv_paise' => 'integer',
        ];
    }
}
```

- [ ] **Step 2: Create `GsbCarryforward` model**

```php
// app/Modules/Compensation/Models/GsbCarryforward.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $distributor_id
 * @property int $power_side_bv_paise
 * @property string|null $power_side   ('L'|'R'|null)
 * @property int $slab1_weaker_bv_paise
 */
final class GsbCarryforward extends Model
{
    protected $table = 'gsb_carryforward';
    protected $fillable = [
        'distributor_id', 'power_side_bv_paise', 'power_side', 'slab1_weaker_bv_paise',
    ];

    protected function casts(): array
    {
        return [
            'power_side_bv_paise' => 'integer',
            'slab1_weaker_bv_paise' => 'integer',
        ];
    }
}
```

- [ ] **Step 3: Create `GsbCutoffResult` model**

```php
// app/Modules/Compensation/Models/GsbCutoffResult.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $distributor_id
 * @property string $cutoff_date
 * @property int $left_bv_paise
 * @property int $right_bv_paise
 * @property int $weaker_bv_paise
 * @property int|null $slab
 * @property int $gross_gsb_paise
 * @property int $admin_charge_paise
 * @property int $tds_paise
 * @property int $net_gsb_paise
 * @property int $power_cf_before_paise
 * @property int $power_cf_after_paise
 * @property string|null $power_side_after
 * @property int $slab1_weaker_cf_before_paise
 * @property int $slab1_weaker_cf_after_paise
 * @property string $status
 * @property string|null $failure_reason
 */
final class GsbCutoffResult extends Model
{
    public const STATUS_NO_MATCH    = 'no_match';
    public const STATUS_CALCULATED  = 'calculated';
    public const STATUS_CREDITED    = 'credited';
    public const STATUS_FAILED      = 'failed';
    public const STATUS_FROZEN      = 'frozen';
    public const STATUS_BELOW_600BV = 'below_600bv';

    protected $table = 'gsb_cutoff_results';
    protected $fillable = [
        'distributor_id', 'cutoff_date',
        'left_bv_paise', 'right_bv_paise', 'weaker_bv_paise',
        'slab', 'gross_gsb_paise', 'admin_charge_paise', 'tds_paise', 'net_gsb_paise',
        'power_cf_before_paise', 'power_cf_after_paise', 'power_side_after',
        'slab1_weaker_cf_before_paise', 'slab1_weaker_cf_after_paise',
        'status', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'left_bv_paise' => 'integer',
            'right_bv_paise' => 'integer',
            'weaker_bv_paise' => 'integer',
            'slab' => 'integer',
            'gross_gsb_paise' => 'integer',
            'admin_charge_paise' => 'integer',
            'tds_paise' => 'integer',
            'net_gsb_paise' => 'integer',
            'power_cf_before_paise' => 'integer',
            'power_cf_after_paise' => 'integer',
            'slab1_weaker_cf_before_paise' => 'integer',
            'slab1_weaker_cf_after_paise' => 'integer',
        ];
    }
}
```

- [ ] **Step 4: Create `MentorshipBonusResult`, `WalletLedgerEntry`, `PayoutBatch`, `PayoutLineItem` models**

```php
// MentorshipBonusResult.php
final class MentorshipBonusResult extends Model
{
    protected $table = 'mentorship_bonus_results';
    protected $fillable = [
        'sponsor_id', 'sponsee_id', 'cutoff_date',
        'sponsee_gsb_paise', 'mb_rate_pct', 'mb_paise', 'sponsee_cumulative_gsb_paise',
        'status', 'failure_reason',
    ];
    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'sponsee_gsb_paise' => 'integer',
            'mb_rate_pct' => 'integer',
            'mb_paise' => 'integer',
            'sponsee_cumulative_gsb_paise' => 'integer',
        ];
    }
}

// WalletLedgerEntry.php
final class WalletLedgerEntry extends Model
{
    public const UPDATED_AT = null;
    protected $table = 'wallet_ledger_entries';
    protected $fillable = [
        'distributor_id', 'type', 'amount_paise',
        'reference_id', 'reference_type', 'memo',
    ];
    protected function casts(): array
    {
        return ['amount_paise' => 'integer', 'reference_id' => 'integer'];
    }
}

// PayoutBatch.php
final class PayoutBatch extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';

    protected $table = 'payout_batches';
    protected $fillable = [
        'batch_date', 'status',
        'total_gross_paise', 'total_deductions_paise', 'total_net_paise',
        'distributor_count', 'processed_at',
    ];
    protected function casts(): array
    {
        return [
            'batch_date' => 'date',
            'processed_at' => 'datetime',
            'total_gross_paise' => 'integer',
            'total_deductions_paise' => 'integer',
            'total_net_paise' => 'integer',
            'distributor_count' => 'integer',
        ];
    }
}

// PayoutLineItem.php
final class PayoutLineItem extends Model
{
    protected $table = 'payout_line_items';
    protected $fillable = [
        'payout_batch_id', 'distributor_id',
        'wallet_balance_paise', 'repurchase_deduction_paise', 'net_transferred_paise',
        'bank_account_last4', 'utr_number', 'status', 'failure_reason',
    ];
    protected function casts(): array
    {
        return [
            'wallet_balance_paise' => 'integer',
            'repurchase_deduction_paise' => 'integer',
            'net_transferred_paise' => 'integer',
        ];
    }
}
```

Each model goes in `app/app/Modules/Compensation/Models/` with the proper namespace `App\Modules\Compensation\Models` and `declare(strict_types=1);` at the top.

- [ ] **Step 5: Commit**

```bash
cd /Users/preetham/Documents/arovolife/arovolife/arovolife-code/app
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add app/Modules/Compensation/Models/
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): add Eloquent models for cut-off results, wallet, payouts, carry-forward"
```

---

## Task 3: `PersonalBvTitleService`

**Files:**
- Create: `app/app/Modules/Compensation/Services/DTOs/TitleResult.php`
- Create: `app/app/Modules/Compensation/Services/PersonalBvTitleService.php`
- Test: `app/tests/Modules/Compensation/PersonalBvTitleServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
// tests/Modules/Compensation/PersonalBvTitleServiceTest.php
<?php
declare(strict_types=1);

use App\Modules\Compensation\Services\PersonalBvTitleService;

it('returns null title below 3000 BV', function () {
    $svc = new PersonalBvTitleService();
    $result = $svc->forBvPaise(299_999); // 2,999.99 BV
    expect($result->title)->toBeNull();
    expect($result->maxGsbSlab)->toBe(0);
    expect($result->nextTitleBvPaise)->toBe(300_000);
});

it('returns Retailer at exactly 3000 BV', function () {
    $svc = new PersonalBvTitleService();
    $result = $svc->forBvPaise(300_000); // 3,000 BV
    expect($result->title)->toBe('Retailer');
    expect($result->maxGsbSlab)->toBe(1);
    expect($result->nextTitleBvPaise)->toBe(500_000);
});

it('returns Dealer at 5000 BV', function () {
    $svc = new PersonalBvTitleService();
    $result = $svc->forBvPaise(500_000);
    expect($result->title)->toBe('Dealer');
    expect($result->maxGsbSlab)->toBe(2);
});

it('returns Wholesaler at 15000 BV', function () {
    $svc = new PersonalBvTitleService();
    $result = $svc->forBvPaise(1_500_000);
    expect($result->title)->toBe('Wholesaler');
    expect($result->maxGsbSlab)->toBe(3);
});

it('returns Global Distributor at 300000 BV with no next title', function () {
    $svc = new PersonalBvTitleService();
    $result = $svc->forBvPaise(30_000_000);
    expect($result->title)->toBe('Global Distributor');
    expect($result->maxGsbSlab)->toBe(7);
    expect($result->nextTitleBvPaise)->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd /Users/preetham/Documents/arovolife/arovolife/arovolife-code/app
php artisan test --compact --filter=PersonalBvTitleServiceTest
```

Expected: FAIL with class not found.

- [ ] **Step 3: Create `TitleResult` DTO**

```php
// app/Modules/Compensation/Services/DTOs/TitleResult.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

final readonly class TitleResult
{
    public function __construct(
        public readonly ?string $title,
        public readonly int $maxGsbSlab,       // 0–7; 0 means no GSB eligible
        public readonly ?int $nextTitleBvPaise, // null at top title
    ) {}
}
```

- [ ] **Step 4: Create `PersonalBvTitleService`**

```php
// app/Modules/Compensation/Services/PersonalBvTitleService.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Services\DTOs\TitleResult;

/**
 * Resolves a distributor's personal purchase title and their maximum GSB slab
 * from their lifetime personal BV (in paise, i.e. BV × 100).
 *
 * Titles from the 2026-06-19 revenue sharing plan.
 * GSB slab constraint: the achieved slab is the LOWER of the matched group BV
 * slab and the distributor's title slab. This service provides the title slab cap.
 */
final class PersonalBvTitleService
{
    /** [min_bv_paise => [title, gsb_slab]] in ascending order. */
    private const LADDER = [
        300_000   => ['title' => 'Retailer',            'slab' => 1],
        500_000   => ['title' => 'Dealer',              'slab' => 2],
        1_500_000 => ['title' => 'Wholesaler',          'slab' => 3],
        5_000_000 => ['title' => 'Distributor',         'slab' => 4],
        10_000_000 => ['title' => 'Regional Distributor', 'slab' => 5],
        20_000_000 => ['title' => 'National Distributor', 'slab' => 6],
        30_000_000 => ['title' => 'Global Distributor',  'slab' => 7],
    ];

    public function forBvPaise(int $bvPaise): TitleResult
    {
        $matched = null;
        $thresholds = array_keys(self::LADDER);

        foreach (array_reverse($thresholds, preserve_keys: true) as $threshold => $entry) {
            if ($bvPaise >= $threshold) {
                $matched = ['threshold' => $threshold, ...$entry];
                break;
            }
        }

        if ($matched === null) {
            return new TitleResult(
                title: null,
                maxGsbSlab: 0,
                nextTitleBvPaise: $thresholds[0],
            );
        }

        $nextThreshold = null;
        foreach ($thresholds as $t) {
            if ($t > $matched['threshold']) {
                $nextThreshold = $t;
                break;
            }
        }

        return new TitleResult(
            title: $matched['title'],
            maxGsbSlab: $matched['slab'],
            nextTitleBvPaise: $nextThreshold,
        );
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact --filter=PersonalBvTitleServiceTest
```

Expected: All 5 PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add app/Modules/Compensation/Services/ tests/Modules/Compensation/PersonalBvTitleServiceTest.php
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): PersonalBvTitleService — title + max GSB slab from lifetime BV"
```

---

## Task 4: `WalletService`

**Files:**
- Create: `app/app/Modules/Compensation/Services/WalletService.php`
- Test: `app/tests/Modules/Compensation/WalletServiceTest.php`

- [ ] **Step 1: Write failing tests**

```php
// tests/Modules/Compensation/WalletServiceTest.php
<?php
declare(strict_types=1);

use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Models\Distributor;

uses(\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

it('balance returns 0 for new distributor', function () {
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);
    expect($svc->balancePaise($dist->id))->toBe(0);
});

it('credit adds a positive entry', function () {
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);
    $svc->credit($dist->id, 100_000, 'gsb_credit', 1, 'gsb_cutoff_result', 'GSB for 24 Jun');
    expect($svc->balancePaise($dist->id))->toBe(100_000);
});

it('debit subtracts from balance', function () {
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);
    $svc->credit($dist->id, 100_000, 'gsb_credit');
    $svc->debit($dist->id, 40_000, 'payout_debit');
    expect($svc->balancePaise($dist->id))->toBe(60_000);
});

it('balance is the sum of all signed entries', function () {
    $dist = Distributor::factory()->create();
    $svc = app(WalletService::class);
    $svc->credit($dist->id, 552_900, 'gsb_credit');    // ₹5,529
    $svc->credit($dist->id, 27_640, 'mb_credit');      // ₹276.40
    $svc->debit($dist->id, 552_900, 'payout_debit');
    expect($svc->balancePaise($dist->id))->toBe(27_640);
});
```

- [ ] **Step 2: Run to verify they fail**

```bash
php artisan test --compact --filter=WalletServiceTest
```

- [ ] **Step 3: Implement `WalletService`**

```php
// app/Modules/Compensation/Services/WalletService.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\WalletLedgerEntry;
use Illuminate\Support\Facades\DB;

final class WalletService
{
    public function balancePaise(int $distributorId): int
    {
        return (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->sum('amount_paise');
    }

    public function credit(
        int $distributorId,
        int $amountPaise,
        string $type,
        ?int $referenceId = null,
        ?string $referenceType = null,
        ?string $memo = null,
    ): WalletLedgerEntry {
        return WalletLedgerEntry::create([
            'distributor_id' => $distributorId,
            'type' => $type,
            'amount_paise' => abs($amountPaise),  // always positive for credits
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'memo' => $memo,
        ]);
    }

    public function debit(
        int $distributorId,
        int $amountPaise,
        string $type,
        ?int $referenceId = null,
        ?string $referenceType = null,
        ?string $memo = null,
    ): WalletLedgerEntry {
        return WalletLedgerEntry::create([
            'distributor_id' => $distributorId,
            'type' => $type,
            'amount_paise' => -abs($amountPaise),  // always negative for debits
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'memo' => $memo,
        ]);
    }

    /** Running balance ledger with cumulative sum, ordered by created_at. */
    public function ledgerWithRunningBalance(int $distributorId): \Illuminate\Support\Collection
    {
        $entries = WalletLedgerEntry::where('distributor_id', $distributorId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $running = 0;
        return $entries->map(function (WalletLedgerEntry $e) use (&$running) {
            $running += $e->amount_paise;
            return ['entry' => $e, 'running_balance_paise' => $running];
        });
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact --filter=WalletServiceTest
```

Expected: All 4 PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add app/Modules/Compensation/Services/WalletService.php tests/Modules/Compensation/WalletServiceTest.php
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): WalletService — double-entry wallet ledger for Phase 3"
```

---

## Task 5: `GroupBvAccumulatorService` + `PropagateGroupBvJob`

**Files:**
- Create: `app/app/Modules/Compensation/Services/GroupBvAccumulatorService.php`
- Create: `app/app/Modules/Compensation/Jobs/PropagateGroupBvJob.php`
- Create: `app/app/Modules/Compensation/Listeners/PropagateGroupBvOnOrderPaid.php`
- Test: `app/tests/Modules/Compensation/GroupBvAccumulatorServiceTest.php`

The propagation logic: when distributor D makes a purchase with BV `b` on date `d`, for every ancestor A of D in the binary placement tree, add `b` to A's left or right group BV for that date. Which side? D is in A's LEFT subtree if D is a descendant of A's left child (where `distributors.placement_parent_id = A.id AND placement_side = 'L'`).

- [ ] **Step 1: Write failing tests**

```php
// tests/Modules/Compensation/GroupBvAccumulatorServiceTest.php
<?php
declare(strict_types=1);

use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Services\GroupBvAccumulatorService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Helper: create a distributor placed as a child of $parent on $side.
 * Inserts the closure table rows manually (like PlacementEngine would).
 */
function makePlacedDistributor(Distributor $parent, string $side): Distributor
{
    $child = Distributor::factory()->create([
        'placement_parent_id' => $parent->id,
        'placement_side' => $side,
        'depth' => $parent->depth + 1,
    ]);
    // Insert closure rows: self-ref + all ancestor rows
    DB::table('genealogy_closure')->insert(['ancestor_id' => $child->id, 'descendant_id' => $child->id, 'depth' => 0]);
    $ancestors = DB::table('genealogy_closure')->where('descendant_id', $parent->id)->get();
    foreach ($ancestors as $row) {
        DB::table('genealogy_closure')->insert([
            'ancestor_id' => $row->ancestor_id,
            'descendant_id' => $child->id,
            'depth' => $row->depth + 1,
        ]);
    }
    return $child;
}

it('accumulates BV into the correct group for a direct child', function () {
    $root = Distributor::factory()->create(['depth' => 0]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0]);
    $leftChild = makePlacedDistributor($root, 'L');

    $svc = app(GroupBvAccumulatorService::class);
    $date = Carbon::today();
    $svc->propagate($leftChild->id, 500_000, $date);  // 5,000 BV

    $row = GroupBvDaily::where('distributor_id', $root->id)->where('date', $date->toDateString())->first();
    expect($row)->not->toBeNull();
    expect($row->left_bv_paise)->toBe(500_000);
    expect($row->right_bv_paise)->toBe(0);
});

it('accumulates BV for a grandchild through two levels', function () {
    $root = Distributor::factory()->create(['depth' => 0]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0]);
    $leftChild = makePlacedDistributor($root, 'L');
    $grandchild = makePlacedDistributor($leftChild, 'R');  // right of left child = still LEFT of root

    $svc = app(GroupBvAccumulatorService::class);
    $date = Carbon::today();
    $svc->propagate($grandchild->id, 300_000, $date);

    $rootRow = GroupBvDaily::where('distributor_id', $root->id)->where('date', $date->toDateString())->first();
    $leftRow = GroupBvDaily::where('distributor_id', $leftChild->id)->where('date', $date->toDateString())->first();

    expect($rootRow->left_bv_paise)->toBe(300_000);  // grandchild is in root's left group
    expect($rootRow->right_bv_paise)->toBe(0);
    expect($leftRow->right_bv_paise)->toBe(300_000);  // grandchild is in leftChild's right group
});

it('adds to existing accumulator on the same date', function () {
    $root = Distributor::factory()->create(['depth' => 0]);
    DB::table('genealogy_closure')->insert(['ancestor_id' => $root->id, 'descendant_id' => $root->id, 'depth' => 0]);
    $leftChild = makePlacedDistributor($root, 'L');

    $svc = app(GroupBvAccumulatorService::class);
    $date = Carbon::today();
    $svc->propagate($leftChild->id, 200_000, $date);
    $svc->propagate($leftChild->id, 300_000, $date);  // second order same day

    $row = GroupBvDaily::where('distributor_id', $root->id)->where('date', $date->toDateString())->first();
    expect($row->left_bv_paise)->toBe(500_000);
});
```

- [ ] **Step 2: Run to verify they fail**

```bash
php artisan test --compact --filter=GroupBvAccumulatorServiceTest
```

- [ ] **Step 3: Implement `GroupBvAccumulatorService`**

```php
// app/Modules/Compensation/Services/GroupBvAccumulatorService.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Propagates one distributor's BV purchase to all their ancestors' group_bv_daily
 * accumulators. For each ancestor A, determines which side (L/R) this distributor
 * falls on by checking which of A's direct children is an ancestor of this distributor.
 */
final class GroupBvAccumulatorService
{
    /** Max BV cap for the power side carry-forward (450,000 BV × 100 paise). */
    public const POWER_CF_CAP_PAISE = 45_000_000;

    public function propagate(int $distributorId, int $bvPaise, Carbon $date): void
    {
        // For each ancestor A of D (at any depth), find the direct child of A on
        // the path to D. That child's placement_side relative to A = the side D is on.
        $pairs = DB::table('genealogy_closure as gc_anc')
            ->join('genealogy_closure as gc_child', function ($join) {
                $join->on('gc_child.descendant_id', '=', 'gc_anc.descendant_id')
                    ->whereColumn('gc_child.depth', '=', DB::raw('gc_anc.depth - 1'));
            })
            ->join('distributors as dc', function ($join) {
                $join->on('dc.id', '=', 'gc_child.ancestor_id')
                    ->on('dc.placement_parent_id', '=', 'gc_anc.ancestor_id');
            })
            ->where('gc_anc.descendant_id', $distributorId)
            ->where('gc_anc.depth', '>', 0)
            ->whereIn('dc.placement_side', ['L', 'R'])
            ->select('gc_anc.ancestor_id', 'dc.placement_side as side')
            ->get();

        $dateStr = $date->toDateString();

        foreach ($pairs as $pair) {
            $leftAdd  = $pair->side === 'L' ? $bvPaise : 0;
            $rightAdd = $pair->side === 'R' ? $bvPaise : 0;

            // Atomic increment — safe for concurrent jobs on the same ancestor.
            DB::statement(
                "INSERT INTO group_bv_daily (distributor_id, `date`, left_bv_paise, right_bv_paise, updated_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                     left_bv_paise  = left_bv_paise  + VALUES(left_bv_paise),
                     right_bv_paise = right_bv_paise + VALUES(right_bv_paise),
                     updated_at     = NOW()",
                [$pair->ancestor_id, $dateStr, $leftAdd, $rightAdd],
            );
        }
    }
}
```

- [ ] **Step 4: Implement `PropagateGroupBvJob`**

```php
// app/Modules/Compensation/Jobs/PropagateGroupBvJob.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Jobs;

use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Services\GroupBvAccumulatorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

final class PropagateGroupBvJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly int $orderId,
        private readonly int $distributorId,
        private readonly int $bvPaise,
        private readonly string $date,  // YYYY-MM-DD, captured at dispatch time
    ) {}

    public function handle(GroupBvAccumulatorService $accumulator): void
    {
        if ($this->bvPaise <= 0) {
            return;
        }
        $accumulator->propagate($this->distributorId, $this->bvPaise, Carbon::parse($this->date));
    }
}
```

- [ ] **Step 5: Implement `PropagateGroupBvOnOrderPaid` listener**

```php
// app/Modules/Compensation/Listeners/PropagateGroupBvOnOrderPaid.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Listeners;

use App\Modules\Commerce\Events\OrderStatusChanged;
use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Jobs\PropagateGroupBvJob;
use Illuminate\Support\Carbon;

final class PropagateGroupBvOnOrderPaid
{
    public function handle(OrderStatusChanged $event): void
    {
        if ($event->newStatus !== Order::STATUS_PAID) {
            return;
        }

        $order = Order::with('distributor')->find($event->orderId);
        if ($order === null || $order->distributor_id === null) {
            return;
        }

        // Sum the BV accrued for this order from the BV ledger.
        $bvPaise = (int) BvLedgerEntry::where('order_id', $event->orderId)
            ->where('type', BvLedgerEntry::TYPE_ACCRUAL)
            ->sum('bv_paise');

        if ($bvPaise <= 0) {
            return;
        }

        PropagateGroupBvJob::dispatch(
            orderId: $event->orderId,
            distributorId: $order->distributor_id,
            bvPaise: $bvPaise,
            date: Carbon::now()->toDateString(),
        );
    }
}
```

- [ ] **Step 6: Register listener in `AppServiceProvider`**

Open `app/app/Providers/AppServiceProvider.php` and add to the `boot()` method:

```php
use App\Modules\Commerce\Events\OrderStatusChanged;
use App\Modules\Compensation\Listeners\PropagateGroupBvOnOrderPaid;
use Illuminate\Support\Facades\Event;

// Inside boot():
Event::listen(OrderStatusChanged::class, PropagateGroupBvOnOrderPaid::class);
```

- [ ] **Step 7: Run tests**

```bash
php artisan test --compact --filter=GroupBvAccumulatorServiceTest
```

Expected: All 3 PASS.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add \
  app/Modules/Compensation/Services/GroupBvAccumulatorService.php \
  app/Modules/Compensation/Jobs/PropagateGroupBvJob.php \
  app/Modules/Compensation/Listeners/PropagateGroupBvOnOrderPaid.php \
  app/Providers/AppServiceProvider.php \
  tests/Modules/Compensation/GroupBvAccumulatorServiceTest.php
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): PropagateGroupBvJob + listener — fan out BV to all ancestors on OrderPaid"
```

---

## Task 6: `GsbCutoffService`

**Files:**
- Create: `app/app/Modules/Compensation/Services/GsbCutoffService.php`
- Test: `app/tests/Modules/Compensation/GsbCutoffServiceTest.php`

This is the core algorithm. Key rules:
1. Skip if personal BV < 600 BV (60,000 paise) → status `below_600bv`
2. Get title → if no title (< 3,000 BV, 300,000 paise) → no max slab, no GSB
3. Effective left = today's left BV + power CF (if power side was L)
4. Effective right = today's right BV + power CF (if power side was R)
5. Weaker = min(effective left, effective right)
6. Add slab1 CF to weaker → total weaker (for slab matching)
7. Find highest slab where threshold ≤ total_weaker AND slab ≤ title max slab
8. Deductions: admin charge = min(3% of gross, ₹30,000). TDS = 5% of (gross − admin charge). Net = gross − admin − TDS
9. New power CF = max(0, stronger − slab threshold), capped at 450,000 BV = 45,000,000 paise
10. If frozen: write `gsb_cutoff_results` with status `frozen`, no wallet credit
11. If credited: write wallet entry, update carry-forward atomically

**GSB slab table** (threshold in bv_paise, incentive in money_paise ≡ ₹ × 100):

| Slab | Threshold (bv_paise) | Incentive (paise) |
|------|---------------------|-------------------|
| 1    | 1,500,000           | 100,000           |
| 2    | 3,000,000           | 300,000           |
| 3    | 9,000,000           | 600,000           |
| 4    | 27,000,000          | 1,200,000         |
| 5    | 80,000,000          | 2,400,000         |
| 6    | 240,000,000         | 4,000,000         |
| 7    | 720,000,000         | 6,000,000         |

- [ ] **Step 1: Write failing tests**

```php
// tests/Modules/Compensation/GsbCutoffServiceTest.php
<?php
declare(strict_types=1);

use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\GsbCutoffService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;

uses(\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeDistributorWithBv(int $bvPaise): Distributor
{
    $dist = Distributor::factory()->create();
    // Write a BV ledger entry for the distributor so PersonalBvTitleService sees their BV.
    \App\Modules\Commerce\Models\BvLedgerEntry::create([
        'distributor_id' => $dist->id,
        'order_id' => 999_999,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);
    return $dist;
}

it('returns below_600bv status when personal BV is under 600 BV', function () {
    $dist = makeDistributorWithBv(59_999);  // 599.99 BV
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 2_000_000,
        'right_bv_paise' => 2_000_000,
    ]);

    $svc = app(GsbCutoffService::class);
    $result = $svc->runForDistributor($dist->id, Carbon::today());

    expect($result->status)->toBe(GsbCutoffResult::STATUS_BELOW_600BV);
    expect($result->slab)->toBeNull();
    expect($result->net_gsb_paise)->toBe(0);
});

it('returns no_match when group BV does not reach any slab', function () {
    $dist = makeDistributorWithBv(300_000);  // Retailer (3,000 BV)
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 1_000_000,  // 10,000 BV — below 15K threshold
        'right_bv_paise' => 800_000,   // 8,000 BV weaker
    ]);

    $svc = app(GsbCutoffService::class);
    $result = $svc->runForDistributor($dist->id, Carbon::today());

    expect($result->status)->toBe(GsbCutoffResult::STATUS_NO_MATCH);
    expect($result->slab)->toBeNull();
    // Slab1 weaker CF should accumulate the weaker side (800,000 paise)
    $cf = GsbCarryforward::where('distributor_id', $dist->id)->first();
    expect($cf->slab1_weaker_bv_paise)->toBe(800_000);
    expect($cf->power_side_bv_paise)->toBe(1_000_000);
    expect($cf->power_side)->toBe('L');
});

it('credits slab 1 when weaker side meets 15,000 BV threshold', function () {
    $dist = makeDistributorWithBv(300_000);  // Retailer
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 2_000_000,   // 20,000 BV
        'right_bv_paise' => 1_600_000,  // 16,000 BV — weaker, ≥ 15K threshold
    ]);

    $svc = app(GsbCutoffService::class);
    $result = $svc->runForDistributor($dist->id, Carbon::today());

    expect($result->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
    expect($result->slab)->toBe(1);
    expect($result->gross_gsb_paise)->toBe(100_000);   // ₹1,000

    // Admin charge = 3% × 100,000 = 3,000 paise = ₹30
    expect($result->admin_charge_paise)->toBe(3_000);

    // TDS = 5% × (100,000 - 3,000) = 5% × 97,000 = 4,850 paise
    expect($result->tds_paise)->toBe(4_850);
    expect($result->net_gsb_paise)->toBe(92_150);  // 100,000 - 3,000 - 4,850

    // Power CF = stronger (2,000,000) - threshold (1,500,000) = 500,000
    $cf = GsbCarryforward::where('distributor_id', $dist->id)->first();
    expect($cf->power_side_bv_paise)->toBe(500_000);
    expect($cf->power_side)->toBe('L');
    expect($cf->slab1_weaker_bv_paise)->toBe(0);  // reset after match
});

it('carries over slab1 weaker CF from previous day to reach threshold', function () {
    $dist = makeDistributorWithBv(300_000);  // Retailer
    // Previous day: weaker was 1,000,000 (10,000 BV) — saved to slab1 CF
    GsbCarryforward::create([
        'distributor_id' => $dist->id,
        'power_side_bv_paise' => 1_200_000,   // 12,000 BV power side
        'power_side' => 'R',
        'slab1_weaker_bv_paise' => 1_000_000, // 10,000 BV accumulated
    ]);
    // Today: left = 5,000 BV, right (with 12K CF) = 5K + 12K = 17K
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 500_000,   // 5,000 BV
        'right_bv_paise' => 500_000,  // 5,000 BV
    ]);

    // Effective right = 500K + 1,200K (CF) = 1,700K
    // Effective left  = 500K
    // Weaker = 500K + slab1_CF = 500K + 1,000K = 1,500K ≥ 1,500K threshold → slab 1!
    $svc = app(GsbCutoffService::class);
    $result = $svc->runForDistributor($dist->id, Carbon::today());

    expect($result->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
    expect($result->slab)->toBe(1);
    $cf = GsbCarryforward::where('distributor_id', $dist->id)->first();
    expect($cf->slab1_weaker_bv_paise)->toBe(0);  // reset
    // Power CF = stronger_effective(1,700K) - threshold(1,500K) = 200K
    expect($cf->power_side_bv_paise)->toBe(200_000);
    expect($cf->power_side)->toBe('R');
});

it('caps power CF at 45,000,000 paise (450,000 BV)', function () {
    $dist = makeDistributorWithBv(30_000_000);  // Global Distributor
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 800_000_000,  // 8M BV stronger
        'right_bv_paise' => 80_000_000,  // 800K BV weaker — matches slab 5
    ]);

    $svc = app(GsbCutoffService::class);
    $result = $svc->runForDistributor($dist->id, Carbon::today());

    expect($result->status)->toBe(GsbCutoffResult::STATUS_CREDITED);
    expect($result->slab)->toBe(5);
    $cf = GsbCarryforward::where('distributor_id', $dist->id)->first();
    // Without cap: 800M - 80M = 720M paise. Capped at 45M.
    expect($cf->power_side_bv_paise)->toBe(45_000_000);
});

it('marks status as frozen when distributor GSB is frozen', function () {
    $dist = makeDistributorWithBv(1_500_000);  // Wholesaler
    $dist->update(['gsb_frozen_at' => now()]);
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 10_000_000,
        'right_bv_paise' => 10_000_000,
    ]);

    $svc = app(GsbCutoffService::class);
    $result = $svc->runForDistributor($dist->id, Carbon::today());

    expect($result->status)->toBe(GsbCutoffResult::STATUS_FROZEN);
    expect($result->slab)->toBe(3);
    expect($result->gross_gsb_paise)->toBe(600_000);
    // Wallet should NOT have been credited
    expect(\App\Modules\Compensation\Models\WalletLedgerEntry::where('distributor_id', $dist->id)->count())->toBe(0);
});

it('is idempotent — second call returns existing credited result', function () {
    $dist = makeDistributorWithBv(300_000);
    GroupBvDaily::create([
        'distributor_id' => $dist->id,
        'date' => today()->toDateString(),
        'left_bv_paise' => 2_000_000,
        'right_bv_paise' => 1_600_000,
    ]);

    $svc = app(GsbCutoffService::class);
    $r1 = $svc->runForDistributor($dist->id, Carbon::today());
    $r2 = $svc->runForDistributor($dist->id, Carbon::today());

    expect($r1->id)->toBe($r2->id);
    expect(\App\Modules\Compensation\Models\WalletLedgerEntry::where('distributor_id', $dist->id)->count())->toBe(1);
});
```

- [ ] **Step 2: Run to verify they fail**

```bash
php artisan test --compact --filter=GsbCutoffServiceTest
```

- [ ] **Step 3: Implement `GsbCutoffService`**

```php
// app/Modules/Compensation/Services/GsbCutoffService.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCarryforward;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Commerce\Services\BvLedgerService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class GsbCutoffService
{
    /** Power-side carry-forward hard cap: 450,000 BV = 45,000,000 paise. */
    private const POWER_CF_CAP_PAISE = 45_000_000;

    /** Minimum personal BV to participate: 600 BV = 60,000 paise. */
    private const MIN_PERSONAL_BV_PAISE = 60_000;

    /** Max admin charge: ₹30,000 = 3,000,000 paise. */
    private const MAX_ADMIN_CHARGE_PAISE = 3_000_000;

    /**
     * [slab_index => [threshold_bv_paise, incentive_money_paise]]
     * Threshold in BV paise (BV × 100); incentive in money paise (₹ × 100).
     */
    private const SLABS = [
        1 => [1_500_000, 100_000],
        2 => [3_000_000, 300_000],
        3 => [9_000_000, 600_000],
        4 => [27_000_000, 1_200_000],
        5 => [80_000_000, 2_400_000],
        6 => [240_000_000, 4_000_000],
        7 => [720_000_000, 6_000_000],
    ];

    public function __construct(
        private readonly BvLedgerService $bvLedger,
        private readonly PersonalBvTitleService $titleService,
        private readonly WalletService $wallet,
    ) {}

    /**
     * Run (or re-run) the 23:59 cut-off for one distributor on one date.
     * Idempotent: if a 'credited' result already exists for this date, return it unchanged.
     */
    public function runForDistributor(int $distributorId, Carbon $date): GsbCutoffResult
    {
        // Idempotency: never double-credit.
        $existing = GsbCutoffResult::where('distributor_id', $distributorId)
            ->where('cutoff_date', $date->toDateString())
            ->first();

        if ($existing !== null && $existing->status === GsbCutoffResult::STATUS_CREDITED) {
            return $existing;
        }

        $distributor = Distributor::findOrFail($distributorId);

        // Eligibility gate: 600 BV minimum personal purchase.
        $personalBvPaise = $this->bvLedger->totalPersonalBvPaise($distributorId);
        if ($personalBvPaise < self::MIN_PERSONAL_BV_PAISE) {
            return $this->saveResult($existing, [
                'distributor_id' => $distributorId,
                'cutoff_date' => $date->toDateString(),
                'status' => GsbCutoffResult::STATUS_BELOW_600BV,
            ]);
        }

        $title = $this->titleService->forBvPaise($personalBvPaise);

        // Today's accumulated group BV (may be 0 if no orders in their group today).
        $dailyBv = GroupBvDaily::where('distributor_id', $distributorId)
            ->where('date', $date->toDateString())
            ->first();

        $leftToday  = $dailyBv?->left_bv_paise ?? 0;
        $rightToday = $dailyBv?->right_bv_paise ?? 0;

        // Carry-forward state (create row if this distributor's first cut-off).
        $cf = GsbCarryforward::firstOrCreate(
            ['distributor_id' => $distributorId],
            ['power_side_bv_paise' => 0, 'power_side' => null, 'slab1_weaker_bv_paise' => 0],
        );

        // Add power CF to the side it belongs to.
        $leftEffective  = $leftToday  + ($cf->power_side === 'L' ? $cf->power_side_bv_paise : 0);
        $rightEffective = $rightToday + ($cf->power_side === 'R' ? $cf->power_side_bv_paise : 0);

        if ($leftEffective >= $rightEffective) {
            $strongerSide = 'L';
            $strongerEffective = $leftEffective;
            $weakerEffective = $rightEffective;
        } else {
            $strongerSide = 'R';
            $strongerEffective = $rightEffective;
            $weakerEffective = $leftEffective;
        }

        // Add slab-1 carry-forward to weaker side for matching purposes.
        $weakerTotal = $weakerEffective + $cf->slab1_weaker_bv_paise;

        // Find the highest matching slab, capped by personal title.
        $matchedSlab = null;
        foreach (array_reverse(self::SLABS, preserve_keys: true) as $slabIndex => [$threshold, $incentive]) {
            if ($slabIndex <= $title->maxGsbSlab && $weakerTotal >= $threshold) {
                $matchedSlab = ['index' => $slabIndex, 'threshold' => $threshold, 'incentive' => $incentive];
                break;
            }
        }

        if ($matchedSlab === null) {
            // No match — update carry-forward: weaker accumulates for slab 1, power carries forward.
            $newPowerCf = min($strongerEffective, self::POWER_CF_CAP_PAISE);
            $newSlab1Cf = $weakerTotal;  // accumulates until 15K matched

            $cf->update([
                'power_side_bv_paise'   => $newPowerCf,
                'power_side'             => $strongerSide,
                'slab1_weaker_bv_paise' => $newSlab1Cf,
            ]);

            return $this->saveResult($existing, [
                'distributor_id' => $distributorId,
                'cutoff_date' => $date->toDateString(),
                'left_bv_paise' => $leftToday,
                'right_bv_paise' => $rightToday,
                'weaker_bv_paise' => $weakerTotal,
                'power_cf_before_paise' => $cf->getOriginal('power_side_bv_paise') ?? 0,
                'power_cf_after_paise' => $newPowerCf,
                'power_side_after' => $strongerSide,
                'slab1_weaker_cf_before_paise' => $cf->getOriginal('slab1_weaker_bv_paise') ?? 0,
                'slab1_weaker_cf_after_paise' => $newSlab1Cf,
                'status' => GsbCutoffResult::STATUS_NO_MATCH,
            ]);
        }

        // Slab matched — compute deductions.
        $gross = $matchedSlab['incentive'];
        $adminCharge = (int) min(round($gross * 0.03), self::MAX_ADMIN_CHARGE_PAISE);
        $tds = (int) round(($gross - $adminCharge) * 0.05);
        $net = $gross - $adminCharge - $tds;

        $newPowerCf = min(
            max(0, $strongerEffective - $matchedSlab['threshold']),
            self::POWER_CF_CAP_PAISE
        );

        $cfBeforePower  = $cf->power_side_bv_paise;
        $cfBeforeSlab1  = $cf->slab1_weaker_bv_paise;

        $cf->update([
            'power_side_bv_paise'   => $newPowerCf,
            'power_side'             => $strongerSide,
            'slab1_weaker_bv_paise' => 0,  // reset — weaker side was matched
        ]);

        $baseData = [
            'distributor_id' => $distributorId,
            'cutoff_date' => $date->toDateString(),
            'left_bv_paise' => $leftToday,
            'right_bv_paise' => $rightToday,
            'weaker_bv_paise' => $weakerTotal,
            'slab' => $matchedSlab['index'],
            'gross_gsb_paise' => $gross,
            'admin_charge_paise' => $adminCharge,
            'tds_paise' => $tds,
            'net_gsb_paise' => $net,
            'power_cf_before_paise' => $cfBeforePower,
            'power_cf_after_paise' => $newPowerCf,
            'power_side_after' => $strongerSide,
            'slab1_weaker_cf_before_paise' => $cfBeforeSlab1,
            'slab1_weaker_cf_after_paise' => 0,
        ];

        // Frozen distributors: calculate but do not credit wallet.
        if ($distributor->gsb_frozen_at !== null) {
            return $this->saveResult($existing, [
                ...$baseData,
                'status' => GsbCutoffResult::STATUS_FROZEN,
            ]);
        }

        // Credit wallet.
        try {
            DB::transaction(function () use ($distributorId, $net, &$baseData): void {
                $savedResult = $this->saveResult(null, [
                    ...$baseData,
                    'status' => GsbCutoffResult::STATUS_CALCULATED,
                ]);

                $this->wallet->credit(
                    distributorId: $distributorId,
                    amountPaise: $net,
                    type: 'gsb_credit',
                    referenceId: $savedResult->id,
                    referenceType: 'gsb_cutoff_result',
                );

                $savedResult->update(['status' => GsbCutoffResult::STATUS_CREDITED]);
                $baseData['_result_id'] = $savedResult->id;
            });
        } catch (Throwable $e) {
            return $this->saveResult($existing, [
                ...$baseData,
                'status' => GsbCutoffResult::STATUS_FAILED,
                'failure_reason' => $e->getMessage(),
            ]);
        }

        return GsbCutoffResult::where('distributor_id', $distributorId)
            ->where('cutoff_date', $date->toDateString())
            ->firstOrFail();
    }

    private function saveResult(?GsbCutoffResult $existing, array $data): GsbCutoffResult
    {
        if ($existing !== null) {
            $existing->fill($data)->save();
            return $existing->fresh();
        }
        return GsbCutoffResult::create($data);
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact --filter=GsbCutoffServiceTest
```

Expected: All 7 PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add app/Modules/Compensation/Services/GsbCutoffService.php tests/Modules/Compensation/GsbCutoffServiceTest.php
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): GsbCutoffService — daily 23:59 slab matching, deductions, wallet credit, carry-forward"
```

---

## Task 7: `MentorshipBonusService`

**Files:**
- Create: `app/app/Modules/Compensation/Services/MentorshipBonusService.php`
- Test: `app/tests/Modules/Compensation/MentorshipBonusServiceTest.php`

Rules:
- Sponsor earns % of each directly-sponsored distributee's (sponsee's) GSB
- Rate starts at 10%, drops 1% per ₹30,000 cumulative GSB from that sponsee, floors at 1%
- Rate is per sponsor-sponsee pair, tracked independently

- [ ] **Step 1: Write failing tests**

```php
// tests/Modules/Compensation/MentorshipBonusServiceTest.php
<?php
declare(strict_types=1);

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Services\MentorshipBonusService;
use App\Modules\Identity\Models\Distributor;

uses(\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeSponsorship(Distributor $sponsor, Distributor $sponsee): void
{
    \Illuminate\Support\Facades\DB::table('sponsorship')->insert([
        'sponsor_id' => $sponsor->id,
        'distributor_id' => $sponsee->id,
        'created_at' => now(),
    ]);
}

it('credits sponsor with 10% of sponsee GSB when sponsee cumulative < 30K GSB', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);

    $cutoffResult = GsbCutoffResult::create([
        'distributor_id' => $sponsee->id,
        'cutoff_date' => today()->toDateString(),
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => 1, 'gross_gsb_paise' => 100_000,
        'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_gsb_paise' => 100_000,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => 'credited',
    ]);

    $svc = app(MentorshipBonusService::class);
    $mb = $svc->processForSponsee($sponsee->id, $cutoffResult);

    expect($mb)->not->toBeNull();
    expect($mb->mb_rate_pct)->toBe(10);
    expect($mb->mb_paise)->toBe(10_000);  // 10% of 100,000
    expect($mb->status)->toBe('credited');

    // Sponsor wallet should have received 10,000 paise
    expect(\App\Modules\Compensation\Models\WalletLedgerEntry::where('distributor_id', $sponsor->id)->sum('amount_paise'))->toBe(10_000);
});

it('steps down MB rate after each 30K cumulative GSB milestone', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);

    // Sponsee has already earned 60K cumulative GSB → rate should be 8% (10 - 2 steps)
    // Simulate prior MB results that total to 60K sponsee GSB
    MentorshipBonusResult::create([
        'sponsor_id' => $sponsor->id, 'sponsee_id' => $sponsee->id,
        'cutoff_date' => today()->subDays(2)->toDateString(),
        'sponsee_gsb_paise' => 3_000_000, 'mb_rate_pct' => 10, 'mb_paise' => 300_000,
        'sponsee_cumulative_gsb_paise' => 3_000_000, 'status' => 'credited',
    ]);
    MentorshipBonusResult::create([
        'sponsor_id' => $sponsor->id, 'sponsee_id' => $sponsee->id,
        'cutoff_date' => today()->subDay()->toDateString(),
        'sponsee_gsb_paise' => 3_000_000, 'mb_rate_pct' => 9, 'mb_paise' => 270_000,
        'sponsee_cumulative_gsb_paise' => 6_000_000, 'status' => 'credited',
    ]);

    $cutoffResult = GsbCutoffResult::create([
        'distributor_id' => $sponsee->id,
        'cutoff_date' => today()->toDateString(),
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => 1, 'gross_gsb_paise' => 100_000,
        'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_gsb_paise' => 100_000,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => 'credited',
    ]);

    $svc = app(MentorshipBonusService::class);
    $mb = $svc->processForSponsee($sponsee->id, $cutoffResult);

    expect($mb->mb_rate_pct)->toBe(8);  // 10 - 2 steps (60K = 2 × 30K milestones)
    expect($mb->mb_paise)->toBe(8_000);
});

it('floors MB rate at 1%', function () {
    $sponsor = Distributor::factory()->create();
    $sponsee = Distributor::factory()->create();
    makeSponsorship($sponsor, $sponsee);

    // Sponsee cumulative = 270K GSB (9 × 30K milestones → rate = max(10-9, 1) = 1%)
    MentorshipBonusResult::create([
        'sponsor_id' => $sponsor->id, 'sponsee_id' => $sponsee->id,
        'cutoff_date' => today()->subDay()->toDateString(),
        'sponsee_gsb_paise' => 27_000_000, 'mb_rate_pct' => 1, 'mb_paise' => 270_000,
        'sponsee_cumulative_gsb_paise' => 27_000_000, 'status' => 'credited',
    ]);

    $cutoffResult = GsbCutoffResult::create([
        'distributor_id' => $sponsee->id,
        'cutoff_date' => today()->toDateString(),
        'left_bv_paise' => 0, 'right_bv_paise' => 0, 'weaker_bv_paise' => 0,
        'slab' => 1, 'gross_gsb_paise' => 100_000,
        'admin_charge_paise' => 0, 'tds_paise' => 0, 'net_gsb_paise' => 100_000,
        'power_cf_before_paise' => 0, 'power_cf_after_paise' => 0,
        'slab1_weaker_cf_before_paise' => 0, 'slab1_weaker_cf_after_paise' => 0,
        'status' => 'credited',
    ]);

    $svc = app(MentorshipBonusService::class);
    $mb = $svc->processForSponsee($sponsee->id, $cutoffResult);

    expect($mb->mb_rate_pct)->toBe(1);
    expect($mb->mb_paise)->toBe(1_000);
});
```

- [ ] **Step 2: Run to verify they fail**

```bash
php artisan test --compact --filter=MentorshipBonusServiceTest
```

- [ ] **Step 3: Implement `MentorshipBonusService`**

```php
// app/Modules/Compensation/Services/MentorshipBonusService.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use Illuminate\Support\Facades\DB;

/**
 * Computes and credits the Mentorship Bonus for a sponsee's GSB cut-off result.
 *
 * Rate ladder: starts at 10%, drops 1% per ₹30,000 (3,000,000 paise) of
 * cumulative GSB earned by the sponsee, floors at 1% permanently.
 * Each sponsor-sponsee pair is tracked independently.
 */
final class MentorshipBonusService
{
    /** Rate step: ₹30,000 cumulative GSB = 3,000,000 paise per rate decrement. */
    private const STEP_PAISE = 3_000_000;

    public function __construct(private readonly WalletService $wallet) {}

    /**
     * Compute and credit MB to the sponsor of $sponseeId for today's cut-off.
     * Returns null if the sponsee has no sponsor, or the sponsee did not earn GSB.
     */
    public function processForSponsee(int $sponseeId, GsbCutoffResult $cutoffResult): ?MentorshipBonusResult
    {
        if ($cutoffResult->status !== GsbCutoffResult::STATUS_CREDITED) {
            return null;
        }

        // Look up the sponsee's sponsor.
        $sponsorId = DB::table('sponsorship')
            ->where('distributor_id', $sponseeId)
            ->value('sponsor_id');

        if ($sponsorId === null) {
            return null;
        }

        // Cumulative sponsee GSB seen by this sponsor-sponsee pair (from previous MB results).
        $prevCumulative = (int) MentorshipBonusResult::where('sponsor_id', $sponsorId)
            ->where('sponsee_id', $sponseeId)
            ->max('sponsee_cumulative_gsb_paise') ?? 0;

        $newCumulative = $prevCumulative + $cutoffResult->gross_gsb_paise;

        // Rate = 10% - (floor(cumulative / 30K step) × 1%), minimum 1%.
        $stepsCompleted = (int) floor($prevCumulative / self::STEP_PAISE);
        $rate = max(1, 10 - $stepsCompleted);

        $mbPaise = (int) round($cutoffResult->gross_gsb_paise * $rate / 100);

        $result = MentorshipBonusResult::create([
            'sponsor_id' => $sponsorId,
            'sponsee_id' => $sponseeId,
            'cutoff_date' => $cutoffResult->cutoff_date->toDateString(),
            'sponsee_gsb_paise' => $cutoffResult->gross_gsb_paise,
            'mb_rate_pct' => $rate,
            'mb_paise' => $mbPaise,
            'sponsee_cumulative_gsb_paise' => $newCumulative,
            'status' => 'credited',
        ]);

        $this->wallet->credit(
            distributorId: (int) $sponsorId,
            amountPaise: $mbPaise,
            type: 'mb_credit',
            referenceId: $result->id,
            referenceType: 'mentorship_bonus_result',
        );

        return $result;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact --filter=MentorshipBonusServiceTest
```

Expected: All 3 PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add app/Modules/Compensation/Services/MentorshipBonusService.php tests/Modules/Compensation/MentorshipBonusServiceTest.php
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): MentorshipBonusService — sponsor MB at 10%→1% step-down on sponsee cumulative GSB"
```

---

## Task 8: `GsbDailyCutoffCommand`

**Files:**
- Create: `app/app/Modules/Compensation/Console/Commands/GsbDailyCutoffCommand.php`
- Modify: `app/routes/console.php`

This command is scheduled at 23:59 daily. It processes ALL eligible distributors.

- [ ] **Step 1: Implement the command**

```php
// app/Modules/Compensation/Console/Commands/GsbDailyCutoffCommand.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Services\GsbCutoffService;
use App\Modules\Compensation\Services\MentorshipBonusService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class GsbDailyCutoffCommand extends Command
{
    protected $signature = 'gsb:daily-cutoff
                            {--date= : Override the cut-off date (YYYY-MM-DD, default: today)}
                            {--distributor= : Run for a single distributor ID only (admin retry)}';

    protected $description = 'Run the 23:59 GSB cut-off for all active distributors';

    public function __construct(
        private readonly GsbCutoffService $cutoff,
        private readonly MentorshipBonusService $mentorship,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::today();

        $singleId = $this->option('distributor')
            ? (int) $this->option('distributor')
            : null;

        $this->info("GSB daily cut-off — {$date->toDateString()}");

        $query = Distributor::query()
            ->whereNotNull('adn')
            ->where('status', 'active');

        if ($singleId !== null) {
            $query->where('id', $singleId);
        }

        $distributors = $query->pluck('id');
        $total = $distributors->count();
        $credited = 0;
        $failed = 0;

        foreach ($distributors as $distributorId) {
            try {
                $result = $this->cutoff->runForDistributor((int) $distributorId, $date);

                if ($result->status === GsbCutoffResult::STATUS_CREDITED) {
                    $credited++;
                    // Also compute mentorship bonus for the sponsee's sponsor.
                    $this->mentorship->processForSponsee((int) $distributorId, $result);
                } elseif ($result->status === GsbCutoffResult::STATUS_FAILED) {
                    $failed++;
                    Log::error('gsb.cutoff.failed', ['distributor_id' => $distributorId, 'reason' => $result->failure_reason]);
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('gsb.cutoff.exception', ['distributor_id' => $distributorId, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Done — total: {$total}, credited: {$credited}, failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
```

- [ ] **Step 2: Register command and schedule in `console.php`**

Open `app/routes/console.php` and add:

```php
use App\Modules\Compensation\Console\Commands\GsbDailyCutoffCommand;
use App\Modules\Compensation\Console\Commands\GsbWeeklyPayoutCommand;
use Illuminate\Support\Facades\Schedule;

// Daily cut-off at 23:59 IST. withoutOverlapping prevents concurrent runs.
Schedule::command(GsbDailyCutoffCommand::class)
    ->dailyAt('23:59')
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();

// Weekly payout every Tuesday at 09:00 IST.
Schedule::command(GsbWeeklyPayoutCommand::class)
    ->weeklyOn(2, '09:00')   // 2 = Tuesday
    ->timezone('Asia/Kolkata')
    ->withoutOverlapping()
    ->runInBackground();
```

Also register the commands in `AppServiceProvider.php`'s `boot()`:

```php
$this->commands([
    \App\Modules\Compensation\Console\Commands\GsbDailyCutoffCommand::class,
    \App\Modules\Compensation\Console\Commands\GsbWeeklyPayoutCommand::class,
]);
```

- [ ] **Step 3: Verify command is discoverable**

```bash
cd /Users/preetham/Documents/arovolife/arovolife/arovolife-code/app
php artisan list | grep gsb
```

Expected: `gsb:daily-cutoff` and `gsb:weekly-payout` listed.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add \
  app/Modules/Compensation/ \
  app/Providers/AppServiceProvider.php \
  routes/console.php
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): gsb:daily-cutoff command + schedule — 23:59 cut-off with MB propagation"
```

---

## Task 9: `GsbWeeklyPayoutCommand` + `PayoutService`

**Files:**
- Create: `app/app/Modules/Compensation/Services/PayoutService.php`
- Create: `app/app/Modules/Compensation/Console/Commands/GsbWeeklyPayoutCommand.php`

Rules:
- Minimum payout: ₹500 = 50,000 paise. Below-minimum wallets roll over.
- Repurchase deduction: 10% of prior month's GSB + MB, capped at ₹10,000 = 1,000,000 paise.
- Bank transfer is a placeholder in Phase 4 (marks as 'transferred' after stub call).

- [ ] **Step 1: Implement `PayoutService`**

```php
// app/Modules/Compensation/Services/PayoutService.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PayoutService
{
    /** Minimum payout threshold: ₹500 = 50,000 paise. */
    private const MIN_PAYOUT_PAISE = 50_000;

    /** Max repurchase deduction: ₹10,000 = 1,000,000 paise. */
    private const MAX_REPURCHASE_PAISE = 1_000_000;

    public function __construct(private readonly WalletService $wallet) {}

    public function runBatch(Carbon $batchDate): PayoutBatch
    {
        // One batch per date.
        $batch = PayoutBatch::firstOrCreate(
            ['batch_date' => $batchDate->toDateString()],
            ['status' => PayoutBatch::STATUS_PENDING],
        );

        if ($batch->status === PayoutBatch::STATUS_COMPLETED) {
            return $batch;
        }

        $batch->update(['status' => PayoutBatch::STATUS_PROCESSING]);

        $distributors = Distributor::query()
            ->whereNotNull('adn')
            ->where('status', 'active')
            ->pluck('id');

        $totalGross = 0;
        $totalDeductions = 0;
        $totalNet = 0;
        $count = 0;

        foreach ($distributors as $distributorId) {
            $balance = $this->wallet->balancePaise((int) $distributorId);
            if ($balance <= 0) {
                continue;
            }

            $repurchase = $this->repurchaseDeductionPaise((int) $distributorId, $batchDate);
            $net = $balance - $repurchase;

            $lineStatus = $net < self::MIN_PAYOUT_PAISE
                ? PayoutLineItem::STATUS_BELOW_MINIMUM ?? 'below_minimum'
                : 'pending';

            $line = PayoutLineItem::create([
                'payout_batch_id' => $batch->id,
                'distributor_id' => $distributorId,
                'wallet_balance_paise' => $balance,
                'repurchase_deduction_paise' => $repurchase,
                'net_transferred_paise' => max(0, $net),
                'status' => $lineStatus,
            ]);

            if ($lineStatus === 'pending') {
                // Phase 4 stub: mark as transferred immediately.
                // Phase 5 will integrate a real bank transfer API with UTR.
                $this->wallet->debit(
                    distributorId: (int) $distributorId,
                    amountPaise: $balance,
                    type: 'payout_debit',
                    referenceId: $line->id,
                    referenceType: 'payout_line_item',
                );
                if ($repurchase > 0) {
                    $this->wallet->credit(
                        distributorId: (int) $distributorId,
                        amountPaise: $repurchase,
                        type: 'repurchase_deduction',
                        referenceId: $line->id,
                        referenceType: 'payout_line_item',
                    );
                }
                $line->update(['status' => 'transferred']);

                $totalGross += $balance;
                $totalDeductions += $repurchase;
                $totalNet += $net;
                $count++;
            }
        }

        $batch->update([
            'status' => PayoutBatch::STATUS_COMPLETED,
            'total_gross_paise' => $totalGross,
            'total_deductions_paise' => $totalDeductions,
            'total_net_paise' => $totalNet,
            'distributor_count' => $count,
            'processed_at' => now(),
        ]);

        return $batch;
    }

    /** 10% of prior month's GSB + MB net credits, capped at ₹10,000. */
    private function repurchaseDeductionPaise(int $distributorId, Carbon $batchDate): int
    {
        $priorMonthStart = $batchDate->copy()->subMonth()->startOfMonth();
        $priorMonthEnd   = $batchDate->copy()->subMonth()->endOfMonth();

        $earned = (int) WalletLedgerEntry::where('distributor_id', $distributorId)
            ->whereIn('type', ['gsb_credit', 'mb_credit'])
            ->whereBetween('created_at', [$priorMonthStart, $priorMonthEnd])
            ->sum('amount_paise');

        return min((int) round($earned * 0.10), self::MAX_REPURCHASE_PAISE);
    }
}
```

- [ ] **Step 2: Implement `GsbWeeklyPayoutCommand`**

```php
// app/Modules/Compensation/Console/Commands/GsbWeeklyPayoutCommand.php
<?php
declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\PayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class GsbWeeklyPayoutCommand extends Command
{
    protected $signature = 'gsb:weekly-payout
                            {--date= : Batch date override (YYYY-MM-DD, default: today)}
                            {--distributor= : Force-payout for a single distributor ID (admin use)}';

    protected $description = 'Run the Tuesday weekly payout batch for all eligible wallets';

    public function __construct(private readonly PayoutService $payoutService) {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::today();

        $this->info("GSB weekly payout — {$date->toDateString()}");
        $batch = $this->payoutService->runBatch($date);
        $this->info("Batch #{$batch->id} {$batch->status} — {$batch->distributor_count} distributors, net ₹".number_format($batch->total_net_paise / 100, 2));

        return $batch->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
```

- [ ] **Step 3: Verify commands are listed**

```bash
php artisan list | grep gsb
```

Expected: both `gsb:daily-cutoff` and `gsb:weekly-payout` listed.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add app/Modules/Compensation/Services/PayoutService.php app/Modules/Compensation/Console/
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "feat(compensation): PayoutService + gsb:weekly-payout command — Tuesday batch with repurchase deduction"
```

---

## Task 10: Run full Compensation test suite

- [ ] **Step 1: Run all new Compensation tests**

```bash
cd /Users/preetham/Documents/arovolife/arovolife/arovolife-code/app
php artisan test --compact tests/Modules/Compensation/
```

Expected: All tests PASS.

- [ ] **Step 2: Run the full test suite to check for regressions**

```bash
php artisan test --compact
```

Expected: All tests PASS. No regressions.

- [ ] **Step 3: Final backend commit**

```bash
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code add .
git -C /Users/preetham/Documents/arovolife/arovolife/arovolife-code commit -m "chore(compensation): finalize Phase 4 backend — all services, jobs, commands tested"
```

---

## Self-review

**Spec coverage check:**
- [x] Group BV accumulation → `GroupBvAccumulatorService` + `PropagateGroupBvJob`
- [x] Daily cut-off algorithm → `GsbCutoffService` with slab matching + deductions + CF
- [x] Carry-forward (power side + slab-1 weaker) → `gsb_carryforward` table + service
- [x] Mentorship Bonus step-down → `MentorshipBonusService`
- [x] Double-entry wallet → `WalletService` + `wallet_ledger_entries`
- [x] Weekly payout with repurchase deduction → `PayoutService`
- [x] Freeze GSB → `gsb_frozen_at` column on `distributors`; service checks it
- [x] Below-600 BV gate → checked in `GsbCutoffService`
- [x] Idempotency (retry = safe) → `GsbCutoffService` returns existing credited result
- [x] Audit logging — MISSING: all admin manual actions must write to `audit_log`. Added in the UI plans (Admin Manual Controls task).
- [x] `payoutService.runBatch()` is idempotent (uses `firstOrCreate` + status check)

**Placeholder scan:** None found.

**Type consistency:** `GsbCutoffResult::STATUS_*` constants used consistently; `WalletService::credit/debit` signatures consistent throughout.
