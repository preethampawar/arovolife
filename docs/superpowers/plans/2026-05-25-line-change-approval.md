# Line-Change Request → Admin Approval → Placement Move — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reframe the existing line-change feature from a *sponsor* change to a *binary-placement* change, and add the missing admin approve/reject workflow that actually executes the placement move.

**Architecture:** A distributor submits a request (target placement-parent ADN only) within 5 business days of joining, while a leaf, at most once ever. An admin reviews it on a new `/admin/line-changes` queue, picks the side, and approves — which moves `placement_parent_id`/`placement_side`, recomputes `depth`, and rebuilds the requester's closure rows. The sponsor link is never touched. Request/approve/reject each emit a domain event whose queued listener sends email.

**Tech Stack:** Laravel 13, PHP 8.4, Pest, MySQL (prod) / SQLite (test), Spatie laravel-permission, Tailwind Blade, database queue, closure-table genealogy.

**Spec:** `docs/superpowers/specs/2026-05-25-line-change-approval-design.md`

**Conventions to honor every task:** `declare(strict_types=1);`, `final` service classes with constructor-promoted deps, `$fillable` (never `guarded=[]`), one concern per migration, audit-log every admin action, never log PII, Conventional Commits, and `Compliance-Review:` trailer on commits touching the tree/placement. Run `vendor/bin/pint --dirty` before each commit. All commands run from the Laravel root: `cd /Users/preetham/Documents/arovolife/arovolife/arovolife-code/app`.

---

## File Structure

**Create:**
- `app/Modules/Genealogy/Database/Migrations/2026_05_25_000001_reframe_line_change_to_placement.php` — rename sponsor cols → placement cols; add `chosen_side`, `reviewed_by`, `reviewed_at`, `decision_note`.
- `app/Modules/Genealogy/Services/Exceptions/LineChangeNewParentTooNewError.php`
- `app/Modules/Genealogy/Services/Exceptions/LineChangeAlreadyProcessedError.php`
- `app/Modules/Genealogy/Services/Exceptions/LineChangePlacementSlotFullError.php`
- `app/Modules/Genealogy/Services/ApproveLineChange.php`
- `app/Modules/Genealogy/Services/RejectLineChange.php`
- `app/Modules/Genealogy/Events/LineChangeApproved.php`
- `app/Modules/Genealogy/Events/LineChangeRejected.php`
- `app/Modules/Genealogy/Notifications/LineChangeRequestedAdminNotification.php`
- `app/Modules/Genealogy/Notifications/LineChangeRequestedRequesterNotification.php`
- `app/Modules/Genealogy/Notifications/LineChangeApprovedNotification.php`
- `app/Modules/Genealogy/Notifications/LineChangeRejectedNotification.php`
- `app/Modules/Genealogy/Listeners/SendLineChangeRequestedMails.php`
- `app/Modules/Genealogy/Listeners/SendLineChangeDecidedMails.php`
- `app/Modules/Admin/Http/Controllers/AdminLineChangeController.php`
- `resources/views/admin/line-change/index.blade.php`
- `resources/views/admin/line-change/show.blade.php`
- `resources/views/components/help-tip.blade.php` — reusable info-icon + hover tooltip (platform convention).
- `resources/views/components/confirm-modal.blade.php` — reusable confirmation modal for actionable buttons (platform convention).
- `resources/views/emails/line-change-requested-admin.blade.php`
- `resources/views/emails/line-change-requested-requester.blade.php`
- `resources/views/emails/line-change-approved.blade.php`
- `resources/views/emails/line-change-rejected.blade.php`
- `tests/Modules/Genealogy/ApproveLineChangeTest.php`
- `tests/Modules/Genealogy/RejectLineChangeTest.php`
- `tests/Modules/Admin/AdminLineChangeControllerTest.php`

**Modify:**
- `app/Modules/Genealogy/Models/LineChangeRequest.php` — placement-named cols, casts, relationships.
- `app/Modules/Genealogy/Services/RequestLineChange.php` — placement terms + one-change rule + slot check.
- `app/Modules/Genealogy/Events/LineChangeRequested.php` — rename fields to placement.
- `app/Modules/Genealogy/Services/Exceptions/LineChangeNewSponsorTooNewError.php` — delete (renamed).
- `app/Modules/Genealogy/Http/Controllers/LineChangeController.php` — placement terms, already-used guard.
- `resources/views/genealogy/line-change.blade.php` — placement copy, form note, help tips, confirm modal, approved/rejected/used states.
- `app/Modules/Admin/Support/AdminNotificationRecipients.php` — add `lineChangeReviewers()`.
- `routes/web.php` — add admin line-change routes.
- `tests/Modules/Genealogy/LineChangeRequestTest.php` — rename + new-rule coverage.

---

## Task 1: Migration — reframe schema to placement

**Files:**
- Create: `app/Modules/Genealogy/Database/Migrations/2026_05_25_000001_reframe_line_change_to_placement.php`
- Test: `tests/Modules/Genealogy/LineChangeSchemaTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Modules/Genealogy/LineChangeSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('line_change_requests has placement-named columns', function () {
    expect(Schema::hasColumn('line_change_requests', 'from_placement_parent_id'))->toBeTrue();
    expect(Schema::hasColumn('line_change_requests', 'to_placement_parent_id'))->toBeTrue();
    expect(Schema::hasColumn('line_change_requests', 'chosen_side'))->toBeTrue();
    expect(Schema::hasColumn('line_change_requests', 'reviewed_by'))->toBeTrue();
    expect(Schema::hasColumn('line_change_requests', 'reviewed_at'))->toBeTrue();
    expect(Schema::hasColumn('line_change_requests', 'decision_note'))->toBeTrue();
    expect(Schema::hasColumn('line_change_requests', 'from_sponsor_id'))->toBeFalse();
    expect(Schema::hasColumn('line_change_requests', 'to_sponsor_id'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Modules/Genealogy/LineChangeSchemaTest.php`
Expected: FAIL — `from_placement_parent_id` does not exist / `from_sponsor_id` still exists.

- [ ] **Step 3: Write the migration**

Create `app/Modules/Genealogy/Database/Migrations/2026_05_25_000001_reframe_line_change_to_placement.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reframe line-change from a sponsor change to a binary-placement change
 * (spec 2026-05-25). The columns named "sponsor" actually only ever
 * recorded the binary placement target; rename them to match reality and
 * add the admin-review fields the approval workflow needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Additive columns first (separate closure so SQLite's renameColumn
        // table-rebuild in the next block sees a stable shape).
        Schema::table('line_change_requests', function (Blueprint $table): void {
            // 'after' names the pre-rename column; the rename closure below
            // shifts it to 'to_placement_parent_id'. Final order is correct.
            $table->enum('chosen_side', ['L', 'R'])->nullable()->after('to_sponsor_id');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('approved_at');
            $table->dateTime('reviewed_at', 3)->nullable()->after('reviewed_by');
            $table->string('decision_note', 1024)->nullable()->after('reviewed_at');

            // restrictOnDelete preserves the reviewer audit trail and matches
            // the table's existing from/to FK convention.
            $table->foreign('reviewed_by', 'fk_lcr_reviewer')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('line_change_requests', function (Blueprint $table): void {
            $table->renameColumn('from_sponsor_id', 'from_placement_parent_id');
            $table->renameColumn('to_sponsor_id', 'to_placement_parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('line_change_requests', function (Blueprint $table): void {
            $table->renameColumn('from_placement_parent_id', 'from_sponsor_id');
            $table->renameColumn('to_placement_parent_id', 'to_sponsor_id');
        });

        Schema::table('line_change_requests', function (Blueprint $table): void {
            $table->dropForeign('fk_lcr_reviewer');
            $table->dropColumn(['chosen_side', 'reviewed_by', 'reviewed_at', 'decision_note']);
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Modules/Genealogy/LineChangeSchemaTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Modules/Genealogy/Database/Migrations/2026_05_25_000001_reframe_line_change_to_placement.php tests/Modules/Genealogy/LineChangeSchemaTest.php
git commit -m "$(cat <<'EOF'
feat(genealogy): reframe line_change_requests schema to binary placement

Rename sponsor columns to placement, add admin-review fields
(chosen_side, reviewed_by, reviewed_at, decision_note).

Compliance-Review: pending
EOF
)"
```

---

## Task 2: Update LineChangeRequest model

**Files:**
- Modify: `app/Modules/Genealogy/Models/LineChangeRequest.php`

- [ ] **Step 1: Replace the model body**

Replace the whole file with:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Models;

use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $distributor_id
 * @property int $from_placement_parent_id
 * @property int $to_placement_parent_id
 * @property string|null $chosen_side
 * @property Carbon $requested_at
 * @property Carbon|null $approved_at
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string $status
 * @property string|null $reason
 * @property string|null $decision_note
 * @property-read Distributor $distributor
 * @property-read Distributor $fromPlacementParent
 * @property-read Distributor $toPlacementParent
 * @property-read User|null $reviewer
 */
final class LineChangeRequest extends Model
{
    public $timestamps = false;

    protected $table = 'line_change_requests';

    protected $fillable = [
        'distributor_id',
        'from_placement_parent_id',
        'to_placement_parent_id',
        'chosen_side',
        'requested_at',
        'approved_at',
        'reviewed_by',
        'reviewed_at',
        'status',
        'reason',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id');
    }

