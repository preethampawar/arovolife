# Registration Drafts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist registration wizard state to a `registration_drafts` DB table so users can resume mid-flow after session loss, without logging in again.

**Architecture:** A `DraftStateService` mirrors `WizardStateService` to the DB after every step POST. Resume is cookie-first (`av_draft`, 7-day TTL) on the same browser; a signed email link handles cross-device resume. The draft is deleted on finalise. Sensitive payload is `Crypt::encryptString()`-encrypted as a single JSON blob.

**Tech Stack:** Laravel 13, PHP 8.4, Pest PHP, MySQL BINARY(32) for token hash, `Cookie::queue()` / `withCookie()`, `URL::temporarySignedRoute()`.

---

## File Map

| Action | Path |
|---|---|
| Create | `app/app/Modules/Identity/Database/Migrations/2026_05_13_000001_create_registration_drafts_table.php` |
| Create | `app/app/Modules/Identity/Models/RegistrationDraft.php` |
| Create | `app/app/Modules/Identity/Services/DraftStateService.php` |
| Create | `app/app/Modules/Identity/Notifications/DraftResumeNotification.php` |
| Create | `app/app/Modules/Identity/Http/Controllers/Registration/DraftResumeController.php` |
| Create | `app/Console/Commands/PurgeExpiredDraftsCommand.php` |
| Create | `app/resources/views/registration/_draft_notice.blade.php` |
| Create | `app/resources/views/registration/draft-conflict.blade.php` |
| Create | `app/tests/Modules/Identity/DraftStateServiceTest.php` |
| Create | `app/tests/Modules/Identity/DraftResumeFlowTest.php` |
| Modify | `app/app/Modules/Identity/Services/WizardStateService.php` — add `restore()` + static `stepRoute()` |
| Modify | `app/app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php` — 6 touch points |
| Modify | `app/app/Modules/Identity/Http/Middleware/EnsureRegistrationProgress.php` — cookie restore path |
| Modify | `app/routes/web.php` — add `register.resume` route |
| Modify | `app/bootstrap/app.php` — schedule `drafts:purge` daily |
| Modify | Step views (7 files) — include `_draft_notice` partial |

---

## Task 1: Migration — `registration_drafts` table

**Files:**
- Create: `app/app/Modules/Identity/Database/Migrations/2026_05_13_000001_create_registration_drafts_table.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_drafts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique('uniq_drafts_user')->constrained('users')->cascadeOnDelete();
            $table->binary('draft_token_hash')->nullable();
            $table->tinyInteger('current_step')->unsigned()->default(3);
            $table->unsignedBigInteger('sponsor_id');
            $table->unsignedBigInteger('placement_id');
            $table->enum('side_opt', ['L', 'R'])->nullable();
            $table->text('payload_enc');
            $table->dateTime('resume_link_sent_at', 3)->nullable();
            $table->dateTime('expires_at', 3);
            $table->dateTime('created_at', 3)->useCurrent();
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate();
            $table->index('expires_at', 'idx_drafts_expires');
        });

        DB::statement('ALTER TABLE registration_drafts MODIFY draft_token_hash BINARY(32) NOT NULL');
        DB::statement('ALTER TABLE registration_drafts ADD UNIQUE uniq_drafts_token (draft_token_hash)');
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_drafts');
    }
};
```

- [ ] **Step 2: Run migration and verify**

```bash
cd app && php artisan migrate
```

Expected: `Migrating: 2026_05_13_000001_create_registration_drafts_table` then `Migrated` line.

```bash
php artisan db:show --database=mysql 2>/dev/null || php artisan tinker --execute="Schema::getColumnListing('registration_drafts');"
```

Expected output includes: `id`, `user_id`, `draft_token_hash`, `current_step`, `sponsor_id`, `placement_id`, `side_opt`, `payload_enc`, `resume_link_sent_at`, `expires_at`, `created_at`, `updated_at`.

- [ ] **Step 3: Commit**

```bash
cd app && git add app/Modules/Identity/Database/Migrations/2026_05_13_000001_create_registration_drafts_table.php
git commit -m "$(cat <<'EOF'
feat(identity): add registration_drafts migration

Persists wizard state for resume-mid-flow. BINARY(32) token hash with
unique index; encrypted payload column; 7-day TTL enforced by expires_at.

Compliance-Review: compliance-officer
EOF
)"
```

---

## Task 2: `RegistrationDraft` model + `WizardStateService` extensions

**Files:**
- Create: `app/app/Modules/Identity/Models/RegistrationDraft.php`
- Modify: `app/app/Modules/Identity/Services/WizardStateService.php`

