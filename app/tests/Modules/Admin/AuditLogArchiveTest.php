<?php

declare(strict_types=1);

/**
 * Monthly audit-log archives on the audit-archives disk. Export only: every
 * test that archives also proves the audit_log rows are still there.
 */

use App\Modules\Admin\Notifications\DatabaseBackupStatusNotification;
use App\Modules\Admin\Services\AuditLogArchiveService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('audit-archives');
    Storage::fake('db-backups');
    Carbon::setTestNow(Carbon::create(2026, 11, 19, 12, 0, 0, 'Asia/Kolkata'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function auditArchiveStaff(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

/** An audit row stamped at an IST wall-clock time, linked into the chain by the model. */
function auditArchiveRow(string $istTime, string $action = 'test.action', array $details = ['k' => 'v']): AuditLog
{
    $row = new AuditLog([
        'action' => $action,
        'subject_type' => 'test',
        'subject_id' => 1,
        'before_hash' => AuditLog::digest('before'),
        'after_hash' => AuditLog::digest('after'),
        'details' => $details,
        'ip' => '127.0.0.1',
    ]);
    $row->created_at = Carbon::parse($istTime.'.123', 'Asia/Kolkata')->timezone((string) config('app.timezone'));
    $row->save();

    return $row;
}

/** @return list<array<string, mixed>> */
function auditArchiveLines(string $file): array
{
    $raw = (string) gzdecode(Storage::disk('audit-archives')->get($file));

    return array_map(
        static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", $raw), static fn (string $l): bool => $l !== '')),
    );
}

it('archives a month into one file with a manifest and the rows in id order', function (): void {
    $inside = auditArchiveRow('2026-10-15 10:00:00', 'a.first', ['nested' => ['x' => 1]]);
    $lastSecond = auditArchiveRow('2026-10-31 23:59:59', 'b.last_second');
    $nextMonth = auditArchiveRow('2026-11-01 00:00:00', 'c.next_month');
    $before = auditArchiveRow('2026-09-30 23:59:59', 'd.previous_month');
    $total = AuditLog::count();

    $archive = app(AuditLogArchiveService::class)->archiveMonth('2026-10');

    expect($archive->file)->toBe('arovolife-testing-audit-log-2026-10.jsonl.gz')
        ->and($archive->rows)->toBe(2)
        ->and(Storage::disk('audit-archives')->exists($archive->file))->toBeTrue()
        ->and(AuditLog::count())->toBe($total);

    $lines = auditArchiveLines($archive->file);
    expect($lines)->toHaveCount(3);

    [$manifest, $one, $two] = $lines;
    expect($manifest)->toMatchArray([
        'manifest' => true,
        'environment' => 'testing',
        'month' => '2026-10',
        'timezone' => 'Asia/Kolkata',
        'rows' => 2,
        'first_id' => $inside->id,
        'last_id' => $lastSecond->id,
        'first_prev_hash' => bin2hex((string) AuditLog::find($inside->id)->getRawOriginal('prev_hash')),
        'last_row_hash' => bin2hex((string) AuditLog::find($lastSecond->id)->getRawOriginal('row_hash')),
    ])->and($manifest['generated_at'])->toBeString();

    expect([$one['id'], $two['id']])->toBe([$inside->id, $lastSecond->id])
        ->and($one['action'])->toBe('a.first')
        ->and($one['details'])->toBe(['nested' => ['x' => 1]])
        ->and($one['row_hash'])->toBe(bin2hex((string) AuditLog::find($inside->id)->getRawOriginal('row_hash')))
        ->and($one['row_hash'])->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($two['prev_hash'])->toBe($one['row_hash'])
        ->and($one['created_at'])->toBe(DB::table('audit_log')->where('id', $inside->id)->value('created_at'));

    // Neither the 1st at 00:00:00 IST nor September belongs to October.
    expect(collect($lines)->pluck('action')->all())->not->toContain('c.next_month')->not->toContain('d.previous_month');
    expect($nextMonth->id)->toBeGreaterThan($lastSecond->id)->and($before->id)->toBeGreaterThan($lastSecond->id);
});

it('writes details as a JSON value, not a string', function (): void {
    auditArchiveRow('2026-10-02 09:00:00', 'x', ['a' => 1]);

    $archive = app(AuditLogArchiveService::class)->archiveMonth('2026-10');
    $raw = (string) gzdecode(Storage::disk('audit-archives')->get($archive->file));

    expect($raw)->toContain('"details":{"a":1}');
});

it('leaves an existing archive untouched without force and rewrites it with force', function (): void {
    auditArchiveRow('2026-10-02 09:00:00');
    $service = app(AuditLogArchiveService::class);
    $file = $service->archiveMonth('2026-10')->file;

    Storage::disk('audit-archives')->put($file, 'sentinel');
    $again = $service->archiveMonth('2026-10');
    expect($again->rows)->toBeNull()
        ->and(Storage::disk('audit-archives')->get($file))->toBe('sentinel');

    $forced = $service->archiveMonth('2026-10', true);
    expect($forced->rows)->toBe(1)
        ->and(auditArchiveLines($file))->toHaveCount(2);
});

it('refuses a month that has not ended yet', function (string $month): void {
    app(AuditLogArchiveService::class)->archiveMonth($month);
})->with(['2026-11', '2026-12'])->throws(RuntimeException::class);

it('refuses a malformed month', function (): void {
    app(AuditLogArchiveService::class)->archiveMonth('Oct-2026');
})->throws(RuntimeException::class);

it('writes a manifest-only file for a month with no rows', function (): void {
    $archive = app(AuditLogArchiveService::class)->archiveMonth('2026-08');

    $lines = auditArchiveLines($archive->file);
    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toMatchArray(['rows' => 0, 'first_id' => null, 'last_id' => null, 'last_row_hash' => null]);
});

it('lists pending months from the earliest row up to last month', function (): void {
    expect(app(AuditLogArchiveService::class)->pendingMonths())->toBe([]);

    auditArchiveRow('2026-08-20 09:00:00');
    auditArchiveRow('2026-11-05 09:00:00');
    $service = app(AuditLogArchiveService::class);

    expect($service->pendingMonths())->toBe(['2026-08', '2026-09', '2026-10']);

    $service->archiveMonth('2026-09');
    expect($service->pendingMonths())->toBe(['2026-08', '2026-10']);
});

it('archives every pending month by command, exits 0 and deletes nothing', function (): void {
    auditArchiveRow('2026-08-20 09:00:00');
    auditArchiveRow('2026-10-20 09:00:00');
    $total = AuditLog::count();

    $this->artisan('audit-log:archive')->assertSuccessful();

    expect(collect(Storage::disk('audit-archives')->files())->sort()->values()->all())->toBe([
        'arovolife-testing-audit-log-2026-08.jsonl.gz',
        'arovolife-testing-audit-log-2026-09.jsonl.gz',
        'arovolife-testing-audit-log-2026-10.jsonl.gz',
    ])->and(auditArchiveLines('arovolife-testing-audit-log-2026-09.jsonl.gz'))->toHaveCount(1)
        ->and(AuditLog::count())->toBe($total)
        ->and(app(AuditLogArchiveService::class)->pendingMonths())->toBe([]);

    $this->artisan('audit-log:archive')->assertSuccessful();
});

it('rejects a bad --month and fails when a month cannot be archived', function (): void {
    $this->artisan('audit-log:archive --month=nope')->assertFailed();
    $this->artisan('audit-log:archive --month=2026-11')->assertFailed();
    expect(Storage::disk('audit-archives')->files())->toBe([]);
});

it('downloads an archive for admin and developer and audit-logs it', function (string $role): void {
    auditArchiveRow('2026-10-02 09:00:00');
    $file = app(AuditLogArchiveService::class)->archiveMonth('2026-10')->file;
    $user = auditArchiveStaff($role);

    $location = (string) $this->actingAs($user)->get(route('admin.database-backups.audit.download', $file))
        ->assertRedirect()->headers->get('Location');
    expect($location)->toContain($file)->toContain('expiration=');

    $audit = AuditLog::where('action', 'admin.audit_archive_downloaded')->sole();
    expect($audit->actor_id)->toBe($user->id)->and($audit->details['file'])->toBe($file);
})->with(['admin', 'developer']);

it('answers 404 to scoped staff and for unknown or invalid archive names', function (): void {
    auditArchiveRow('2026-10-02 09:00:00');
    $file = app(AuditLogArchiveService::class)->archiveMonth('2026-10')->file;
    Storage::disk('db-backups')->put('arovolife-testing-20261018-234500-scheduled.sql.gz', 'x');

    foreach (['admin-operations', 'admin-finance', 'admin-compliance'] as $role) {
        $this->actingAs(auditArchiveStaff($role))->get(route('admin.database-backups.audit.download', $file))->assertNotFound();
    }

    $developer = auditArchiveStaff('developer');
    $this->actingAs($developer)->get('/admin/database-backups/audit-log/arovolife-testing-audit-log-2020-01.jsonl.gz/download')->assertNotFound();
    $this->actingAs($developer)->get('/admin/database-backups/audit-log/..%2F.env/download')->assertNotFound();
    $this->actingAs($developer)->get('/admin/database-backups/audit-log/arovolife-testing-20261018-234500-scheduled.sql.gz/download')->assertNotFound();
    $this->actingAs($developer)->get('/admin/database-backups/'.$file.'/download')->assertNotFound();

    expect(AuditLog::where('action', 'admin.audit_archive_downloaded')->count())->toBe(0);
});

it('shows the audit log archives section to admin and developer', function (string $role): void {
    auditArchiveRow('2026-10-02 09:00:00');
    $file = app(AuditLogArchiveService::class)->archiveMonth('2026-10')->file;

    $response = $this->actingAs(auditArchiveStaff($role))->get(route('admin.database-backups.index'))
        ->assertOk()
        ->assertSee('Audit log archives')
        ->assertSee($file)
        ->assertSee('Oct 2026')
        ->assertSee(route('admin.database-backups.audit.download', $file), false);

    if ($role === 'admin') {
        expect(strtolower($response->getContent()))->not->toContain('restore')->not->toContain('developer');
    }
})->with(['admin', 'developer']);

it('shows the empty state when there are no archives', function (): void {
    $this->actingAs(auditArchiveStaff('admin'))->get(route('admin.database-backups.index'))
        ->assertOk()
        ->assertSee('No audit log archives on this environment yet.');
});

it('adds the pending-months line to the backup mail only when months are waiting', function (?int $pending, bool $shown): void {
    $notification = new DatabaseBackupStatusNotification(
        succeeded: true,
        environment: 'testing',
        takenAt: '19 Nov 2026, 11:45 PM',
        file: 'f.sql.gz',
        bytes: 1024,
        durationSeconds: 1.0,
        heldCount: 1,
        prunedCount: 0,
        auditMonthsPending: $pending,
    );

    $lines = implode("\n", $notification->toMail(auditArchiveStaff('admin'))->introLines);

    expect(str_contains($lines, 'Audit log archive: '.$pending.' month(s) not yet archived'))->toBe($shown)
        ->and(str_contains($lines, 'Audit log archive'))->toBe($shown);
})->with([[3, true], [0, false], [null, false]]);