    public function fromPlacementParent(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'from_placement_parent_id');
    }

    public function toPlacementParent(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'to_placement_parent_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
```

- [ ] **Step 2: Lint**

Run: `vendor/bin/pint --dirty` then `php -l app/Modules/Genealogy/Models/LineChangeRequest.php`
Expected: no syntax errors.

- [ ] **Step 3: Commit**

```bash
git add app/Modules/Genealogy/Models/LineChangeRequest.php
git commit -m "refactor(genealogy): LineChangeRequest model uses placement columns + reviewer"
```

---

## Task 3: Exceptions (rename one, add three)

**Files:**
- Create: `app/Modules/Genealogy/Services/Exceptions/LineChangeNewParentTooNewError.php`
- Create: `app/Modules/Genealogy/Services/Exceptions/LineChangeAlreadyProcessedError.php`
- Create: `app/Modules/Genealogy/Services/Exceptions/LineChangePlacementSlotFullError.php`
- Delete: `app/Modules/Genealogy/Services/Exceptions/LineChangeNewSponsorTooNewError.php`

- [ ] **Step 1: Create the three new exception classes**

`LineChangeNewParentTooNewError.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services\Exceptions;

use RuntimeException;

final class LineChangeNewParentTooNewError extends RuntimeException {}
```

`LineChangeAlreadyProcessedError.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services\Exceptions;

use RuntimeException;

final class LineChangeAlreadyProcessedError extends RuntimeException {}
```

`LineChangePlacementSlotFullError.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services\Exceptions;

use RuntimeException;

final class LineChangePlacementSlotFullError extends RuntimeException {}
```

- [ ] **Step 2: Delete the renamed exception**

```bash
git rm app/Modules/Genealogy/Services/Exceptions/LineChangeNewSponsorTooNewError.php
```

- [ ] **Step 3: Commit**

```bash
vendor/bin/pint --dirty
git add app/Modules/Genealogy/Services/Exceptions/
git commit -m "feat(genealogy): line-change exceptions for placement (parent-too-new, already-processed, slot-full)"
```

---

## Task 4: Rework RequestLineChange + LineChangeRequested event + update existing tests

**Files:**
- Modify: `app/Modules/Genealogy/Events/LineChangeRequested.php`
- Modify: `app/Modules/Genealogy/Services/RequestLineChange.php`
- Modify: `tests/Modules/Genealogy/LineChangeRequestTest.php`

- [ ] **Step 1: Update the event (rename fields to placement)**

Replace `app/Modules/Genealogy/Events/LineChangeRequested.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

final class LineChangeRequested
{
    use Dispatchable;

    public function __construct(
        public readonly int $requestId,
        public readonly int $distributorId,
        public readonly int $fromPlacementParentId,
        public readonly int $toPlacementParentId,
        public readonly Carbon $requestedAt,
    ) {}
}
```

- [ ] **Step 2: Update the existing test file (rename + add LCR-08/09)**

In `tests/Modules/Genealogy/LineChangeRequestTest.php`:

1. Update imports: replace
   `use App\Modules\Genealogy\Services\Exceptions\LineChangeNewSponsorTooNewError;`
   with
   ```php
   use App\Modules\Genealogy\Services\Exceptions\LineChangeAlreadyProcessedError;
   use App\Modules\Genealogy\Services\Exceptions\LineChangeNewParentTooNewError;
   use App\Modules\Genealogy\Services\Exceptions\LineChangePlacementSlotFullError;
   ```

2. Replace every `toSponsorId:` argument label with `toPlacementParentId:`.

3. In LCR-01 replace the assertions block:
   ```php
   expect($row->status)->toBe('pending')
       ->and($row->from_sponsor_id)->toBe($rootId)
       ->and($row->to_sponsor_id)->toBe($newSponsorId);
   ```
   with:
   ```php
   expect($row->status)->toBe('pending')
       ->and($row->from_placement_parent_id)->toBe($rootId)
       ->and($row->to_placement_parent_id)->toBe($newSponsorId);
   ```

4. Replace both `LineChangeNewSponsorTooNewError::class` occurrences (LCR-06, LCR-07) with `LineChangeNewParentTooNewError::class`.

5. Append two new tests at the end of the file:

```php
it('LCR-08: cannot request a second line change after one was approved', function () {
    $rootId = lcrSeed(lcrUser('root')->id, effectiveAtBusinessDaysAgo: 30);
    $newParentId = lcrSeed(lcrUser('newP')->id, effectiveAtBusinessDaysAgo: 10);

    $applicantUser = lcrUser('app');
    $applicantId = lcrSeed($applicantUser->id, effectiveAtBusinessDaysAgo: 2, sponsorId: $rootId);

    // Simulate a previously approved line change.
    DB::table('line_change_requests')->insert([
        'distributor_id' => $applicantId,
        'from_placement_parent_id' => $rootId,
        'to_placement_parent_id' => $newParentId,
        'requested_at' => now()->subDay()->format('Y-m-d H:i:s.v'),
        'approved_at' => now()->subDay()->format('Y-m-d H:i:s.v'),
        'status' => 'approved',
    ]);

    expect(fn () => app(RequestLineChange::class)(
        distributorId: $applicantId,
        toPlacementParentId: $newParentId,
        actorUserId: $applicantUser->id,
    ))->toThrow(LineChangeAlreadyProcessedError::class);
});

it('LCR-09: rejects when the target parent has no open slot', function () {
    $rootId = lcrSeed(lcrUser('root')->id, effectiveAtBusinessDaysAgo: 30);
    // Target parent joined before applicant; fill both its legs.
    $targetId = lcrSeed(lcrUser('target')->id, effectiveAtBusinessDaysAgo: 20);
    $cL = lcrSeed(lcrUser('cl')->id, effectiveAtBusinessDaysAgo: 15, sponsorId: $targetId);
    $cR = lcrSeed(lcrUser('cr')->id, effectiveAtBusinessDaysAgo: 14, sponsorId: $targetId);
    DB::table('distributors')->where('id', $cL)->update(['placement_side' => 'L']);
    DB::table('distributors')->where('id', $cR)->update(['placement_side' => 'R']);

    $applicantUser = lcrUser('app');
    $applicantId = lcrSeed($applicantUser->id, effectiveAtBusinessDaysAgo: 2, sponsorId: $rootId);

    expect(fn () => app(RequestLineChange::class)(
        distributorId: $applicantId,
        toPlacementParentId: $targetId,
        actorUserId: $applicantUser->id,
    ))->toThrow(LineChangePlacementSlotFullError::class);
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Modules/Genealogy/LineChangeRequestTest.php`
Expected: FAIL — `RequestLineChange` still has `toSponsorId` param / new exceptions never thrown.

- [ ] **Step 4: Rework the service**

Replace `app/Modules/Genealogy/Services/RequestLineChange.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Events\LineChangeRequested;
use App\Modules\Genealogy\Models\GenealogyClosure;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\Exceptions\LineChangeAlreadyProcessedError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeAlreadyRequestedError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeHasDownlineError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeNewParentTooNewError;
use App\Modules\Genealogy\Services\Exceptions\LineChangePlacementSlotFullError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeWindowExpiredError;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * T&C §10: within 5 working days of registration, a leaf distributor may
 * request to move their BINARY PLACEMENT under a different parent. Their
 * sponsor is NOT changed. One approved change per distributor, ever.
 *
 * This service only records the request. ApproveLineChange / RejectLineChange
 * perform the decision and the actual move.
 */
final class RequestLineChange
{
    private const BUSINESS_DAY_WINDOW = 5;

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(
        int $distributorId,
        int $toPlacementParentId,
        int $actorUserId,
        ?string $reason = null,
    ): LineChangeRequest {
        return $this->db->connection()->transaction(function () use ($distributorId, $toPlacementParentId, $actorUserId, $reason): LineChangeRequest {
            /** @var Distributor $distributor */
            $distributor = Distributor::query()->lockForUpdate()->findOrFail($distributorId);

            $now = Carbon::now();

            // 5 working days from effective_date. (int) truncation is
            // deliberate — see LCR-02/LCR-05.
            $businessDaysSince = (int) $distributor->effective_date->diffInWeekdays($now);
            if ($businessDaysSince > self::BUSINESS_DAY_WINDOW) {
                throw new LineChangeWindowExpiredError(
                    "Line-change window for distributor {$distributorId} ended; "
                    ."{$businessDaysSince} business days have elapsed (max ".self::BUSINESS_DAY_WINDOW.').',
                );
            }

            // No downline — keeps the requester a leaf so the move only
            // rewrites their own closure rows.
            $hasDownline = GenealogyClosure::query()
                ->where('ancestor_id', $distributorId)
                ->where('depth', '>=', 1)
                ->exists();
            if ($hasDownline) {
                throw new LineChangeHasDownlineError(
                    "Distributor {$distributorId} has descendants and cannot request a line-change.",
                );
            }

            // One change per distributor, ever: block if any prior request
            // was approved.
            $alreadyApproved = LineChangeRequest::query()
                ->where('distributor_id', $distributorId)
                ->where('status', 'approved')
                ->exists();
            if ($alreadyApproved) {
                throw new LineChangeAlreadyProcessedError(
                    "Distributor {$distributorId} has already used their one line change.",
                );
            }

            // Idempotency: one pending request at a time.
            $existing = LineChangeRequest::query()
                ->where('distributor_id', $distributorId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                throw new LineChangeAlreadyRequestedError(
                    "A pending line-change request (id={$existing->id}) already exists for distributor {$distributorId}.",
                );
            }

            // Target parent must have joined STRICTLY before the requester —
            // the binary tree's parent-older-than-child invariant.
            /** @var Distributor $newParent */
            $newParent = Distributor::query()->lockForUpdate()->findOrFail($toPlacementParentId);
            if (! $newParent->effective_date->lessThan($distributor->effective_date)) {
                throw new LineChangeNewParentTooNewError(
                    "Distributor {$distributorId} (joined {$distributor->effective_date->toDateString()}) "
                    ."cannot move under parent {$toPlacementParentId} (joined {$newParent->effective_date->toDateString()}); "
                    .'the new placement parent must have joined earlier.'
                );
            }

            // Target parent must have at least one open slot at request time.
            if (! $this->hasOpenSlot($toPlacementParentId)) {
                throw new LineChangePlacementSlotFullError(
                    "Target placement parent {$toPlacementParentId} has no open L/R slot.",
                );
            }

            $fromParentId = (int) $distributor->placement_parent_id;

            $request = LineChangeRequest::create([
                'distributor_id' => $distributorId,
                'from_placement_parent_id' => $fromParentId,
                'to_placement_parent_id' => $toPlacementParentId,
                'requested_at' => $now,
                'status' => 'pending',
                'reason' => $reason !== null ? mb_substr($reason, 0, 512) : null,
            ]);

            AuditLog::create([
                'actor_id' => $actorUserId,
                'action' => 'genealogy.line_change.requested',
                'subject_type' => 'distributor',
                'subject_id' => $distributorId,
                'details' => [
                    'request_id' => $request->id,
                    'from_placement_parent_id' => $fromParentId,
                    'to_placement_parent_id' => $toPlacementParentId,
                    'to_parent_effective_date' => $newParent->effective_date->toIso8601String(),
                    'requester_effective_date' => $distributor->effective_date->toIso8601String(),
                    'business_days_since_join' => $businessDaysSince,
                ],
            ]);

            LineChangeRequested::dispatch(
                $request->id,
                $distributorId,
                $fromParentId,
                $toPlacementParentId,
                $now,
            );

            return $request;
        });
    }

    /**
     * True when at least one of parent.L / parent.R is free. Mirrors
     * PlacementEngine::hasOpenSlot (children = rows whose placement_parent_id
     * is this parent, excluding the parent's own root self-reference).
     */
    private function hasOpenSlot(int $parentId): bool
    {
        $taken = $this->db->table('distributors')
            ->where('placement_parent_id', $parentId)
            ->where('id', '!=', $parentId)
            ->whereIn('placement_side', ['L', 'R'])
            ->pluck('placement_side')
            ->all();

        return ! (in_array('L', $taken, true) && in_array('R', $taken, true));
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Modules/Genealogy/LineChangeRequestTest.php`
Expected: PASS (all LCR-01..09).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Modules/Genealogy/Services/RequestLineChange.php app/Modules/Genealogy/Events/LineChangeRequested.php tests/Modules/Genealogy/LineChangeRequestTest.php
git commit -m "$(cat <<'EOF'
feat(genealogy): line-change request targets binary placement, one change per distributor

Sponsor untouched; adds one-change-only and target-slot-open guards.

Compliance-Review: pending
EOF
)"
```

---

## Task 5: ApproveLineChange service + LineChangeApproved event

**Files:**
- Create: `app/Modules/Genealogy/Events/LineChangeApproved.php`
- Create: `app/Modules/Genealogy/Services/ApproveLineChange.php`
- Test: `tests/Modules/Genealogy/ApproveLineChangeTest.php`

- [ ] **Step 1: Create the event**

`app/Modules/Genealogy/Events/LineChangeApproved.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

final class LineChangeApproved
{
    use Dispatchable;

    public function __construct(
        public readonly int $requestId,
        public readonly int $distributorId,
        public readonly int $newPlacementParentId,
        public readonly string $chosenSide,
        public readonly int $reviewerId,
        public readonly Carbon $approvedAt,
    ) {}
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Modules/Genealogy/ApproveLineChangeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Events\LineChangeApproved;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\ApproveLineChange;
use App\Modules\Genealogy\Services\Exceptions\LineChangePlacementSlotFullError;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

// Mirrors the seed helper in LineChangeRequestTest but local to this file.
function alcSeed(int $userId, int $businessDaysAgo, ?int $parentId = null): int
{
    disableTestForeignKeys();
    try {
        $effective = now()->subWeekdays($businessDaysAgo);
        $depth = $parentId === null ? 0 : 1;
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $userId,
            'adn' => 'ARO'.rand(100000, 999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => $parentId ?? 0,
            'placement_parent_id' => $parentId ?? 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => $depth,
            'effective_date' => $effective->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => $effective->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        if ($parentId === null) {
            DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
        }
    } finally {
        enableTestForeignKeys();
    }

    DB::table('genealogy_closure')->insert(['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0]);
    if ($parentId !== null) {
        $ancestors = DB::table('genealogy_closure')->where('descendant_id', $parentId)->get(['ancestor_id', 'depth']);
        foreach ($ancestors as $a) {
            DB::table('genealogy_closure')->insert([
                'ancestor_id' => $a->ancestor_id, 'descendant_id' => $id, 'depth' => $a->depth + 1,
            ]);
        }
    }

    return $id;
}

function alcUser(string $tag): User
{
    return User::create([
        'email' => "alc-{$tag}-".rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
}

function alcPendingRequest(int $distributorId, int $fromParentId, int $toParentId): int
{
    return DB::table('line_change_requests')->insertGetId([
        'distributor_id' => $distributorId,
        'from_placement_parent_id' => $fromParentId,
        'to_placement_parent_id' => $toParentId,
        'requested_at' => now()->format('Y-m-d H:i:s.v'),
        'status' => 'pending',
        'reason' => 'please move me',
    ]);
}

it('ALC-01: approval moves placement, depth, closure and leaves sponsor intact', function () {
    Event::fake();

    $rootId = alcSeed(alcUser('root')->id, 40);
    $oldParentId = alcSeed(alcUser('old')->id, 30, parentId: $rootId);   // depth 1
    $newParentId = alcSeed(alcUser('new')->id, 25, parentId: $rootId);   // depth 1
    $applicantId = alcSeed(alcUser('app')->id, 2, parentId: $oldParentId); // depth 2, sponsor=oldParent

    $reqId = alcPendingRequest($applicantId, $oldParentId, $newParentId);
    $admin = alcUser('admin');

    app(ApproveLineChange::class)($reqId, $admin->id, 'L');

    $d = DB::table('distributors')->where('id', $applicantId)->first();
    expect((int) $d->placement_parent_id)->toBe($newParentId)
        ->and($d->placement_side)->toBe('L')
        ->and((int) $d->depth)->toBe(2)            // newParent depth 1 + 1
        ->and((int) $d->sponsor_id)->toBe($oldParentId); // sponsor UNCHANGED

    // Closure: applicant self + newParent(d1) + root(d2).
    $closure = DB::table('genealogy_closure')->where('descendant_id', $applicantId)
        ->orderBy('depth')->get()->map(fn ($r) => [(int) $r->ancestor_id, (int) $r->depth])->all();
    expect($closure)->toBe([[$applicantId, 0], [$newParentId, 1], [$rootId, 2]]);

    $req = LineChangeRequest::find($reqId);
    expect($req->status)->toBe('approved')
        ->and($req->chosen_side)->toBe('L')
        ->and((int) $req->reviewed_by)->toBe($admin->id)
        ->and($req->reviewed_at)->not->toBeNull()
        ->and($req->approved_at)->not->toBeNull();

    Event::assertDispatched(LineChangeApproved::class, fn ($e) => $e->distributorId === $applicantId && $e->chosenSide === 'L');
    expect(AuditLog::where('action', 'genealogy.line_change.approved')->where('subject_id', $applicantId)->exists())->toBeTrue();
});

it('ALC-02: approving onto a taken side throws slot-full', function () {
    $rootId = alcSeed(alcUser('root')->id, 40);
    $newParentId = alcSeed(alcUser('new')->id, 25, parentId: $rootId);
    // Fill the L slot under newParent.
    $blocker = alcSeed(alcUser('blk')->id, 20, parentId: $newParentId);
    DB::table('distributors')->where('id', $blocker)->update(['placement_side' => 'L']);

    $applicantId = alcSeed(alcUser('app')->id, 2, parentId: $rootId);
    $reqId = alcPendingRequest($applicantId, $rootId, $newParentId);

    expect(fn () => app(ApproveLineChange::class)($reqId, alcUser('admin')->id, 'L'))
        ->toThrow(LineChangePlacementSlotFullError::class);

    expect(LineChangeRequest::find($reqId)->status)->toBe('pending');
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Modules/Genealogy/ApproveLineChangeTest.php`
Expected: FAIL — `ApproveLineChange` class not found.

- [ ] **Step 4: Write the service**

Create `app/Modules/Genealogy/Services/ApproveLineChange.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Events\LineChangeApproved;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\Exceptions\LineChangePlacementSlotFullError;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Executes an approved line change: moves the requester's BINARY PLACEMENT
 * under the requested parent on the admin-chosen side, recomputes depth, and
 * rebuilds the requester's closure rows. The sponsor link is untouched.
 *
 * Safe because the requester is a leaf (RequestLineChange enforces no
 * downline), so only the requester's own closure rows change.
 */
final class ApproveLineChange
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(int $requestId, int $reviewerUserId, string $chosenSide): void
    {
        if (! in_array($chosenSide, ['L', 'R'], true)) {
            throw new RuntimeException("Invalid side '{$chosenSide}'; expected L or R.");
        }

        $this->db->connection()->transaction(function () use ($requestId, $reviewerUserId, $chosenSide): void {
            /** @var LineChangeRequest $request */
            $request = LineChangeRequest::query()->lockForUpdate()->findOrFail($requestId);
            if ($request->status !== 'pending') {
                throw new RuntimeException("Line-change request {$requestId} is not pending (status={$request->status}).");
            }

            $distributorId = (int) $request->distributor_id;
            $newParentId = (int) $request->to_placement_parent_id;

            // MySQL advisory lock on the target slot — same guard PlacementEngine
            // uses. SQLite (tests) skips it; the unique index is last-line defence.
            $usingMysql = $this->db->connection()->getDriverName() === 'mysql';
            if ($usingMysql) {
                $got = $this->db->selectOne('SELECT GET_LOCK(?, 5) AS got', ["placement:{$newParentId}"]);
                if ((int) ($got->got ?? 0) !== 1) {
                    throw new LineChangePlacementSlotFullError("Could not lock target parent {$newParentId}.");
                }
            }

            try {
                /** @var Distributor $newParent */
                $newParent = Distributor::query()->lockForUpdate()->findOrFail($newParentId);

                // Re-check the chosen slot is still free (could have filled
                // between request and approval).
                $taken = $this->db->table('distributors')
                    ->where('placement_parent_id', $newParentId)
                    ->where('id', '!=', $newParentId)
                    ->whereIn('placement_side', ['L', 'R'])
                    ->pluck('placement_side')
                    ->all();
                if (in_array($chosenSide, $taken, true)) {
                    throw new LineChangePlacementSlotFullError(
                        "Slot {$chosenSide} under parent {$newParentId} is already taken.",
                    );
                }

                /** @var Distributor $distributor */
                $distributor = Distributor::query()->lockForUpdate()->findOrFail($distributorId);
                $fromParentId = (int) $distributor->placement_parent_id;
                $newDepth = (int) $newParent->depth + 1;
                $now = Carbon::now();

                // Move the placement. side_chosen_by reuses 'referral_explicit'
                // (a human explicitly chose the side); the line-change context
                // lives in line_change_requests + audit_log. sponsor_id and
                // placement_id_at_registration are intentionally left as-is.
                $this->db->table('distributors')->where('id', $distributorId)->update([
                    'placement_parent_id' => $newParentId,
                    'placement_side' => $chosenSide,
                    'side_chosen_by' => 'referral_explicit',
                    'depth' => $newDepth,
                    'updated_at' => $now->format('Y-m-d H:i:s.v'),
                ]);

                // Rebuild closure rows for the (leaf) requester.
                $this->db->table('genealogy_closure')
                    ->where('descendant_id', $distributorId)
                    ->where('depth', '>=', 1)
                    ->delete();

                $ancestors = $this->db->table('genealogy_closure')
                    ->where('descendant_id', $newParentId)
                    ->get(['ancestor_id', 'depth']);
                $rows = [];
                foreach ($ancestors as $a) {
                    $rows[] = [
                        'ancestor_id' => $a->ancestor_id,
                        'descendant_id' => $distributorId,
                        'depth' => $a->depth + 1,
                    ];
                }
                if ($rows !== []) {
                    $this->db->table('genealogy_closure')->insert($rows);
                }

                $request->status = 'approved';
                $request->chosen_side = $chosenSide;
                $request->reviewed_by = $reviewerUserId;
                $request->reviewed_at = $now;
                $request->approved_at = $now;
                $request->save();

                AuditLog::create([
                    'actor_id' => $reviewerUserId,
                    'action' => 'genealogy.line_change.approved',
                    'subject_type' => 'distributor',
                    'subject_id' => $distributorId,
                    'details' => [
                        'request_id' => $requestId,
                        'from_placement_parent_id' => $fromParentId,
                        'to_placement_parent_id' => $newParentId,
                        'chosen_side' => $chosenSide,
                        'new_depth' => $newDepth,
                    ],
                ]);

                LineChangeApproved::dispatch(
                    $requestId,
                    $distributorId,
                    $newParentId,
                    $chosenSide,
                    $reviewerUserId,
                    $now,
                );
            } finally {
                if ($usingMysql) {
                    $this->db->statement('SELECT RELEASE_LOCK(?)', ["placement:{$newParentId}"]);
                }
            }
        });
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Modules/Genealogy/ApproveLineChangeTest.php`
Expected: PASS (ALC-01, ALC-02).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Modules/Genealogy/Events/LineChangeApproved.php app/Modules/Genealogy/Services/ApproveLineChange.php tests/Modules/Genealogy/ApproveLineChangeTest.php
git commit -m "$(cat <<'EOF'
feat(genealogy): ApproveLineChange moves binary placement + rebuilds closure

Sponsor untouched; re-checks slot under advisory lock; depth recomputed.

Compliance-Review: pending
EOF
)"
```

---

## Task 6: RejectLineChange service + LineChangeRejected event

**Files:**
- Create: `app/Modules/Genealogy/Events/LineChangeRejected.php`
- Create: `app/Modules/Genealogy/Services/RejectLineChange.php`
- Test: `tests/Modules/Genealogy/RejectLineChangeTest.php`

- [ ] **Step 1: Create the event**

`app/Modules/Genealogy/Events/LineChangeRejected.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

final class LineChangeRejected
{
    use Dispatchable;

    public function __construct(
        public readonly int $requestId,
        public readonly int $distributorId,
        public readonly string $decisionNote,
        public readonly int $reviewerId,
        public readonly Carbon $rejectedAt,
    ) {}
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Modules/Genealogy/RejectLineChangeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Events\LineChangeRejected;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\RejectLineChange;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('RLC-01: rejection sets status + note + reviewer and touches no placement', function () {
    Event::fake();

    $admin = User::create([
        'email' => 'rlc-admin-'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    $reqId = DB::table('line_change_requests')->insertGetId([
        'distributor_id' => 777,
        'from_placement_parent_id' => 1,
        'to_placement_parent_id' => 2,
        'requested_at' => now()->format('Y-m-d H:i:s.v'),
        'status' => 'pending',
        'reason' => 'move me',
    ]);

    app(RejectLineChange::class)($reqId, $admin->id, 'Target parent is in a different leg; not eligible.');

    $req = LineChangeRequest::find($reqId);
    expect($req->status)->toBe('rejected')
        ->and($req->decision_note)->toBe('Target parent is in a different leg; not eligible.')
        ->and((int) $req->reviewed_by)->toBe($admin->id)
        ->and($req->reviewed_at)->not->toBeNull()
        ->and($req->approved_at)->toBeNull();

    Event::assertDispatched(LineChangeRejected::class, fn ($e) => $e->requestId === $reqId);
    expect(AuditLog::where('action', 'genealogy.line_change.rejected')->where('subject_id', 777)->exists())->toBeTrue();
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Modules/Genealogy/RejectLineChangeTest.php`
Expected: FAIL — `RejectLineChange` not found.

- [ ] **Step 4: Write the service**

Create `app/Modules/Genealogy/Services/RejectLineChange.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Genealogy\Events\LineChangeRejected;
use App\Modules\Genealogy\Models\LineChangeRequest;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Rejects a pending line-change request. Records the admin's note; no
 * placement is touched. The requester is free to submit a new request only
 * if still inside the 5-day window (RequestLineChange re-checks).
 */
final class RejectLineChange
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(int $requestId, int $reviewerUserId, string $decisionNote): void
    {
        $this->db->connection()->transaction(function () use ($requestId, $reviewerUserId, $decisionNote): void {
            /** @var LineChangeRequest $request */
            $request = LineChangeRequest::query()->lockForUpdate()->findOrFail($requestId);
            if ($request->status !== 'pending') {
                throw new RuntimeException("Line-change request {$requestId} is not pending (status={$request->status}).");
            }

            $now = Carbon::now();
            $request->status = 'rejected';
            $request->decision_note = mb_substr($decisionNote, 0, 1024);
            $request->reviewed_by = $reviewerUserId;
            $request->reviewed_at = $now;
            $request->save();

            AuditLog::create([
                'actor_id' => $reviewerUserId,
                'action' => 'genealogy.line_change.rejected',
                'subject_type' => 'distributor',
                'subject_id' => (int) $request->distributor_id,
                'details' => [
                    'request_id' => $requestId,
                    'decision_note' => $request->decision_note,
                ],
            ]);

            LineChangeRejected::dispatch(
                $requestId,
                (int) $request->distributor_id,
                (string) $request->decision_note,
                $reviewerUserId,
                $now,
            );
        });
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Modules/Genealogy/RejectLineChangeTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add app/Modules/Genealogy/Events/LineChangeRejected.php app/Modules/Genealogy/Services/RejectLineChange.php tests/Modules/Genealogy/RejectLineChangeTest.php
git commit -m "feat(genealogy): RejectLineChange records admin note, leaves placement intact"
```

---

## Task 7: Recipients helper + notifications + mail views + listeners

**Files:**
- Modify: `app/Modules/Admin/Support/AdminNotificationRecipients.php`
- Create: 4 notification classes, 4 mail views, 2 listeners (paths in File Structure).

- [ ] **Step 1: Add the recipients method**

In `app/Modules/Admin/Support/AdminNotificationRecipients.php`, add this method inside the class (after `compliance()`):

```php
    /**
     * Who to email when a distributor submits a line-change request — every
     * active user holding the 'admin' or 'admin-compliance' role.
     *
     * @return Collection<int, User>
     */
    public static function lineChangeReviewers(): Collection
    {
        return User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'admin-compliance']))
            ->get();
    }
```

- [ ] **Step 2: Create the requester notification (request received)**

`app/Modules/Genealogy/Notifications/LineChangeRequestedRequesterNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class LineChangeRequestedRequesterNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $requesterAdn,
        public readonly string $targetParentAdn,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('We received your line-change request')
            ->view('emails.line-change-requested-requester', [
                'requesterAdn' => $this->requesterAdn,
                'targetParentAdn' => $this->targetParentAdn,
            ]);
    }
}
```

- [ ] **Step 3: Create the admin notification (new request to review)**

`app/Modules/Genealogy/Notifications/LineChangeRequestedAdminNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class LineChangeRequestedAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $requestId,
        public readonly string $requesterAdn,
        public readonly string $targetParentAdn,
        public readonly ?string $reason,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Line-change request to review — ADN {$this->requesterAdn}")
            ->view('emails.line-change-requested-admin', [
                'requestId' => $this->requestId,
                'requesterAdn' => $this->requesterAdn,
                'targetParentAdn' => $this->targetParentAdn,
                'reason' => $this->reason,
                'reviewUrl' => url("/admin/line-changes/{$this->requestId}"),
            ]);
    }
}
```

- [ ] **Step 4: Create the approved notification**

`app/Modules/Genealogy/Notifications/LineChangeApprovedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class LineChangeApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $requesterAdn,
        public readonly string $newParentAdn,
        public readonly string $side,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sideLabel = $this->side === 'L' ? 'left' : 'right';

        return (new MailMessage)
            ->subject('Your line-change request was approved')
            ->view('emails.line-change-approved', [
                'requesterAdn' => $this->requesterAdn,
                'newParentAdn' => $this->newParentAdn,
                'sideLabel' => $sideLabel,
            ]);
    }
}
```

- [ ] **Step 5: Create the rejected notification**

`app/Modules/Genealogy/Notifications/LineChangeRejectedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class LineChangeRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $requesterAdn,
        public readonly string $decisionNote,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Update on your line-change request')
            ->view('emails.line-change-rejected', [
                'requesterAdn' => $this->requesterAdn,
                'decisionNote' => $this->decisionNote,
            ]);
    }
}
```

- [ ] **Step 6: Create the four mail views**

These extend the project's branded email layout (`emails.layouts.branded`) — the
same pattern as `emails/new-placement-under-you.blade.php`. The layout takes
`subject` + `previewText` and yields a `content` section. Do NOT use markdown
`<x-mail::message>` components — the notifications render these with `->view()`,
not `->markdown()`.

`resources/views/emails/line-change-requested-requester.blade.php`:

```blade
@extends('emails.layouts.branded', [
    'subject'     => 'We received your line-change request',
    'previewText' => 'Your line-change request for ADN '.$requesterAdn.' is now with our team.',
])