- [ ] **Step 1: Create the model**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RegistrationDraft extends Model
{
    protected $fillable = [
        'user_id',
        'draft_token_hash',
        'current_step',
        'sponsor_id',
        'placement_id',
        'side_opt',
        'payload_enc',
        'resume_link_sent_at',
        'expires_at',
    ];

    protected $casts = [
        'current_step' => 'integer',
        'sponsor_id' => 'integer',
        'placement_id' => 'integer',
        'expires_at' => 'datetime',
        'resume_link_sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
```

- [ ] **Step 2: Add `restore()` and `stepRoute()` to `WizardStateService`**

Add these two methods to `app/app/Modules/Identity/Services/WizardStateService.php` (after the existing `clear()` method):

```php
public function restore(
    int $userId,
    int $sponsorId,
    int $placementId,
    ?string $sideOpt,
    array $data,
    int $currentStep,
): void {
    $this->session->put(self::KEY, [
        'step'         => $currentStep,
        'user_id'      => $userId,
        'sponsor_id'   => $sponsorId,
        'placement_id' => $placementId,
        'side_opt'     => $sideOpt,
        'data'         => $data,
    ]);
}

public static function stepRoute(int $step): string
{
    return match (true) {
        $step <= 3  => 'register.orientation',
        $step === 4 => 'register.consent',
        $step === 5 => 'register.pan',
        $step === 6 => 'register.aadhaar',
        $step === 7 => 'register.bank',
        $step === 8 => 'register.personal',
        $step === 9 => 'register.documents',
        default     => 'register.complete',
    };
}
```

- [ ] **Step 3: Commit**

```bash
cd app && git add app/Modules/Identity/Models/RegistrationDraft.php app/Modules/Identity/Services/WizardStateService.php
git commit -m "feat(identity): add RegistrationDraft model and WizardStateService::restore()"
```

---

## Task 3: `DraftStateService`

**Files:**
- Create: `app/app/Modules/Identity/Services/DraftStateService.php`

- [ ] **Step 1: Write the failing test first**

Create `app/tests/Modules/Identity/DraftStateServiceTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Models\RegistrationDraft;
use App\Modules\Identity\Services\DraftStateService;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function draftSeedUser(): int
{
    return DB::table('users')->insertGetId([
        'email'           => 'draft'.uniqid().'@test.com',
        'phone_e164'      => '+919'.rand(100000000, 999999999),
        'password_hash'   => bcrypt('password'),
        'password_set_at' => now(),
        'status'          => 'pending',
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
}

test('create persists draft and returns 64-char hex token', function (): void {
    $userId = draftSeedUser();

    $token = app(DraftStateService::class)->create($userId, 1, 2, 'L', ['orientation' => ['quiz_q1' => 'A']]);

    expect($token)->toMatch('/^[0-9a-f]{64}$/')
        ->and(RegistrationDraft::where('user_id', $userId)->value('current_step'))->toBe(3)
        ->and(RegistrationDraft::where('user_id', $userId)->value('sponsor_id'))->toBe(1)
        ->and(RegistrationDraft::where('user_id', $userId)->exists())->toBeTrue();
});

test('create replaces an existing draft for the same user', function (): void {
    $userId = draftSeedUser();
    $svc = app(DraftStateService::class);

    $svc->create($userId, 1, 2, null, []);
    $svc->create($userId, 3, 4, 'R', []);

    expect(RegistrationDraft::where('user_id', $userId)->count())->toBe(1)
        ->and(RegistrationDraft::where('user_id', $userId)->value('sponsor_id'))->toBe(3);
});

test('resolveFromToken returns draft for a valid non-expired token', function (): void {
    $userId = draftSeedUser();
    $svc = app(DraftStateService::class);
    $token = $svc->create($userId, 1, 2, null, []);

    $draft = $svc->resolveFromToken($token);

    expect($draft)->not->toBeNull()
        ->and($draft->user_id)->toBe($userId);
});

test('resolveFromToken returns null when the draft has expired', function (): void {
    $userId = draftSeedUser();
    $svc = app(DraftStateService::class);
    $token = $svc->create($userId, 1, 2, null, []);

    RegistrationDraft::where('user_id', $userId)->update(['expires_at' => now()->subDay()]);

    expect($svc->resolveFromToken($token))->toBeNull();
});

test('resolveFromToken returns null for an unknown token', function (): void {
    expect(app(DraftStateService::class)->resolveFromToken(bin2hex(random_bytes(32))))->toBeNull();
});

test('sync updates current_step and re-encrypts payload', function (): void {
    $userId = draftSeedUser();
    $svc = app(DraftStateService::class);
    $svc->create($userId, 1, 2, null, []);

    $svc->sync($userId, 6, ['pan' => ['pan_number' => 'ABCDE1234F']]);

    expect(RegistrationDraft::where('user_id', $userId)->value('current_step'))->toBe(6);
});

test('findActiveByUserId returns null when no draft exists', function (): void {
    $userId = draftSeedUser();
    expect(app(DraftStateService::class)->findActiveByUserId($userId))->toBeNull();
});

test('findActiveByUserId returns draft when one exists', function (): void {
    $userId = draftSeedUser();
    $svc = app(DraftStateService::class);
    $svc->create($userId, 1, 2, null, []);

    expect($svc->findActiveByUserId($userId))->not->toBeNull();
});

test('delete removes the draft row', function (): void {
    $userId = draftSeedUser();
    $svc = app(DraftStateService::class);
    $svc->create($userId, 1, 2, null, []);

    $svc->delete($userId);

    expect(RegistrationDraft::where('user_id', $userId)->first())->toBeNull();
});

test('purgeExpired deletes only expired rows', function (): void {
    $userA = draftSeedUser();
    $userB = draftSeedUser();
    $svc = app(DraftStateService::class);
    $svc->create($userA, 1, 2, null, []);
    $svc->create($userB, 1, 2, null, []);

    RegistrationDraft::where('user_id', $userA)->update(['expires_at' => now()->subDay()]);

    expect($svc->purgeExpired())->toBe(1);
    expect(RegistrationDraft::where('user_id', $userA)->first())->toBeNull();
    expect(RegistrationDraft::where('user_id', $userB)->first())->not->toBeNull();
});

test('restoreToWizard populates wizard session from draft', function (): void {
    $userId = draftSeedUser();
    $svc = app(DraftStateService::class);
    $svc->create($userId, 10, 20, 'R', []);
    $svc->sync($userId, 6, ['pan' => ['pan_number' => 'ABCDE1234F']]);

    $draft = RegistrationDraft::where('user_id', $userId)->first();
    $wizard = app(WizardStateService::class);

    $svc->restoreToWizard($draft, $wizard);

    expect($wizard->currentStep())->toBe(6)
        ->and($wizard->sponsorId())->toBe(10)
        ->and($wizard->placementId())->toBe(20)
        ->and($wizard->placementSideOpt())->toBe('R')
        ->and($wizard->getStepData(5))->toBe(['pan_number' => 'ABCDE1234F']);
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd app && php artisan test tests/Modules/Identity/DraftStateServiceTest.php
```

Expected: All tests FAIL with `Class "App\Modules\Identity\Services\DraftStateService" not found` or similar.

- [ ] **Step 3: Implement `DraftStateService`**

Create `app/app/Modules/Identity/Services/DraftStateService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\RegistrationDraft;
use Illuminate\Support\Facades\Crypt;

final class DraftStateService
{
    private const TTL_DAYS = 7;

    public function create(
        int $userId,
        int $sponsorId,
        int $placementId,
        ?string $sideOpt,
        array $data,
    ): string {
        RegistrationDraft::where('user_id', $userId)->delete();

        $rawToken = bin2hex(random_bytes(32));

        RegistrationDraft::create([
            'user_id'           => $userId,
            'draft_token_hash'  => hash('sha256', $rawToken, true),
            'current_step'      => 3,
            'sponsor_id'        => $sponsorId,
            'placement_id'      => $placementId,
            'side_opt'          => $sideOpt,
            'payload_enc'       => Crypt::encryptString(json_encode($data)),
            'expires_at'        => now()->addDays(self::TTL_DAYS),
        ]);

        return $rawToken;
    }

    public function sync(int $userId, int $currentStep, array $data): void
    {
        RegistrationDraft::where('user_id', $userId)->update([
            'current_step' => $currentStep,
            'payload_enc'  => Crypt::encryptString(json_encode($data)),
        ]);
    }

    public function updatePlacement(int $userId, int $sponsorId, int $placementId, ?string $sideOpt): void
    {
        RegistrationDraft::where('user_id', $userId)->update([
            'sponsor_id'   => $sponsorId,
            'placement_id' => $placementId,
            'side_opt'     => $sideOpt,
        ]);
    }

    public function resolveFromToken(string $rawToken): ?RegistrationDraft
    {
        return RegistrationDraft::where('draft_token_hash', hash('sha256', $rawToken, true))
            ->active()
            ->first();
    }

    public function findActiveByUserId(int $userId): ?RegistrationDraft
    {
        return RegistrationDraft::where('user_id', $userId)->active()->first();
    }

    public function restoreToWizard(RegistrationDraft $draft, WizardStateService $wizard): void
    {
        $data = json_decode(Crypt::decryptString($draft->payload_enc), true) ?? [];

        $wizard->restore(
            userId:      $draft->user_id,
            sponsorId:   $draft->sponsor_id,
            placementId: $draft->placement_id,
            sideOpt:     $draft->side_opt,
            data:        $data,
            currentStep: $draft->current_step,
        );
    }

    public function delete(int $userId): void
    {
        RegistrationDraft::where('user_id', $userId)->delete();
    }

    public function purgeExpired(): int
    {
        return RegistrationDraft::where('expires_at', '<', now())->delete();
    }
}
```

- [ ] **Step 4: Run tests to confirm they pass**

```bash
cd app && php artisan test tests/Modules/Identity/DraftStateServiceTest.php
```

Expected: All 11 tests PASS.

- [ ] **Step 5: Commit**

```bash
cd app && git add app/Modules/Identity/Services/DraftStateService.php tests/Modules/Identity/DraftStateServiceTest.php
git commit -m "feat(identity): DraftStateService with full test coverage"
```

---

## Task 4: `PurgeExpiredDraftsCommand` + scheduler

**Files:**
- Create: `app/app/Console/Commands/PurgeExpiredDraftsCommand.php`
- Modify: `app/bootstrap/app.php`

- [ ] **Step 1: Create the command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Services\DraftStateService;
use Illuminate\Console\Command;

final class PurgeExpiredDraftsCommand extends Command
{
    protected $signature = 'drafts:purge';

    protected $description = 'Delete registration_drafts rows that have passed their 7-day TTL';

    public function handle(DraftStateService $drafts): int
    {
        $count = $drafts->purgeExpired();
        $this->info("Purged {$count} expired registration draft(s).");

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 2: Register command and schedule in `bootstrap/app.php`**

Find the `->withSchedule(` block in `app/bootstrap/app.php`. If it doesn't exist, find `->withCommands(` or add the schedule binding. Add:

```php
use Illuminate\Console\Scheduling\Schedule;

// Inside withSchedule callback:
$schedule->command('drafts:purge')->daily();
```

If `bootstrap/app.php` has no schedule block yet, add it to the `Application::configure()` chain:

```php
->withSchedule(static function (Schedule $schedule): void {
    $schedule->command('drafts:purge')->daily();
})
```

- [ ] **Step 3: Verify command runs**

```bash
cd app && php artisan drafts:purge
```

Expected: `Purged 0 expired registration draft(s).`

- [ ] **Step 4: Commit**

```bash
cd app && git add app/Console/Commands/PurgeExpiredDraftsCommand.php bootstrap/app.php
git commit -m "feat(identity): PurgeExpiredDraftsCommand scheduled daily"
```

---

## Task 5: `DraftResumeNotification`

**Files:**
- Create: `app/app/Modules/Identity/Notifications/DraftResumeNotification.php`

- [ ] **Step 1: Create the notification**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

final class DraftResumeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $draftId) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $resumeUrl = URL::temporarySignedRoute(
            'register.resume',
            now()->addDays(7),
            ['draft' => $this->draftId],
        );

        return (new MailMessage)
            ->subject('Continue your arovolife registration')
            ->greeting('Hello,')
            ->line('You started your arovolife registration but haven\'t finished yet.')
            ->line('Your progress is saved for 7 days. Click the button below to continue — no password needed.')
            ->action('Continue Registration', $resumeUrl)
            ->line('If you did not start a registration with arovolife, please ignore this email.');
    }
}
```

- [ ] **Step 2: Commit**

```bash
cd app && git add app/Modules/Identity/Notifications/DraftResumeNotification.php
git commit -m "feat(identity): DraftResumeNotification — email fallback for cross-device resume"
```

---

## Task 6: `DraftResumeController` + route

**Files:**
- Create: `app/app/Modules/Identity/Http/Controllers/Registration/DraftResumeController.php`
- Modify: `app/routes/web.php`

- [ ] **Step 1: Create the controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Registration;

use App\Modules\Identity\Models\RegistrationDraft;
use App\Modules\Identity\Services\DraftStateService;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class DraftResumeController extends Controller
{
    public function __construct(
        private readonly DraftStateService $drafts,
        private readonly WizardStateService $wizard,
    ) {}

    public function show(Request $request, RegistrationDraft $draft): RedirectResponse
    {
        if ($draft->expires_at->isPast()) {
            return redirect()->route('join.show')
                ->with('status', 'Your saved registration has expired. Please start again using your referral link.');
        }

        $takenSlots = DB::table('distributors')
            ->where('placement_parent_id', $draft->placement_id)
            ->whereNotNull('placement_side')
            ->count();

        if ($takenSlots >= 2) {
            Auth::loginUsingId($draft->user_id);
            $request->session()->regenerate();
            $this->drafts->restoreToWizard($draft, $this->wizard);

            return redirect()->route('join.show')
                ->with('status', 'The placement position from your original invitation is no longer available. Please choose a new one — all other details are saved.');
        }

        Auth::loginUsingId($draft->user_id);
        $request->session()->regenerate();
        $this->drafts->restoreToWizard($draft, $this->wizard);

        $rawToken = bin2hex(random_bytes(32));
        $draft->update(['draft_token_hash' => hash('sha256', $rawToken, true)]);

        return redirect()
            ->route(WizardStateService::stepRoute($draft->current_step))
            ->withCookie(cookie('av_draft', $rawToken, 7 * 24 * 60, '/', null, true, true));
    }
}
```

- [ ] **Step 2: Add route to `app/routes/web.php`**

Find the block containing `spouse.activate.*` routes (near line 74). Add after it:

```php
Route::get('/register/resume/{draft}', [DraftResumeController::class, 'show'])
    ->name('register.resume')
    ->middleware('signed');
```

Add the import at the top of `web.php`:

```php
use App\Modules\Identity\Http\Controllers\Registration\DraftResumeController;
```

- [ ] **Step 3: Verify the route is registered**

```bash
cd app && php artisan route:list --name=register.resume
```

Expected: One row showing `GET /register/resume/{draft}` with middleware `web, signed`.

- [ ] **Step 4: Commit**

```bash
cd app && git add app/Modules/Identity/Http/Controllers/Registration/DraftResumeController.php routes/web.php
git commit -m "feat(identity): DraftResumeController — signed-URL cross-device resume endpoint"
```

---

## Task 7: Wire draft creation into `handleAccount()` (step 2 POST)

**Files:**
- Modify: `app/app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php`

- [ ] **Step 1: Add `DraftStateService` constructor dependency**

In `RegistrationWizardController`, the constructor currently is:

```php
public function __construct(
    private readonly WizardStateService $wizard,
    private readonly RegistrationService $registrationService,
) {}
```

Change to:

```php
public function __construct(
    private readonly WizardStateService $wizard,
    private readonly RegistrationService $registrationService,
    private readonly DraftStateService $drafts,
) {}
```

Add the import at the top:
```php
use App\Modules\Identity\Models\RegistrationDraft;
use App\Modules\Identity\Notifications\DraftResumeNotification;
use App\Modules\Identity\Services\DraftStateService;
use Illuminate\Support\Facades\Notification;
```

- [ ] **Step 2: Replace the `return redirect()` at the end of `handleAccount()`**

Find the current last line of `handleAccount()`:

```php
        // Step 2 done; the next auth-gated step is Orientation.
        return redirect()->route('register.orientation');
```

Replace with:

```php
        $rawToken = $this->drafts->create(
            userId:      $user->id,
            sponsorId:   (int) $intent['sponsor_id'],
            placementId: (int) $intent['placement_id'],
            sideOpt:     $intent['side_opt'] ?? null,
            data:        [],
        );

        $draftId = (int) RegistrationDraft::where('user_id', $user->id)->value('id');
        Notification::send($user, new DraftResumeNotification($draftId));

        return redirect()->route('register.orientation')
            ->withCookie(cookie('av_draft', $rawToken, 7 * 24 * 60, '/', null, true, true));
```

- [ ] **Step 3: Run existing registration tests to confirm nothing broke**

```bash
cd app && php artisan test tests/Modules/Identity/RegistrationFlowTest.php
```

Expected: All tests PASS.

- [ ] **Step 4: Commit**

```bash
cd app && git add app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php
git commit -m "feat(identity): create registration draft + issue av_draft cookie on account creation"
```

---

## Task 8: Wire `sync()` into every step POST + `delete()` into `handleComplete()`

**Files:**
- Modify: `app/app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php`

Add the following private helper inside `RegistrationWizardController`:

```php
private function syncDraft(int $step): void
{
    if (($userId = Auth::id()) !== null) {
        $this->drafts->sync((int) $userId, $step + 1, $this->wizard->get()['data'] ?? []);
    }
}
```

- [ ] **Step 1: Add `syncDraft()` call after each `saveStepData()` in step POST handlers**

In each of the 7 POST handlers, add `$this->syncDraft($step)` immediately after `$this->wizard->saveStepData(...)`. The step numbers and locations are:

| Method | saveStepData call line (approx) | step arg |
|---|---|---|
| `handleOrientation()` | line 284 | `3` |
| `handleConsent()` | line 601 | `4` |
| `handlePan()` | line 395 | `5` |
| `handleAadhaar()` | line 437 | `6` |
| `handleBank()` | line 474 | `7` |
| `handlePersonal()` | line 339 | `8` |
| `handleDocuments()` | line 537 | `9` |

Example change for `handleOrientation()`:
```php
        $this->wizard->saveStepData(3, [
            'quiz_q1'          => $validated['quiz_q1'],
            'quiz_q2'          => $validated['quiz_q2'],
            'quiz_q3'          => $validated['quiz_q3'],
            'confirmed_watched'=> true,
        ]);
        $this->syncDraft(3);   // ← add this line
        return redirect()->route('register.consent');
```

Repeat for all 7 handlers.

- [ ] **Step 2: Wire `delete()` + `Cookie::forget` into `handleComplete()` success path**

Find the line in `handleComplete()`:
```php
        $this->wizard->clear();

        return redirect()->route('dashboard')->with('adn_issued', $result->distributorId);
```

Replace with:
```php
        $this->wizard->clear();

        if (($userId = Auth::id()) !== null) {
            $this->drafts->delete((int) $userId);
        }

        return redirect()->route('dashboard')
            ->with('adn_issued', $result->distributorId)
            ->withCookie(cookie()->forget('av_draft'));
```

Add the `Cookie` import at top if not present:
```php
use Illuminate\Support\Facades\Cookie;
```

- [ ] **Step 3: Run full registration tests**

```bash
cd app && php artisan test tests/Modules/Identity/
```

Expected: All tests PASS.

- [ ] **Step 4: Commit**

```bash
cd app && git add app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php
git commit -m "feat(identity): sync draft after each wizard step; delete draft on finalise"
```

---

## Task 9: `showAccount()` pre-population for returning user

**Files:**
- Modify: `app/app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php`

- [ ] **Step 1: Update `showAccount()` to detect and pass returning user**

Replace the current `showAccount()` body:

```php
    public function showAccount(Request $request): View|RedirectResponse
    {
        $intent = $this->wizard->intent();
        if ($intent === null) {
            return redirect('/contact-us?reason=referral_link_required');
        }

        $existingUser = null;
        $rawToken = $request->cookie('av_draft');
        if ($rawToken !== null) {
            $draft = $this->drafts->resolveFromToken($rawToken);
            if ($draft !== null) {
                $existingUser = $draft->user;
            }
        }

        return view('registration.step1-account', [
            'sponsorAdn'   => $intent['sponsor_adn'] ?? '',
            'placementAdn' => $intent['placement_adn'] ?? '',
            'sideOpt'      => $intent['side_opt'] ?? null,
            'existingUser' => $existingUser,
        ]);
    }
```

The Blade view (`resources/views/registration/step1-account.blade.php`) already receives `sponsorAdn`, `placementAdn`, `sideOpt`. The `$existingUser` variable, when non-null, means the form should pre-populate and show a "Welcome back" notice. Add this to the view:

```blade
@if(isset($existingUser) && $existingUser !== null)
<div class="mb-6 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
    <p class="font-medium">Welcome back.</p>
    <p class="mt-1">Your registration is still in progress. Enter your password to continue from where you left off.</p>
</div>
@endif
```

And on the name/email/phone fields, set the `value` attribute:
```blade
value="{{ old('full_name', $existingUser?->full_name ?? '') }}"
value="{{ old('email', $existingUser?->email ?? '') }}"
value="{{ old('phone_e164', $existingUser ? ltrim($existingUser->phone_e164, '+91') : '') }}"
```

The password field always stays blank (do not pre-populate).

- [ ] **Step 2: Commit**

```bash
cd app && git add app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php resources/views/registration/step1-account.blade.php
git commit -m "feat(identity): pre-populate account form for returning draft users"
```

---

## Task 10: `handleAccount()` — returning user authentication path

**Files:**
- Modify: `app/app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php`

- [ ] **Step 1: Write the failing feature test**

Create `app/tests/Modules/Identity/DraftResumeFlowTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Identity\Models\RegistrationDraft;
use App\Modules\Identity\Services\DraftStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function resumeSeedUser(string $password = 'password'): array
{
    $userId = DB::table('users')->insertGetId([
        'email'           => 'resume'.uniqid().'@test.com',
        'phone_e164'      => '+919'.rand(100000000, 999999999),
        'password_hash'   => Hash::make($password),
        'password_set_at' => now(),
        'full_name'       => 'Test User',
        'status'          => 'pending',
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    return ['id' => $userId, 'email' => DB::table('users')->where('id', $userId)->value('email')];
}

function resumeSeedSponsor(): int
{
    $userId = DB::table('users')->insertGetId([
        'email'           => 'sponsor'.uniqid().'@test.com',
        'phone_e164'      => '+919'.rand(100000000, 999999999),
        'password_hash'   => Hash::make('password'),
        'password_set_at' => now(),
        'status'          => 'active',
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    $sponsorId = DB::table('distributors')->insertGetId([
        'user_id'                    => $userId,
        'adn'                        => (string) rand(100000000, 999999999),
        'pan_hash'                   => random_bytes(32),
        'pan_last4'                  => '0000',
        'bank_account_enc'           => random_bytes(32),
        'bank_ifsc'                  => 'SBIN0000000',
        'sponsor_id'                 => 0,
        'placement_parent_id'        => 0,
        'placement_side'             => null,
        'placement_strategy_snapshot'=> 'default_left',
        'side_chosen_by'             => 'admin_default',
        'depth'                      => 0,
        'effective_date'             => now()->format('Y-m-d H:i:s.v'),
        'cooling_off_end_at'         => now()->addDays(30)->format('Y-m-d H:i:s.v'),
        'state'                      => 'TS',
        'is_primary_couple'          => 0,
        'created_at'                 => now()->format('Y-m-d H:i:s.v'),
        'updated_at'                 => now()->format('Y-m-d H:i:s.v'),
    ]);
    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    DB::table('tree_paths')->insert([
        ['ancestor_id' => $sponsorId, 'descendant_id' => $sponsorId, 'depth' => 0],
    ]);

    return $sponsorId;
}

test('returning user with correct password is authenticated and redirected to saved step', function (): void {
    $sponsorId = resumeSeedSponsor();
    $sponsorAdn = DB::table('distributors')->where('id', $sponsorId)->value('adn');

    $user = resumeSeedUser('secret123');
    $draft = app(DraftStateService::class)->create($user['id'], $sponsorId, $sponsorId, null, []);
    app(DraftStateService::class)->sync($user['id'], 5, ['orientation' => ['quiz_q1' => 'A']]);

    $this->withSession(['registration_intent' => [
        'sponsor_id'   => $sponsorId,
        'placement_id' => $sponsorId,
        'side_opt'     => null,
        'sponsor_adn'  => $sponsorAdn,
        'placement_adn'=> $sponsorAdn,
    ]])->post(route('register.post'), [
        'email'    => $user['email'],
        'password' => 'secret123',
    ])->assertRedirect(route('register.pan'));
});

test('returning user with wrong password gets error', function (): void {
    $sponsorId = resumeSeedSponsor();
    $sponsorAdn = DB::table('distributors')->where('id', $sponsorId)->value('adn');

    $user = resumeSeedUser('secret123');
    app(DraftStateService::class)->create($user['id'], $sponsorId, $sponsorId, null, []);

    $this->withSession(['registration_intent' => [
        'sponsor_id'   => $sponsorId,
        'placement_id' => $sponsorId,
        'side_opt'     => null,
        'sponsor_adn'  => $sponsorAdn,
        'placement_adn'=> $sponsorAdn,
    ]])->post(route('register.post'), [
        'email'    => $user['email'],
        'password' => 'wrongpass',
    ])->assertSessionHasErrors('password');
});

test('fully registered distributor gets email-taken error not draft resume', function (): void {
    $sponsorId = resumeSeedSponsor();
    $sponsorAdn = DB::table('distributors')->where('id', $sponsorId)->value('adn');

    $user = resumeSeedUser();

    $this->withSession(['registration_intent' => [
        'sponsor_id'   => $sponsorId,
        'placement_id' => $sponsorId,
        'side_opt'     => null,
        'sponsor_adn'  => $sponsorAdn,
        'placement_adn'=> $sponsorAdn,
    ]])->post(route('register.post'), [
        'full_name'             => 'Test',
        'email'                 => $user['email'],
        'phone_e164'            => '9876543210',
        'password'              => 'NewPass@123',
        'password_confirmation' => 'NewPass@123',
    ])->assertSessionHasErrors('email');
});
```

- [ ] **Step 2: Run to confirm the tests fail**

```bash
cd app && php artisan test tests/Modules/Identity/DraftResumeFlowTest.php
```

Expected: Tests FAIL (returning-user path not implemented yet).

- [ ] **Step 3: Implement the returning user path in `handleAccount()`**

At the very beginning of `handleAccount()`, after the `$intent === null` guard, add:

```php
        $emailInput = strtolower(trim((string) $request->input('email', '')));
        $existingUser = User::where('email', $emailInput)->first();

        if ($existingUser !== null) {
            $activeDraft = $this->drafts->findActiveByUserId($existingUser->id);

            if ($activeDraft !== null) {
                if (! Hash::check((string) $request->input('password', ''), $existingUser->password_hash)) {
                    return back()
                        ->withInput(['email' => $emailInput])
                        ->withErrors(['password' => 'Incorrect password. Try again or use the Forgot Password link below.']);
                }

                if ($intent !== null) {
                    $newSponsorId   = (int) $intent['sponsor_id'];
                    $newPlacementId = (int) $intent['placement_id'];
                    if ($activeDraft->sponsor_id !== $newSponsorId || $activeDraft->placement_id !== $newPlacementId) {
                        $this->drafts->updatePlacement($existingUser->id, $newSponsorId, $newPlacementId, $intent['side_opt'] ?? null);
                        $activeDraft->refresh();
                    }
                }

                Auth::login($existingUser);
                $request->session()->regenerate();
                $this->drafts->restoreToWizard($activeDraft, $this->wizard);

                $rawToken = bin2hex(random_bytes(32));
                $activeDraft->update(['draft_token_hash' => hash('sha256', $rawToken, true)]);

                return redirect()
                    ->route(WizardStateService::stepRoute($activeDraft->current_step))
                    ->withCookie(cookie('av_draft', $rawToken, 7 * 24 * 60, '/', null, true, true));
            }
        }
```

- [ ] **Step 4: Run all tests to confirm passing**

```bash
cd app && php artisan test tests/Modules/Identity/DraftResumeFlowTest.php tests/Modules/Identity/RegistrationFlowTest.php
```

Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
cd app && git add app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php tests/Modules/Identity/DraftResumeFlowTest.php
git commit -m "feat(identity): returning user auth path in handleAccount — no login required to resume"
```

---

## Task 11: `EnsureRegistrationProgress` — cookie-restore path

**Files:**
- Modify: `app/app/Modules/Identity/Http/Middleware/EnsureRegistrationProgress.php`

- [ ] **Step 1: Add `DraftStateService` dependency and update the session-lost block**

Change the constructor:
```php
    public function __construct(
        private readonly WizardStateService $wizard,
        private readonly DraftStateService $drafts,
    ) {}
```

Add the import:
```php
use App\Modules\Identity\Services\DraftStateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
```

Replace the existing session-lost guard block:

```php
        if ($state === null || $this->wizard->userId() === null) {
            return redirect()->route('login')->with(
                'status',
                'Your registration session expired. Please sign in if you completed registration, or use your referral link again to start over.'
            );
        }
```

With:

```php
        if ($state === null || $this->wizard->userId() === null) {
            $rawToken = $request->cookie('av_draft');

            if ($rawToken !== null && Auth::check()) {
                $draft = $this->drafts->resolveFromToken($rawToken);

                if ($draft !== null && $draft->user_id === (int) Auth::id()) {
                    $takenSlots = DB::table('distributors')
                        ->where('placement_parent_id', $draft->placement_id)
                        ->whereNotNull('placement_side')
                        ->count();

                    if ($takenSlots >= 2) {
                        return redirect()->route('join.show')
                            ->with('status', 'The placement position from your original invitation is no longer available. Please choose a new one — all other details are saved.');
                    }

                    $this->drafts->restoreToWizard($draft, $this->wizard);
                    $state = $this->wizard->get();
                }
            }

            if ($state === null || $this->wizard->userId() === null) {
                return redirect()->route('login')->with(
                    'status',
                    'Your registration session expired. Please sign in if you completed registration, or use your referral link again to start over.'
                );
            }
        }
```

- [ ] **Step 2: Run the full Identity test suite**

```bash
cd app && php artisan test tests/Modules/Identity/
```

Expected: All tests PASS.

- [ ] **Step 3: Commit**

```bash
cd app && git add app/Modules/Identity/Http/Middleware/EnsureRegistrationProgress.php
git commit -m "feat(identity): EnsureRegistrationProgress restores wizard from av_draft cookie on session loss"
```

---

## Task 12: `start()` conflict detection — new referral link vs existing draft

**Files:**
- Modify: `app/app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php`
- Create: `app/resources/views/registration/draft-conflict.blade.php`

- [ ] **Step 1: Create the conflict view**

```blade
{{-- resources/views/registration/draft-conflict.blade.php --}}
<x-guest-layout>
    <div class="max-w-lg mx-auto mt-12 rounded-lg border border-amber-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-gray-900">You have an incomplete registration</h1>
        <p class="mt-3 text-sm text-gray-600">
            You already started a registration under sponsor <strong>{{ $existingSponsorAdn }}</strong>.
            Your progress is saved until {{ $draftExpiresAt->format('d M Y') }}.
        </p>
        <p class="mt-2 text-sm text-gray-600">
            This new link is from sponsor <strong>{{ $newSponsorAdn }}</strong>.
            Would you like to continue your existing registration or start fresh?
        </p>

        <div class="mt-6 flex flex-col gap-3 sm:flex-row">
            <a href="{{ route('register.account.show') }}"
               class="inline-flex items-center justify-center rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-700">
                Continue existing registration
            </a>

            <form method="POST" action="{{ route('register.draft.discard') }}">
                @csrf
                <button type="submit"
                        class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Start fresh with new sponsor
                </button>
            </form>
        </div>
    </div>
</x-guest-layout>
```

- [ ] **Step 2: Add conflict detection in `start()` and a discard route**

In `start()`, after `$this->wizard->stashIntent(...)` is called and before the final `return redirect()->route('register.account.show')`, add:

```php
        $rawToken = $request->cookie('av_draft');
        if ($rawToken !== null) {
            $existingDraft = $this->drafts->resolveFromToken($rawToken);
            if ($existingDraft !== null) {
                $resolvedSponsorId = (int) ($intent['sponsor_id'] ?? 0);
                if ($existingDraft->sponsor_id !== $resolvedSponsorId || $existingDraft->placement_id !== (int) ($intent['placement_id'] ?? 0)) {
                    $existingDistributorAdn = \Illuminate\Support\Facades\DB::table('distributors')
                        ->where('id', $existingDraft->sponsor_id)
                        ->value('adn') ?? '—';

                    return view('registration.draft-conflict', [
                        'existingSponsorAdn' => $existingDistributorAdn,
                        'newSponsorAdn'      => $sponsorAdn,
                        'draftExpiresAt'     => $existingDraft->expires_at,
                    ]);
                }
            }
        }
```

Add a `discardDraft()` method and route for "Start fresh":

```php
    public function discardDraft(Request $request): RedirectResponse
    {
        $rawToken = $request->cookie('av_draft');
        if ($rawToken !== null) {
            $draft = $this->drafts->resolveFromToken($rawToken);
            if ($draft !== null) {
                $this->drafts->delete($draft->user_id);
            }
        }

        $this->wizard->clear();

        return redirect()->route('join.show')
            ->withCookie(cookie()->forget('av_draft'))
            ->with('status', 'Your previous registration has been discarded. Please enter your new referral details.');
    }
```

Add the route to `web.php` inside the guest middleware group:

```php
Route::post('/register/draft/discard', [RegistrationWizardController::class, 'discardDraft'])
    ->name('register.draft.discard');
```

- [ ] **Step 3: Run full tests**

```bash
cd app && php artisan test tests/Modules/Identity/
```

Expected: All tests PASS.

- [ ] **Step 4: Commit**

```bash
cd app && git add app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php resources/views/registration/draft-conflict.blade.php routes/web.php
git commit -m "feat(identity): sponsor/placement conflict screen when new referral link conflicts with active draft"
```

---

## Task 13: `_draft_notice` Blade partial + wire into step views

**Files:**
- Create: `app/resources/views/registration/_draft_notice.blade.php`
- Modify: `app/app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php`
- Modify: 7 step view files

- [ ] **Step 1: Create the notice partial**

```blade
{{-- resources/views/registration/_draft_notice.blade.php --}}
@if(isset($draftExpiresAt) && $draftExpiresAt !== null)
    @php $daysLeft = max(0, (int) now()->diffInDays($draftExpiresAt, false)); @endphp
    <div class="mt-8 rounded-md border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800" role="status" aria-live="polite">
        <p class="font-medium">Your progress is saved.</p>
        <p class="mt-1">
            @if($daysLeft <= 3 && $daysLeft > 0)
                <span class="font-semibold text-amber-700">{{ $daysLeft }} {{ Str::plural('day', $daysLeft) }} remaining</span> — please complete your registration soon.
            @elseif($daysLeft === 0)
                <span class="font-semibold text-red-700">Your registration expires today.</span>
            @else
                You can close this page and return within <strong>7 days</strong> to continue from where you left off.
            @endif
            After this window your draft expires and you will need to restart using your referral link.
        </p>
    </div>
@endif
```

- [ ] **Step 2: Add `draftExpiresAt()` private helper to the wizard controller**

Add inside `RegistrationWizardController`:

```php
    private function draftExpiresAt(): ?\Carbon\Carbon
    {
        if (($userId = Auth::id()) === null) {
            return null;
        }
        return $this->drafts->findActiveByUserId((int) $userId)?->expires_at;
    }
```

- [ ] **Step 3: Pass `$draftExpiresAt` from each `show*()` method**

In all 7 authenticated step `show*()` methods, add `'draftExpiresAt' => $this->draftExpiresAt()` to the `view()` call data array. For example `showOrientation()` changes from:

```php
        return view('registration.step2-orientation');
```

To:

```php
        return view('registration.step2-orientation', [
            'draftExpiresAt' => $this->draftExpiresAt(),
        ]);
```

Apply the same change to `showConsent()`, `showPan()`, `showAadhaar()`, `showBank()`, `showPersonal()`, `showDocuments()`.

- [ ] **Step 4: Include the partial in each step view**

Add `@include('registration._draft_notice')` just before the closing `</form>` tag (or at the bottom of the card content) in each of these 7 view files:
- `resources/views/registration/step2-orientation.blade.php`
- `resources/views/registration/step9-consent.blade.php`
- `resources/views/registration/step4-pan.blade.php`
- `resources/views/registration/step5-aadhaar.blade.php`
- `resources/views/registration/step6-bank.blade.php`
- `resources/views/registration/step3-personal.blade.php`
- `resources/views/registration/step7-documents.blade.php`

- [ ] **Step 5: Run tests and lint**

```bash
cd app && php artisan test tests/Modules/Identity/ && vendor/bin/pint --dirty
```

Expected: Tests PASS, Pint makes no changes (or only formatting fixes).

- [ ] **Step 6: Commit**

```bash
cd app && git add resources/views/registration/_draft_notice.blade.php resources/views/registration/ app/Modules/Identity/Http/Controllers/Registration/RegistrationWizardController.php
git commit -m "feat(identity): 7-day expiry notice banner on all registration wizard steps"
```

---

## Task 14: Full test run + Larastan

- [ ] **Step 1: Run the complete test suite**

```bash
cd app && php artisan test
```

Expected: All tests PASS. Note any failures and fix before continuing.

- [ ] **Step 2: Run static analysis**

```bash
cd app && vendor/bin/phpstan analyse --level=7
```

Expected: No errors. Fix any type errors in new files before proceeding.

- [ ] **Step 3: If Larastan flags issues, fix them inline then re-run**

Common issues to watch:
- Missing `@return` types on Eloquent scopes
- `?RegistrationDraft` nullable returns
- Cookie helper return type mismatch (use `Cookie::make()` if `cookie()` helper causes issues)

- [ ] **Step 4: Final commit**

```bash
cd app && git add -p
git commit -m "fix(identity): address Larastan level-7 findings in draft feature"
```

---

## Verification Checklist

Run these manually in the dev environment after all tasks complete:

- [ ] Complete step 2 → assert `registration_drafts` row exists + `av_draft` cookie in response + job in `jobs` table
- [ ] Complete step 5 (PAN) → assert `payload_enc` column updated + `current_step = 6`
- [ ] Manually `php artisan tinker --execute="session()->flush();"` then load `GET /register/kyc/aadhaar` → assert cookie restores session, step 6 rendered (not login redirect)
- [ ] Clear cookie + session → click signed email link → assert session restored, redirected to step 6, new cookie issued
- [ ] Return to `/register?sponsor=X&placement=Y` with different sponsor while draft active → assert conflict view shown
- [ ] Finalise step 10 → assert draft row deleted + `av_draft` cookie cleared
- [ ] `php artisan tinker --execute="App\Modules\Identity\Models\RegistrationDraft::query()->update(['expires_at' => now()->subDay()]);"` → run `php artisan drafts:purge` → assert row deleted
- [ ] Seed both slots under a placement node, trigger resume → assert redirect to join form with "placement unavailable" message
