<?php

declare(strict_types=1);

/**
 * CLAUDE.md: "any admin action, any KYC change, any settings change →
 * audit_log entry with before/after hashes" (QA finding F108).
 *
 * ARD-01: a settings change carries both digests, 32 raw bytes each
 * ARD-02: a create carries a NULL before and a real after
 * ARD-03: every audit write site in an admin, KYC or settings path names
 *         `before_hash` — the fence that stops the next one being added without
 */

use App\Modules\Catalog\Models\ProductCategory;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function ardStaff(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $staff = User::create([
        'full_name' => 'Audit Digest Staff',
        'email' => 'ard-'.uniqid().'@example.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('Adm1n!Pass#2026Test'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $staff->assignRole($role);

    return $staff;
}

/** Both digests present, and stored as the raw 32 bytes the columns hold. */
function ardExpectBothDigests(string $action): AuditLog
{
    /** @var AuditLog|null $row */
    $row = AuditLog::query()->where('action', $action)->latest('id')->first();

    expect($row)->not->toBeNull("no audit row for {$action}");
    expect($row->before_hash)->not->toBeNull("{$action} has no before_hash")
        ->and(strlen((string) $row->before_hash))->toBe(32)
        ->and($row->after_hash)->not->toBeNull("{$action} has no after_hash")
        ->and(strlen((string) $row->after_hash))->toBe(32)
        ->and($row->before_hash)->not->toBe($row->after_hash);

    return $row;
}

it('ARD-01: a settings change records the value on both sides', function () {
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->actingAs(ardStaff('developer'))
        ->post('/admin/settings/commerce.cooling_off.days', ['value' => '45'])
        ->assertRedirect(route('admin.settings'));

    ardExpectBothDigests('admin.settings.changed');
});

it('ARD-02: a create has no before-state and says so with NULL', function () {
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->actingAs(ardStaff('admin'))
        ->post(route('admin.catalog.categories.store'), [
            'slug' => 'ard-category',
            'name' => 'ARD Category',
            'sort' => 0,
            'status' => ProductCategory::STATUS_ACTIVE,
        ]);

    /** @var AuditLog|null $row */
    $row = AuditLog::query()->where('action', 'catalog.category.created')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->before_hash)->toBeNull()
        ->and(strlen((string) $row->after_hash))->toBe(32);
});

it('ARD-03: every admin, KYC and settings audit write names before_hash', function () {
    // A source fence, not a behaviour test: the sweep that added the digests
    // is only worth as much as the next call site remembering them. Anything
    // matching these paths must pass a `before_hash` — NULL is a fine answer
    // (a create has no before), an absent key is not.
    $root = base_path('app/Modules');
    $offenders = [];
    $scanned = 0;

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace($root.'/', '', $file->getPathname());
        $inScope = str_contains($path, '/Http/Controllers/Admin/')
            || str_starts_with($path, 'Admin/')
            || preg_match('#(AdminGrievance|AdminMessageReport|Kyc[A-Za-z]*(Controller|Submission)|LineChange|DistributorRequestService|BankDetailsController)#', $path) === 1;

        if (! $inScope) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        foreach (explode('AuditLog::create([', $source) as $i => $chunk) {
            if ($i === 0) {
                continue;
            }
            // The argument array ends at its closing `]);`.
            $call = substr($chunk, 0, (int) strpos($chunk, ']);'));
            $scanned++;
            if (! str_contains($call, "'before_hash'")) {
                $offenders[] = $path;
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([])
        // A fence that matches nothing passes forever. It covered 80+ call
        // sites when it was written; a collapse means the path filter broke.
        ->and($scanned)->toBeGreaterThan(60);
});