@section('content')
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    Thanks — we've received your line-change request for ADN
    <strong style="color: #0a719f;">{{ $requesterAdn }}</strong>.
</p>
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    You asked to move your binary-tree placement under ADN
    <strong style="color: #0a719f;">{{ $targetParentAdn }}</strong>. This changes your
    <strong>binary placement only</strong> — your sponsor stays the same.
</p>
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    An admin will review it shortly. We'll email you again once a decision is made.
</p>
@endsection
```

`resources/views/emails/line-change-requested-admin.blade.php`:

```blade
@extends('emails.layouts.branded', [
    'subject'     => 'Line-change request to review — ADN '.$requesterAdn,
    'previewText' => 'Distributor '.$requesterAdn.' requested a binary-placement change.',
])

@section('content')
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    Distributor <strong style="color: #0a719f;">{{ $requesterAdn }}</strong> has requested a line change.
</p>
<p style="margin: 0 0 8px 0; font-size: 14px; line-height: 22px; color: #374151;">
    <strong style="color: #111827;">Requested target placement parent:</strong> {{ $targetParentAdn }}
</p>
<p style="margin: 0 0 14px 0; font-size: 14px; line-height: 22px; color: #374151;">
    <strong style="color: #111827;">Reason given:</strong> {{ $reason ?: '—' }}
</p>
<p style="margin: 0 0 18px 0; font-size: 14px; line-height: 22px; color: #374151;">
    This will move the requester's <strong>binary placement only</strong>; their sponsor is unchanged.
</p>
<p style="margin: 0 0 14px 0;">
    <a href="{{ $reviewUrl }}" style="display: inline-block; padding: 10px 18px; background: #0a719f; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 6px;">
        Review request
    </a>
</p>
@endsection
```

`resources/views/emails/line-change-approved.blade.php`:

```blade
@extends('emails.layouts.branded', [
    'subject'     => 'Your line-change request was approved',
    'previewText' => 'Your placement (ADN '.$requesterAdn.') has been moved.',
])

@section('content')
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    Good news — your placement (ADN <strong style="color: #0a719f;">{{ $requesterAdn }}</strong>)
    has been moved under ADN <strong style="color: #0a719f;">{{ $newParentAdn }}</strong>
    on the <strong>{{ $sideLabel }}</strong> leg.
</p>
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    This changed your <strong>binary placement only</strong> — your sponsor is unchanged.
</p>
@endsection
```

`resources/views/emails/line-change-rejected.blade.php`:

```blade
@extends('emails.layouts.branded', [
    'subject'     => 'Update on your line-change request',
    'previewText' => 'We were unable to approve your line-change request.',
])

@section('content')
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    We've reviewed your line-change request for ADN
    <strong style="color: #0a719f;">{{ $requesterAdn }}</strong> and are unable to approve it at this time.
</p>
<p style="margin: 0 0 14px 0; font-size: 14px; line-height: 22px; color: #374151;">
    <strong style="color: #111827;">Reason:</strong> {{ $decisionNote }}
</p>
<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
    If you have questions, contact support@arovolife.com.
</p>
@endsection
```

> If `emails/new-placement-under-you.blade.php` references a different section
> name or layout variables than shown, mirror that file exactly — it is the
> canonical example in this codebase.

- [ ] **Step 7: Create the request listener**

`app/Modules/Genealogy/Listeners/SendLineChangeRequestedMails.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Listeners;

use App\Modules\Admin\Support\AdminNotificationRecipients;
use App\Modules\Genealogy\Events\LineChangeRequested;
use App\Modules\Genealogy\Notifications\LineChangeRequestedAdminNotification;
use App\Modules\Genealogy\Notifications\LineChangeRequestedRequesterNotification;
use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * On a new line-change request, email every admin reviewer AND the requester.
 */
final class SendLineChangeRequestedMails implements ShouldQueue
{
    public function handle(LineChangeRequested $event): void
    {
        $requester = Distributor::query()->with('user')->find($event->distributorId);
        $target = Distributor::query()->find($event->toPlacementParentId);
        if ($requester === null || $target === null) {
            return;
        }

        $request = LineChangeRequest::find($event->requestId);
        $reason = $request?->reason;

        // Requester confirmation.
        if ($requester->user !== null) {
            Notification::send($requester->user, new LineChangeRequestedRequesterNotification(
                requesterAdn: $requester->adn,
                targetParentAdn: $target->adn,
            ));
        }

        // Admin reviewers.
        $admins = AdminNotificationRecipients::lineChangeReviewers();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new LineChangeRequestedAdminNotification(
                requestId: $event->requestId,
                requesterAdn: $requester->adn,
                targetParentAdn: $target->adn,
                reason: $reason,
            ));
        }
    }
}
```

- [ ] **Step 8: Create the decision listener**

`app/Modules/Genealogy/Listeners/SendLineChangeDecidedMails.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Listeners;

use App\Modules\Genealogy\Events\LineChangeApproved;
use App\Modules\Genealogy\Events\LineChangeRejected;
use App\Modules\Genealogy\Notifications\LineChangeApprovedNotification;
use App\Modules\Genealogy\Notifications\LineChangeRejectedNotification;
use App\Modules\Genealogy\Notifications\NewPlacementUnderYouNotification;
use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Emails on a line-change decision. Two handlers — Laravel auto-discovers
 * each by its type-hinted argument.
 */
final class SendLineChangeDecidedMails implements ShouldQueue
{
    public function handleApproved(LineChangeApproved $event): void
    {
        $requester = Distributor::query()->with('user')->find($event->distributorId);
        $newParent = Distributor::query()->with('user')->find($event->newPlacementParentId);
        if ($requester === null) {
            return;
        }

        if ($requester->user !== null) {
            Notification::send($requester->user, new LineChangeApprovedNotification(
                requesterAdn: $requester->adn,
                newParentAdn: $newParent?->adn ?? '—',
                side: $event->chosenSide,
            ));
        }

        // New placement parent — mirrors SendPlacementCreatedMails. Skip the
        // reserved company root.
        if ($newParent !== null && $newParent->user !== null && ! ReservedAdns::isReserved($newParent->adn)) {
            Notification::send($newParent->user, new NewPlacementUnderYouNotification(
                parentFullName: (string) ($newParent->user->full_name ?? 'Distributor'),
                parentAdn: $newParent->adn,
                newJoinerFullName: (string) ($requester->user->full_name ?? 'Distributor'),
                newJoinerAdn: $requester->adn,
                side: $event->chosenSide,
                sideChosenBy: 'referral_explicit',
                placedAtFormatted: Carbon::now()->format('d M Y H:i'),
            ));
        }
    }

    public function handleRejected(LineChangeRejected $event): void
    {
        $requester = Distributor::query()->with('user')->find($event->distributorId);
        if ($requester === null || $requester->user === null) {
            return;
        }

        Notification::send($requester->user, new LineChangeRejectedNotification(
            requesterAdn: $requester->adn,
            decisionNote: $event->decisionNote,
        ));
    }
}
```

> Note: this listener uses two handler methods. Laravel's event auto-discovery
> registers a class as a listener for the event type-hinted in `handle`. For
> a class with multiple handlers, register them explicitly. Add the mapping in
> Step 9.

- [ ] **Step 9: Register the decision listeners explicitly**

Confirm how listeners are wired:

Run: `grep -rn "Event::listen\|protected \$listen\|->listen(" app/Providers app/Modules/*/Providers 2>/dev/null`

If there is an `EventServiceProvider` with a `$listen` array, add:

```php
\App\Modules\Genealogy\Events\LineChangeApproved::class => [
    [\App\Modules\Genealogy\Listeners\SendLineChangeDecidedMails::class, 'handleApproved'],
],
\App\Modules\Genealogy\Events\LineChangeRejected::class => [
    [\App\Modules\Genealogy\Listeners\SendLineChangeDecidedMails::class, 'handleRejected'],
],
```

If there is **no** `$listen` array (auto-discovery only), instead register in the boot of the appropriate provider (e.g. `app/Providers/AppServiceProvider.php`):

```php
\Illuminate\Support\Facades\Event::listen(
    \App\Modules\Genealogy\Events\LineChangeApproved::class,
    [\App\Modules\Genealogy\Listeners\SendLineChangeDecidedMails::class, 'handleApproved'],
);
\Illuminate\Support\Facades\Event::listen(
    \App\Modules\Genealogy\Events\LineChangeRejected::class,
    [\App\Modules\Genealogy\Listeners\SendLineChangeDecidedMails::class, 'handleRejected'],
);
```

The single-handler `SendLineChangeRequestedMails` (with `handle(LineChangeRequested)`) is auto-discovered like the existing `SendPlacementCreatedMails`; no manual registration needed.

- [ ] **Step 10: Verify the suite still passes**

Run: `vendor/bin/pest tests/Modules/Genealogy`
Expected: PASS (events are real now; existing `Event::fake()` tests unaffected).

- [ ] **Step 11: Commit**

```bash
vendor/bin/pint --dirty
git add app/Modules/Admin/Support/AdminNotificationRecipients.php app/Modules/Genealogy/Notifications app/Modules/Genealogy/Listeners resources/views/emails/line-change-*.blade.php app/Providers
git commit -m "feat(genealogy): line-change request/approve/reject emails to admins + requester + new parent"
```

---

## Task 8: Reusable UI partials (help-tip + confirm-modal)

Platform convention (see memory `ui-convention-help-confirm-form-notes`): field help icons + a confirmation modal before any action. These two partials are reused by the line-change views (Tasks 9–10) and are available platform-wide.

**Files:**
- Create: `resources/views/components/help-tip.blade.php`
- Create: `resources/views/components/confirm-modal.blade.php`

- [ ] **Step 1: Create the help-tip component**

`resources/views/components/help-tip.blade.php`:

```blade
@props(['text'])
{{-- Info icon with a hover/focus tooltip. Usage: <x-help-tip text="..." /> --}}
<span class="relative inline-flex items-center group align-middle ml-1">
    <button type="button" tabindex="0" aria-label="More information"
        class="inline-flex h-4 w-4 items-center justify-center rounded-full border border-gray-400 text-[10px] font-bold text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-400">
        i
    </button>
    <span role="tooltip"
        class="pointer-events-none absolute left-1/2 bottom-full z-20 mb-1 w-56 -translate-x-1/2 rounded-lg bg-gray-900 px-3 py-2 text-xs leading-snug text-white opacity-0 shadow-lg transition-opacity duration-150 group-hover:opacity-100 group-focus-within:opacity-100">
        {{ $text }}
    </span>
</span>
```

- [ ] **Step 2: Create the confirm-modal component**

`resources/views/components/confirm-modal.blade.php`. A reusable modal driven by vanilla JS (no Alpine dependency). Any form whose submit must be confirmed gets `data-confirm` / `data-confirm-title` / `data-confirm-impact` attributes; the script intercepts submit, shows the modal, and only submits on confirm.

```blade
{{-- Reusable confirmation modal. Include ONCE per page (e.g. in the layout or
     at the bottom of a view). Mark any form needing confirmation with:
       <form ... data-confirm="Proceed?" data-confirm-title="..." data-confirm-impact="...">
     The script blocks the native submit, shows this modal, and submits only
     after the user confirms. --}}
<div id="confirm-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
        <h2 id="confirm-modal-title" class="text-base font-semibold text-gray-900 mb-2">Please confirm</h2>
        <p id="confirm-modal-message" class="text-sm text-gray-700 mb-2"></p>
        <p id="confirm-modal-impact" class="text-xs text-gray-500 mb-5 leading-relaxed"></p>
        <div class="flex justify-end gap-3">
            <button type="button" id="confirm-modal-cancel"
                class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
            </button>
            <button type="button" id="confirm-modal-ok"
                class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white hover:bg-brand-600">
                Confirm
            </button>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('confirm-modal');
    if (!modal) return;
    var titleEl = document.getElementById('confirm-modal-title');
    var msgEl = document.getElementById('confirm-modal-message');
    var impactEl = document.getElementById('confirm-modal-impact');
    var okBtn = document.getElementById('confirm-modal-ok');
    var cancelBtn = document.getElementById('confirm-modal-cancel');
    var pendingForm = null;

    function close() { modal.classList.add('hidden'); modal.classList.remove('flex'); pendingForm = null; }
    function open(form) {
        titleEl.textContent = form.getAttribute('data-confirm-title') || 'Please confirm';
        msgEl.textContent = form.getAttribute('data-confirm') || 'Are you sure?';
        impactEl.textContent = form.getAttribute('data-confirm-impact') || '';
        pendingForm = form;
        modal.classList.remove('hidden'); modal.classList.add('flex');
    }

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.confirmed === 'true') return; // already confirmed
            e.preventDefault();
            open(form);
        });
    });

    okBtn.addEventListener('click', function () {
        if (pendingForm) { pendingForm.dataset.confirmed = 'true'; pendingForm.submit(); }
        close();
    });
    cancelBtn.addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
})();
</script>
```

- [ ] **Step 3: Lint (Blade has no PHP to lint, just format)**

Run: `vendor/bin/pint --dirty`
Expected: clean (no PHP files changed by these Blade views).

- [ ] **Step 4: Commit**

```bash
git add resources/views/components/help-tip.blade.php resources/views/components/confirm-modal.blade.php
git commit -m "feat(ui): reusable help-tip + confirm-modal components (platform convention)"
```

---

## Task 9: Distributor request controller + view (placement terms, one-change guard, conventions)

**Files:**
- Modify: `app/Modules/Genealogy/Http/Controllers/LineChangeController.php`
- Modify: `resources/views/genealogy/line-change.blade.php`

- [ ] **Step 1: Update the controller**

Replace `app/Modules/Genealogy/Http/Controllers/LineChangeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Http\Controllers;

use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\Exceptions\LineChangeAlreadyProcessedError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeAlreadyRequestedError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeHasDownlineError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeNewParentTooNewError;
use App\Modules\Genealogy\Services\Exceptions\LineChangePlacementSlotFullError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeWindowExpiredError;
use App\Modules\Genealogy\Services\RequestLineChange;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class LineChangeController extends Controller
{
    public function __construct(
        private readonly RequestLineChange $request,
    ) {}

    public function show(): View|RedirectResponse
    {
        $self = Auth::user()?->distributor;
        if ($self === null) {
            return redirect()->route('dashboard');
        }

        $businessDaysSince = (int) $self->effective_date->diffInWeekdays(now());
        $existing = LineChangeRequest::query()
            ->where('distributor_id', $self->id)
            ->latest('requested_at')
            ->first();

        // One change per distributor, ever.
        $alreadyUsed = LineChangeRequest::query()
            ->where('distributor_id', $self->id)
            ->where('status', 'approved')
            ->exists();

        return view('genealogy.line-change', [
            'self' => $self,
            'businessDaysSince' => $businessDaysSince,
            'isWithinWindow' => $businessDaysSince <= 5,
            'existing' => $existing,
            'alreadyUsed' => $alreadyUsed,
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        $request->merge([
            'to_parent_adn' => strtoupper(trim((string) $request->input('to_parent_adn', ''))),
        ]);
        $validated = $request->validate([
            'to_parent_adn' => ['required', 'string', 'regex:/^[0-9]{9}$/'],
            'reason' => ['nullable', 'string', 'max:512'],
        ], [
            'to_parent_adn.regex' => 'Placement parent ADN must be exactly 9 digits, e.g. 111222333.',
        ]);

        $self = Auth::user()?->distributor;
        if ($self === null) {
            return redirect()->route('dashboard');
        }

        $newParent = Distributor::query()
            ->where('adn', $validated['to_parent_adn'])
            ->first();
        if ($newParent === null) {
            return back()->withInput()->withErrors([
                'to_parent_adn' => 'No distributor found with that ADN.',
            ]);
        }

        if ($newParent->id === $self->id) {
            return back()->withInput()->withErrors([
                'to_parent_adn' => 'You cannot request a line-change to yourself.',
            ]);
        }

        if ($newParent->spouse_distributor_id !== null && ! $newParent->is_primary_couple) {
            return back()->withInput()->withErrors([
                'to_parent_adn' => 'That ADN belongs to a couple-secondary record. Use the primary spouse\'s ADN instead.',
            ]);
        }

        try {
            ($this->request)(
                distributorId: $self->id,
                toPlacementParentId: $newParent->id,
                actorUserId: (int) Auth::id(),
                reason: $validated['reason'] ?? null,
            );
        } catch (LineChangeWindowExpiredError) {
            return back()->withErrors(['line_change' => 'The 5-business-day window has ended.']);
        } catch (LineChangeHasDownlineError) {
            return back()->withErrors(['line_change' => 'You already have referrals in your tree; line-change is not available.']);
        } catch (LineChangeAlreadyRequestedError) {
            return back()->withErrors(['line_change' => 'A line-change request is already pending for your account.']);
        } catch (LineChangeAlreadyProcessedError) {
            return back()->withErrors(['line_change' => 'You have already used your one line change; a further change is not allowed.']);
        } catch (LineChangePlacementSlotFullError) {
            return back()->withInput()->withErrors(['to_parent_adn' => 'That placement parent has no free position (both legs are taken). Choose another ADN.']);
        } catch (LineChangeNewParentTooNewError) {
            return back()->withInput()->withErrors(['to_parent_adn' => 'You can only move under someone who registered before you. Please pick an ADN that registered earlier than your own registration date.']);
        }

        return redirect()->route('line-change.show')->with(
            'status',
            'Your line-change request has been submitted for review.',
        );
    }
}
```

- [ ] **Step 2: Replace the distributor view**

Replace `resources/views/genealogy/line-change.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Request line-change')

@section('content')

<div class="max-w-xl mx-auto py-10">
    <h1 class="text-2xl font-bold mb-2">Request a line-change</h1>

    {{-- Form-purpose note (platform convention). --}}
    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-4 text-sm text-blue-900">
        <p class="font-semibold mb-1">What this form does</p>
        <p class="leading-relaxed">
            This requests a move of your position in the binary tree to sit under a
            different placement parent. It changes your <strong>binary placement only</strong> —
            your sponsor stays the same. An admin must approve the request before anything moves.
        </p>
    </div>

    <p class="text-sm text-gray-600 mb-6">
        Within five working days of registration, and only if you have not yet introduced
        anyone to arovolife, you may request this change. Direct Seller Agreement §10.
        You may use a line change <strong>once</strong>.
    </p>

    <div class="rounded-2xl border border-gray-200 bg-white p-6 mb-6">
        <dl class="text-sm grid grid-cols-2 gap-y-2">
            <dt class="text-gray-600">Your ADN</dt>
            <dd class="font-mono font-bold text-brand-600 tracking-widest">{{ $self->adn }}</dd>

            <dt class="text-gray-600">Effective date</dt>
            <dd class="text-gray-900 font-medium">{{ $self->effective_date->format('d M Y') }}</dd>

            <dt class="text-gray-600">Working days since registration</dt>
            <dd class="text-gray-900 font-medium">{{ $businessDaysSince }} of 5</dd>
        </dl>
    </div>

    @if($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 p-4 mb-6 text-sm text-red-700">
        @foreach($errors->all() as $error)
        <p>{{ $error }}</p>
        @endforeach
    </div>
    @endif

    @if(session('status'))
    <div class="rounded-xl border border-green-200 bg-green-50 p-4 mb-6 text-sm text-green-800">
        {{ session('status') }}
    </div>
    @endif

    @if($alreadyUsed)
    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-6 text-sm text-gray-700">
        <p class="font-semibold mb-1">You've already used your one line change</p>
        <p>Each distributor may change their placement once. For anything further, contact
            <a class="text-brand-600 underline" href="mailto:support@arovolife.com">support@arovolife.com</a>.</p>
    </div>
    @elseif($existing && $existing->status === 'pending')
    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">
        <p class="font-semibold mb-1">Pending request</p>
        <p>You submitted a line-change request on
            {{ $existing->requested_at->format('d M Y H:i') }}. An admin will review it shortly.</p>
    </div>
    @elseif($existing && $existing->status === 'rejected')
    <div class="rounded-2xl border border-red-200 bg-red-50 p-6 text-sm text-red-800 mb-6">
        <p class="font-semibold mb-1">Your last request was not approved</p>
        @if($existing->decision_note)<p class="mb-2">Reason: {{ $existing->decision_note }}</p>@endif
        <p>If you are still within the window, you may submit a new request below.</p>
    </div>
    @endif

    @if(! $alreadyUsed && (! $existing || $existing->status !== 'pending') && $isWithinWindow)
    <form method="POST" action="{{ route('line-change.submit') }}" class="space-y-5"
        data-confirm="Submit this line-change request for admin review?"
        data-confirm-title="Confirm line-change request"
        data-confirm-impact="This changes your binary placement only — your sponsor stays the same. The change happens only after an admin approves it.">
        @csrf

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                New placement parent ADN
                <x-help-tip text="The 9-digit ADN of the distributor you want to be placed under in the binary tree. They must have joined before you and have a free leg. Your sponsor does not change." />
            </label>
            <input type="text" name="to_parent_adn" value="{{ old('to_parent_adn') }}"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono tracking-widest focus:border-brand-500 focus:ring-brand-500"
                placeholder="111222333" required>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1.5">
                Reason (optional)
                <x-help-tip text="Briefly tell the admin why you want this placement change. Shown to the reviewer; max 512 characters." />
            </label>
            <textarea name="reason" rows="3" maxlength="512"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-500"
                placeholder="Briefly tell us why you want this change.">{{ old('reason') }}</textarea>
        </div>

        <button type="submit"
            class="w-full inline-flex justify-center items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-6 py-3 text-sm transition-colors">
            Submit request
        </button>
    </form>
    @elseif(! $alreadyUsed && (! $existing || $existing->status !== 'pending'))
    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-6 text-sm text-gray-700">
        <p class="font-semibold mb-2">The 5-working-day window has ended.</p>
        <p>For account changes outside this window, please contact
            <a class="text-brand-600 underline" href="mailto:support@arovolife.com">support@arovolife.com</a>.</p>
    </div>
    @endif

    <a href="{{ route('dashboard') }}" class="block text-center text-sm text-gray-500 hover:text-gray-700 mt-6">
        Back to dashboard
    </a>
</div>

<x-confirm-modal />

@endsection
```

- [ ] **Step 3: Lint**

Run: `vendor/bin/pint --dirty` then `php -l app/Modules/Genealogy/Http/Controllers/LineChangeController.php`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add app/Modules/Genealogy/Http/Controllers/LineChangeController.php resources/views/genealogy/line-change.blade.php
git commit -m "feat(genealogy): distributor line-change form uses placement terms + one-change guard + UI conventions"
```

---

## Task 10: Admin controller + routes + admin views

**Files:**
- Create: `app/Modules/Admin/Http/Controllers/AdminLineChangeController.php`
- Modify: `routes/web.php`
- Create: `resources/views/admin/line-change/index.blade.php`
- Create: `resources/views/admin/line-change/show.blade.php`
- Test: `tests/Modules/Admin/AdminLineChangeControllerTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Modules/Admin/AdminLineChangeControllerTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function adminUser(): User
{
    Role::findOrCreate('admin', 'web');
    $u = User::create([
        'email' => 'lc-admin-'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
    $u->assignRole('admin');

    return $u;
}

function adminLcSeed(int $businessDaysAgo, ?int $parentId = null): int
{
    disableTestForeignKeys();
    try {
        $u = User::create([
            'email' => 'lc-d-'.rand(1000, 9999).'@test.com',
            'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
            'password_hash' => bcrypt('x'),
            'status' => 'active',
        ]);
        $effective = now()->subWeekdays($businessDaysAgo);
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $u->id,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => $parentId ?? 0,
            'placement_parent_id' => $parentId ?? 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => $parentId === null ? 0 : 1,
            'effective_date' => $effective->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => $effective->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        if ($parentId === null) {
            DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
        }
    } finally {
        enableTestForeignKeys();
    }
    DB::table('genealogy_closure')->insert(['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0]);
    if ($parentId !== null) {
        $anc = DB::table('genealogy_closure')->where('descendant_id', $parentId)->get(['ancestor_id', 'depth']);
        foreach ($anc as $a) {
            DB::table('genealogy_closure')->insert(['ancestor_id' => $a->ancestor_id, 'descendant_id' => $id, 'depth' => $a->depth + 1]);
        }
    }

    return $id;
}

it('ALCC-01: index lists pending requests', function () {
    $admin = adminUser();
    $rootId = adminLcSeed(40);
    $targetId = adminLcSeed(25, parentId: $rootId);
    $applicantId = adminLcSeed(2, parentId: $rootId);
    DB::table('line_change_requests')->insert([
        'distributor_id' => $applicantId, 'from_placement_parent_id' => $rootId,
        'to_placement_parent_id' => $targetId, 'requested_at' => now()->format('Y-m-d H:i:s.v'),
        'status' => 'pending', 'reason' => 'move me',
    ]);

    $this->actingAs($admin)->get(route('admin.line-changes.index'))
        ->assertOk()
        ->assertSee('Line-change requests');
});

it('ALCC-02: approve moves placement and marks approved', function () {
    Notification::fake();
    $admin = adminUser();
    $rootId = adminLcSeed(40);
    $targetId = adminLcSeed(25, parentId: $rootId);
    $applicantId = adminLcSeed(2, parentId: $rootId);
    $reqId = DB::table('line_change_requests')->insertGetId([
        'distributor_id' => $applicantId, 'from_placement_parent_id' => $rootId,
        'to_placement_parent_id' => $targetId, 'requested_at' => now()->format('Y-m-d H:i:s.v'),
        'status' => 'pending', 'reason' => 'move me',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.line-changes.approve', $reqId), ['chosen_side' => 'L'])
        ->assertRedirect(route('admin.line-changes.index'));

    expect(LineChangeRequest::find($reqId)->status)->toBe('approved');
    expect((int) DB::table('distributors')->where('id', $applicantId)->value('placement_parent_id'))->toBe($targetId);
});

it('ALCC-03: reject requires a note and marks rejected', function () {
    Notification::fake();
    $admin = adminUser();
    $rootId = adminLcSeed(40);
    $targetId = adminLcSeed(25, parentId: $rootId);
    $applicantId = adminLcSeed(2, parentId: $rootId);
    $reqId = DB::table('line_change_requests')->insertGetId([
        'distributor_id' => $applicantId, 'from_placement_parent_id' => $rootId,
        'to_placement_parent_id' => $targetId, 'requested_at' => now()->format('Y-m-d H:i:s.v'),
        'status' => 'pending', 'reason' => 'move me',
    ]);

    // Missing note → validation error.
    $this->actingAs($admin)
        ->post(route('admin.line-changes.reject', $reqId), ['decision_note' => 'short'])
        ->assertSessionHasErrors('decision_note');

    // Valid note → rejected.
    $this->actingAs($admin)
        ->post(route('admin.line-changes.reject', $reqId), ['decision_note' => 'Target leg is not eligible for this move.'])
        ->assertRedirect(route('admin.line-changes.index'));

    expect(LineChangeRequest::find($reqId)->status)->toBe('rejected');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Modules/Admin/AdminLineChangeControllerTest.php`
Expected: FAIL — route `admin.line-changes.index` not defined.

- [ ] **Step 3: Create the admin controller**

Create `app/Modules/Admin/Http/Controllers/AdminLineChangeController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\ApproveLineChange;
use App\Modules\Genealogy\Services\Exceptions\LineChangePlacementSlotFullError;
use App\Modules\Genealogy\Services\RejectLineChange;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class AdminLineChangeController extends Controller
{
    public function __construct(
        private readonly ApproveLineChange $approve,
        private readonly RejectLineChange $reject,
    ) {}

    public function index(Request $request): View
    {
        $tab = $request->query('tab') === 'decided' ? 'decided' : 'pending';

        $query = LineChangeRequest::query()
            ->with(['distributor.user', 'fromPlacementParent', 'toPlacementParent'])
            ->when($tab === 'pending',
                fn ($q) => $q->where('status', 'pending'),
                fn ($q) => $q->whereIn('status', ['approved', 'rejected', 'expired']),
            )
            ->orderByDesc('requested_at');

        $rows = $query->paginate(50)->withQueryString();

        $pendingCount = LineChangeRequest::query()->where('status', 'pending')->count();
        $decidedCount = LineChangeRequest::query()->whereIn('status', ['approved', 'rejected', 'expired'])->count();

        return view('admin.line-change.index', [
            'rows' => $rows,
            'currentTab' => $tab,
            'pendingCount' => $pendingCount,
            'decidedCount' => $decidedCount,
        ]);
    }

    public function show(int $id): View
    {
        $lcr = LineChangeRequest::query()
            ->with(['distributor.user', 'fromPlacementParent', 'toPlacementParent', 'reviewer'])
            ->findOrFail($id);

        // Which legs are free under the target parent (for the side picker).
        $taken = Distributor::query()
            ->where('placement_parent_id', $lcr->to_placement_parent_id)
            ->where('id', '!=', $lcr->to_placement_parent_id)
            ->whereIn('placement_side', ['L', 'R'])
            ->pluck('placement_side')
            ->all();
        $freeSides = array_values(array_diff(['L', 'R'], $taken));

        return view('admin.line-change.show', [
            'lcr' => $lcr,
            'freeSides' => $freeSides,
        ]);
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'chosen_side' => ['required', Rule::in(['L', 'R'])],
        ]);

        try {
            ($this->approve)($id, (int) Auth::id(), $validated['chosen_side']);
        } catch (LineChangePlacementSlotFullError) {
            return back()->withErrors(['chosen_side' => 'That leg is no longer free under the target parent. Pick the other leg or reject.']);
        }

        return redirect()->route('admin.line-changes.index')->with('status', 'Line change approved and placement moved.');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'decision_note' => ['required', 'string', 'min:8', 'max:1024'],
        ]);

        ($this->reject)($id, (int) Auth::id(), $validated['decision_note']);

        return redirect()->route('admin.line-changes.index')->with('status', 'Line change rejected. The distributor has been emailed.');
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, inside the `Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(...)` block, after the KYC routes block, add:

```php
    // Line-change requests — review queue + approve/reject
    Route::get('/line-changes', [AdminLineChangeController::class, 'index'])->name('line-changes.index');
    Route::get('/line-changes/{id}', [AdminLineChangeController::class, 'show'])->whereNumber('id')->name('line-changes.show');
    Route::post('/line-changes/{id}/approve', [AdminLineChangeController::class, 'approve'])->whereNumber('id')->name('line-changes.approve');
    Route::post('/line-changes/{id}/reject', [AdminLineChangeController::class, 'reject'])->whereNumber('id')->name('line-changes.reject');
```

Add the import at the top of `routes/web.php` (with the other admin controller imports):

```php
use App\Modules\Admin\Http\Controllers\AdminLineChangeController;
```

- [ ] **Step 5: Create the admin index view**

Create `resources/views/admin/line-change/index.blade.php`:

```blade
@extends('admin.layouts.admin')
@section('title', 'Line-change requests')
@section('heading', 'Line-change requests')

@section('content')

<p class="text-sm text-gray-600 mb-4">
    Distributors requesting a move of their <strong>binary placement</strong> to a different
    parent. Approving moves their placement only — the sponsor is never changed.
</p>

@if(session('status'))
<div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-3 py-2 text-sm text-green-800">
    {{ session('status') }}
</div>
@endif

<div class="flex items-center gap-2 mb-6">
    <a href="{{ route('admin.line-changes.index', ['tab' => 'pending']) }}"
        class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors
            {{ $currentTab === 'decided' ? 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50' : 'border-brand-500 bg-brand-500 text-white' }}">
        Pending
        <span class="inline-flex items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-bold
            {{ $currentTab === 'decided' ? 'bg-gray-100 text-gray-700' : 'bg-white/25 text-white' }}">{{ $pendingCount }}</span>
    </a>
    <a href="{{ route('admin.line-changes.index', ['tab' => 'decided']) }}"
        class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors
            {{ $currentTab === 'decided' ? 'border-brand-500 bg-brand-500 text-white' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50' }}">
        Decided
        <span class="inline-flex items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-bold
            {{ $currentTab === 'decided' ? 'bg-white/25 text-white' : 'bg-gray-100 text-gray-700' }}">{{ $decidedCount }}</span>
    </a>
</div>

<div class="rounded-2xl border border-gray-200 bg-white">
    @if($rows->isEmpty())
    <div class="p-8 text-center text-sm text-gray-500">
        {{ $currentTab === 'decided' ? 'No decided requests yet.' : 'No pending line-change requests.' }}
    </div>
    @else
    <table class="w-full text-sm">
        <thead class="text-left text-xs uppercase tracking-wider text-gray-500 border-b border-gray-200">
            <tr>
                <th class="px-5 py-3">Requester</th>
                <th class="px-5 py-3">Current parent</th>
                <th class="px-5 py-3">Requested parent</th>
                <th class="px-5 py-3">Requested</th>
                <th class="px-5 py-3">Status</th>
                <th class="px-5 py-3 text-right">Action</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
            <tr class="border-b border-gray-100 last:border-0">
                <td class="px-5 py-3 font-mono font-bold text-brand-600 tracking-widest">{{ $row->distributor?->adn ?? '—' }}</td>
                <td class="px-5 py-3 font-mono text-gray-700">{{ $row->fromPlacementParent?->adn ?? '—' }}</td>
                <td class="px-5 py-3 font-mono text-gray-700">{{ $row->toPlacementParent?->adn ?? '—' }}</td>
                <td class="px-5 py-3 text-gray-700">{{ $row->requested_at->format('d M Y H:i') }}</td>
                <td class="px-5 py-3 text-gray-700">{{ ucfirst($row->status) }}</td>
                <td class="px-5 py-3 text-right">
                    <a href="{{ route('admin.line-changes.show', $row->id) }}"
                        class="inline-flex items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-3 py-1.5 text-xs transition-colors">
                        {{ $row->status === 'pending' ? 'Review →' : 'View →' }}
                    </a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif
</div>

<div class="mt-4">{{ $rows->links() }}</div>

@endsection
```

- [ ] **Step 6: Create the admin show view**

Create `resources/views/admin/line-change/show.blade.php`:

```blade
@extends('admin.layouts.admin')
@section('title', 'Line-change review')
@section('heading', 'Line-change review')

@section('content')

@if($errors->any())
<div class="rounded-xl border border-red-200 bg-red-50 p-4 mb-6 text-sm text-red-700">
    @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
</div>
@endif

<div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900">
    <p class="font-semibold mb-1">Binary placement change only</p>
    <p class="leading-relaxed">Approving this request moves the distributor's position in the binary
        tree under the requested parent. Their <strong>sponsor is not changed</strong>.</p>
</div>

<div class="rounded-2xl border border-gray-200 bg-white p-6 mb-6">
    <p class="text-xs text-gray-500 uppercase tracking-wider mb-3">Request</p>
    <dl class="text-sm grid grid-cols-2 gap-y-2">
        <dt class="text-gray-600">Requester ADN</dt>
        <dd class="font-mono font-bold text-brand-600 tracking-widest">{{ $lcr->distributor?->adn ?? '—' }}</dd>

        <dt class="text-gray-600">Requester email</dt>
        <dd class="text-gray-900">{{ $lcr->distributor?->user?->email ?? '—' }}</dd>

        <dt class="text-gray-600">Current placement parent</dt>
        <dd class="font-mono text-gray-900">{{ $lcr->fromPlacementParent?->adn ?? '—' }}</dd>

        <dt class="text-gray-600">Requested placement parent</dt>
        <dd class="font-mono text-gray-900">{{ $lcr->toPlacementParent?->adn ?? '—' }}</dd>

        <dt class="text-gray-600">Reason given</dt>
        <dd class="text-gray-900">{{ $lcr->reason ?: '—' }}</dd>

        <dt class="text-gray-600">Requested at</dt>
        <dd class="text-gray-900">{{ $lcr->requested_at->format('d M Y H:i') }}</dd>

        <dt class="text-gray-600">Status</dt>
        <dd class="text-gray-900">{{ ucfirst($lcr->status) }}</dd>

        @if($lcr->status !== 'pending')
        <dt class="text-gray-600">Decision note</dt>
        <dd class="text-gray-900">{{ $lcr->decision_note ?: '—' }}</dd>
        @endif
    </dl>
</div>

@if($lcr->status === 'pending')
    @if($freeSides === [])
    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900 mb-6">
        <p class="font-semibold mb-1">No free leg under the requested parent</p>
        <p>Both legs are taken, so this move cannot be approved. Reject it with a reason below.</p>
    </div>
    @else
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <form method="POST" action="{{ route('admin.line-changes.approve', $lcr->id) }}"
            class="rounded-2xl border border-green-200 bg-green-50 p-6 space-y-3"
            data-confirm="Approve this line change and move the placement now?"
            data-confirm-title="Confirm approval"
            data-confirm-impact="Moves the distributor's binary placement under the requested parent on the chosen leg. Sponsor is unchanged. This cannot be undone via this screen.">
            @csrf
            <p class="text-base font-semibold text-green-800">Approve & move placement</p>
            <label class="block text-xs text-green-800">
                Leg to place on
                <x-help-tip text="Which side of the requested parent to attach the distributor to. Only free legs are listed; the first free leg is preselected." />
            </label>
            <select name="chosen_side" required
                class="w-full rounded-lg border border-green-300 bg-white px-3 py-2 text-sm focus:border-green-500 focus:ring-green-500">
                @foreach($freeSides as $s)
                    <option value="{{ $s }}">{{ $s === 'L' ? 'Left (L)' : 'Right (R)' }}</option>
                @endforeach
            </select>
            <button type="submit"
                class="w-full inline-flex justify-center items-center rounded-lg bg-green-600 hover:bg-green-700 text-white font-medium px-4 py-2.5 text-sm transition-colors">
                Approve
            </button>
        </form>

        <form method="POST" action="{{ route('admin.line-changes.reject', $lcr->id) }}"
            class="rounded-2xl border border-red-200 bg-red-50 p-6 space-y-3"
            data-confirm="Reject this line-change request?"
            data-confirm-title="Confirm rejection"
            data-confirm-impact="The distributor is emailed your reason. No placement changes.">
            @csrf
            <p class="text-base font-semibold text-red-800">Reject request</p>
            <label class="block text-xs text-red-800">
                Reason
                <x-help-tip text="Sent verbatim to the distributor. 8–1024 characters." />
            </label>
            <textarea name="decision_note" required minlength="8" maxlength="1024" rows="3"
                class="w-full rounded-lg border border-red-300 bg-white px-3 py-2 text-sm focus:border-red-500 focus:ring-red-500"
                placeholder="e.g. The requested parent is not eligible for this move."></textarea>
            <button type="submit"
                class="w-full inline-flex justify-center items-center rounded-lg bg-red-600 hover:bg-red-700 text-white font-medium px-4 py-2.5 text-sm transition-colors">
                Reject
            </button>
        </form>
    </div>
    @endif
@endif

<a href="{{ route('admin.line-changes.index') }}" class="inline-block mt-6 text-sm text-gray-500 hover:text-gray-700">
    ← Back to queue
</a>

<x-confirm-modal />

@endsection
```

- [ ] **Step 7: Run the feature test to verify it passes**

Run: `vendor/bin/pest tests/Modules/Admin/AdminLineChangeControllerTest.php`
Expected: PASS (ALCC-01, 02, 03).

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add app/Modules/Admin/Http/Controllers/AdminLineChangeController.php routes/web.php resources/views/admin/line-change/ tests/Modules/Admin/AdminLineChangeControllerTest.php
git commit -m "$(cat <<'EOF'
feat(admin): line-change review queue with approve/reject + side picker

Approve moves binary placement; reject records a note. Confirmation modals
and help tips per platform UI convention.

Compliance-Review: pending
EOF
)"
```

---

## Task 11: Add an admin nav link + final regression

**Files:**
- Modify: the admin sidebar/nav partial (find it in Step 1).

- [ ] **Step 1: Locate the admin nav and add a link**

Run: `grep -rn "admin.kyc.index" resources/views/admin/layouts resources/views/admin/partials 2>/dev/null`

In the file that renders the admin nav (where the KYC link lives), add a sibling link:

```blade
<a href="{{ route('admin.line-changes.index') }}"
   class="{{ request()->routeIs('admin.line-changes.*') ? 'bg-brand-50 text-brand-700' : 'text-gray-700 hover:bg-gray-50' }} block rounded-lg px-3 py-2 text-sm font-medium">
    Line changes
</a>
```

Match the exact classes/markup of the neighbouring links in that file.

- [ ] **Step 2: Commit the nav link**

```bash
git add resources/views/admin
git commit -m "feat(admin): add line-change queue to admin nav"
```

- [ ] **Step 3: Run the full genealogy + admin suites**

Run: `vendor/bin/pest tests/Modules/Genealogy tests/Modules/Admin`
Expected: PASS — all line-change tests green, no regressions.

- [ ] **Step 4: Static analysis**

Run: `vendor/bin/phpstan analyse --memory-limit=512M` (or the project's larastan command, e.g. `composer larastan`)
Expected: level 7 passes for the changed files. Fix any reported issues.

- [ ] **Step 5: Full test suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 6: Final commit (if static analysis required fixes)**

```bash
vendor/bin/pint --dirty
git add -A
git commit -m "chore(genealogy): satisfy larastan level 7 for line-change feature"
```

---

## Self-Review Notes (author)

- **Spec coverage:** §1 semantics → Tasks 4/5 + views; §2 background → reflected in renames; §3 eligibility → Task 4 (all 7 rules, with new exceptions Task 3); §4 schema → Task 1 + model Task 2; §5 approval execution → Task 5 (slot re-check, side resolve, depth, closure rebuild, audit, event); reject → Task 6; §6 admin UI → Task 10 (+nav Task 11); §7 emails → Task 7 (request→admins+requester; approve→requester+new parent; reject→requester); §8 distributor view → Task 9 (relabel, already-used panel, approved/rejected states); §9 tests → Tasks 4–10; §10 compliance → audit logs in every service; platform UI conventions → Tasks 8–10.
- **`side_chosen_by`:** reuses `referral_explicit` (existing enum) — no enum migration, per the spec correction.
- **Placement Strategy:** not referenced (removed by ADR-0003); side defaults to first free leg.
- **Type consistency:** service signatures use `toPlacementParentId`; event field `toPlacementParentId`; columns `to_placement_parent_id` — consistent across Tasks 1, 2, 4, 5, 7, 9, 10.
- **Event listener wiring (Task 7 Step 9):** verify-then-register; the multi-handler listener is registered explicitly because auto-discovery binds one event per `handle`.
