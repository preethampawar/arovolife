<?php

declare(strict_types=1);

/**
 * Developer settings → Database backups: a daily gzipped mysqldump on the
 * db-backups disk, listed, downloadable and restorable by a developer only.
 *
 * Every client process is faked — no test runs mysqldump, imports, drops a
 * table or touches a real bucket. A restore is only ever *requested* here
 * (the detached command is asserted, never run).
 */

use App\Modules\Admin\Notifications\DatabaseBackupStatusNotification;
use App\Modules\Admin\Services\DatabaseBackupService;
use App\Modules\Admin\Services\SettingsFileBackupService;
use App\Modules\Admin\Services\UploadedFilesBackupService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const DB_BACKUP_COMPLETE_DUMP = "-- MariaDB dump 10.19\nCREATE TABLE `t` (`id` int);\nINSERT INTO `t` VALUES (1);\n-- Dump completed on 2026-11-19  5:00:01\n";

const DB_BACKUP_SETTINGS_PASSWORD = 'correct-horse-battery-staple-99';

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('db-backups');
    Storage::fake('public');

    // The settings file is always a fixture, never the real .env; no password
    // by default so only the tests that want a settings copy get one.
    $fixture = tempnam(sys_get_temp_dir(), 'env-fixture-');
    file_put_contents($fixture, "APP_NAME=arovolife\nAPP_KEY=base64:".base64_encode(str_repeat('fixture-key-material-', 3))."\nPII_ENCRYPTION_KEY=base64:aGVsbG8gd29ybGQgdGhpcyBpcyBhIHRlc3QgZml4dHVyZSBvbmx5Cg==\n");
    config(['db_backup.settings_file' => $fixture, 'db_backup.settings_password' => null]);
    $this->envFixture = $fixture;
});

afterEach(function (): void {
    @unlink($this->envFixture);
});

function dbBackupStaff(string $role, string $status = 'active'): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create(['status' => $status]);
    $user->assignRole($role);

    return $user;
}

function dbBackupName(Carbon $at, string $trigger = 'scheduled'): string
{
    return 'arovolife-testing-'.$at->copy()->timezone('Asia/Kolkata')->format('Ymd-His').'-'.$trigger.'.sql.gz';
}

function dbBackupPut(string $file, string $content = DB_BACKUP_COMPLETE_DUMP): void
{
    Storage::disk('db-backups')->put($file, (string) gzencode($content, 9));
}

function dbBackupFakeDump(string $output = DB_BACKUP_COMPLETE_DUMP, int $exitCode = 0): void
{
    Process::fake(['*mysqldump*' => Process::result(output: $output, errorOutput: $exitCode === 0 ? '' : 'mysqldump: Got error: 2013', exitCode: $exitCode)]);
}

it('lists the backups with their sizes for a developer', function (): void {
    $file = dbBackupName(Carbon::now()->subDay());
    dbBackupPut($file);
    Storage::disk('db-backups')->put('notes.txt', 'not a backup');

    $this->actingAs(dbBackupStaff('developer'))->get(route('admin.database-backups.index'))
        ->assertOk()
        ->assertSee($file)
        ->assertSee(Number::fileSize(Storage::disk('db-backups')->size($file), 1))
        ->assertSee(route('admin.database-backups.download', $file), false)
        ->assertSee(route('admin.database-backups.restore', $file), false)
        ->assertDontSee('notes.txt');
});

it('answers 404 on every route to scoped staff, never confirming the page', function (string $role): void {
    $file = dbBackupName(Carbon::now()->subDay());
    $settingsFile = dbBackupSettingsName(Carbon::now()->subDay());
    dbBackupPut($file);
    Storage::disk('db-backups')->put($settingsFile, 'zip-bytes');
    Process::fake();
    $user = dbBackupStaff($role);

    $this->actingAs($user)->get(route('admin.database-backups.index'))->assertNotFound();
    $this->actingAs($user)->post(route('admin.database-backups.store'))->assertNotFound();
    $this->actingAs($user)->get(route('admin.database-backups.download', $file))->assertNotFound();
    $this->actingAs($user)->get(route('admin.database-backups.settings.download', $settingsFile))->assertNotFound();
    $this->actingAs($user)->post(route('admin.database-backups.restore', $file), ['confirm_file' => $file, 'password' => 'password'])->assertNotFound();

    Process::assertNothingRan();
    expect(AuditLog::where('action', 'like', 'admin.%backup%')->count())->toBe(0)
        ->and(AuditLog::where('action', 'admin.database_restore_requested')->count())->toBe(0);
})->with(['admin-operations', 'admin-finance', 'admin-compliance']);

it('keeps a distributor out of every backup route', function (): void {
    $file = dbBackupName(Carbon::now()->subDay());
    dbBackupPut($file);
    Process::fake();
    $user = dbBackupStaff('distributor');

    // The admin area's own role gate answers first, for every page in it.
    $this->actingAs($user)->get(route('admin.database-backups.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.database-backups.store'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.database-backups.download', $file))->assertForbidden();
    $this->actingAs($user)->post(route('admin.database-backups.restore', $file), ['confirm_file' => $file, 'password' => 'password'])->assertForbidden();

    Process::assertNothingRan();
    expect(AuditLog::where('action', 'like', 'admin.%backup%')->count())->toBe(0);
});

it('shows an admin the page without any restore wording or the hidden role', function (): void {
    $file = dbBackupName(Carbon::now()->subDay());
    dbBackupPut($file);
    Storage::disk('db-backups')->put(dbBackupSettingsName(Carbon::now()->subDay()), 'zip-bytes');

    $response = $this->actingAs(dbBackupStaff('admin'))->get(route('admin.database-backups.index'))
        ->assertOk()
        ->assertSee($file)
        ->assertSee(route('admin.database-backups.download', $file), false)
        ->assertSee(route('admin.database-backups.store'), false)
        ->assertDontSee(route('admin.database-backups.restore', $file), false);

    expect(strtolower($response->getContent()))->not->toContain('restore')->not->toContain('developer');
});

it('hides a restore result banner from an admin', function (): void {
    Process::fake();
    app(DatabaseBackupService::class)->markRunning('restore', 'arovolife-testing-20260101-000000-manual.sql.gz');

    $this->actingAs(dbBackupStaff('admin'))->get(route('admin.database-backups.index'))
        ->assertOk()->assertDontSee('Restore running');
});

it('shows the settings link to admin and developer only', function (): void {
    $link = route('admin.database-backups.index');

    $this->actingAs(dbBackupStaff('developer'))->get(route('admin.settings'))
        ->assertOk()->assertSee($link, false)->assertSee('Logs')
        ->assertSee('or restore one');

    $this->actingAs(dbBackupStaff('admin'))->get(route('admin.settings'))
        ->assertOk()->assertSee($link, false)->assertSee('Database backups')
        ->assertSee('download one or take one now')
        ->assertDontSee('Download the application log')
        ->assertDontSee('restore');

    foreach (['admin-finance', 'admin-compliance', 'admin-operations'] as $role) {
        $response = $this->actingAs(dbBackupStaff($role))->get(route('admin.settings'));
        expect($response->getContent())->not->toContain($link);
    }
});

it('downloads a backup for admin and developer and audit-logs it', function (string $role): void {
    $file = dbBackupName(Carbon::now()->subDay());
    dbBackupPut($file);
    $developer = dbBackupStaff($role);

    // The faked disk offers temporary URLs, as S3 does, so this is the
    // short-lived signed-URL redirect, not a stream through PHP.
    $location = (string) $this->actingAs($developer)->get(route('admin.database-backups.download', $file))
        ->assertRedirect()->headers->get('Location');
    expect($location)->toContain($file)->toContain('expiration=');

    $audit = AuditLog::where('action', 'admin.database_backup_downloaded')->sole();
    expect($audit->actor_id)->toBe($developer->id)
        ->and($audit->details['file'])->toBe($file);
})->with(['admin', 'developer']);

it('serves only pattern-matching backups that exist on the disk', function (string $path): void {
    Storage::disk('db-backups')->put('notes.sql.gz', 'x');

    $this->actingAs(dbBackupStaff('developer'))->get('/admin/database-backups/'.$path.'/download')->assertNotFound();

    expect(AuditLog::where('action', 'admin.database_backup_downloaded')->count())->toBe(0);
})->with([
    'traversal' => '..%2F.env',
    'not a backup name' => 'notes.sql.gz',
    'missing file' => 'arovolife-testing-20010102-050000-scheduled.sql.gz',
]);

it('starts no restore when the typed file name or the password is wrong', function (array $input): void {
    $file = dbBackupName(Carbon::now()->subDay());
    dbBackupPut($file);
    Process::fake();

    $this->actingAs(dbBackupStaff('developer'))
        ->from(route('admin.database-backups.index'))
        ->post(route('admin.database-backups.restore', $file), $input + ['confirm_file' => $file, 'password' => 'password'])
        ->assertRedirect(route('admin.database-backups.index'))
        ->assertSessionHasErrors();

    Process::assertNothingRan();
    expect(AuditLog::where('action', 'admin.database_restore_requested')->count())->toBe(0);
})->with([
    'wrong file name' => [['confirm_file' => 'arovolife-testing-20010102-050000-manual.sql.gz']],
    'wrong password' => [['password' => 'not-my-password']],
]);

it('launches the detached restore command and audit-logs a valid request', function (): void {
    $file = dbBackupName(Carbon::now()->subDay());
    dbBackupPut($file);
    Process::fake();
    $developer = dbBackupStaff('developer');

    $this->actingAs($developer)
        ->post(route('admin.database-backups.restore', $file), ['confirm_file' => $file, 'password' => 'password'])
        ->assertRedirect(route('admin.database-backups.index'))
        ->assertSessionHasNoErrors();

    Process::assertRanTimes(fn (PendingProcess $process): bool => is_string($process->command)
        && str_starts_with($process->command, 'nohup ')
        && str_contains($process->command, "'db:backup:restore' '{$file}' '--actor={$developer->id}'")
        && str_ends_with($process->command, '> /dev/null 2>&1 &'), 1);

    $audit = AuditLog::where('action', 'admin.database_restore_requested')->sole();
    expect($audit->actor_id)->toBe($developer->id)
        ->and($audit->details['file'])->toBe($file);

    $this->actingAs($developer)->get(route('admin.database-backups.index'))->assertSee('Restore running');
});

it('refuses an admin a restore and starts nothing', function (): void {
    $file = dbBackupName(Carbon::now()->subDay());
    dbBackupPut($file);
    Process::fake();

    $this->actingAs(dbBackupStaff('admin'))
        ->post(route('admin.database-backups.restore', $file), ['confirm_file' => $file, 'password' => 'password'])
        ->assertNotFound();

    Process::assertNothingRan();
    expect(AuditLog::where('action', 'admin.database_restore_requested')->count())->toBe(0);
});

it('launches a detached manual backup from the page', function (string $role): void {
    Process::fake();

    $this->actingAs(dbBackupStaff($role))->post(route('admin.database-backups.store'))
        ->assertRedirect(route('admin.database-backups.index'));

    Process::assertRan(fn (PendingProcess $process): bool => is_string($process->command)
        && str_contains($process->command, "'db:backup:run' '--trigger=manual'"));
})->with(['admin', 'developer']);

it('stores a verified backup and prunes only matching files past retention', function (): void {
    $old = dbBackupName(Carbon::now()->subDays(31));
    $recent = dbBackupName(Carbon::now()->subDays(29));
    dbBackupPut($old);
    dbBackupPut($recent);
    Storage::disk('db-backups')->put('keep-me.sql.gz', 'not ours');
    Storage::disk('db-backups')->put('arovolife-testing-20000101-000000-other.sql.gz', 'not ours either');
    dbBackupFakeDump();
    Notification::fake();

    $this->artisan('db:backup:run', ['--no-mail' => true])->assertSuccessful();

    $created = collect(Storage::disk('db-backups')->files())
        ->reject(fn (string $f): bool => in_array($f, [$recent, 'keep-me.sql.gz', 'arovolife-testing-20000101-000000-other.sql.gz'], true))
        ->values();

    expect($created)->toHaveCount(1)
        ->and($created[0])->toMatch('/\Aarovolife-testing-\d{8}-\d{6}-scheduled\.sql\.gz\z/')
        ->and(gzdecode(Storage::disk('db-backups')->get($created[0])))->toBe(DB_BACKUP_COMPLETE_DUMP);

    Storage::disk('db-backups')->assertMissing($old);
    Storage::disk('db-backups')->assertExists([$recent, 'keep-me.sql.gz', 'arovolife-testing-20000101-000000-other.sql.gz']);

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && in_array('--single-transaction', $process->command, true)
        && array_filter($process->command, fn (string $arg): bool => str_contains($arg, 'password')) === []
        && array_key_exists('MYSQL_PWD', $process->environment));
    Notification::assertNothingSent();
});

it('uploads nothing and mails the failure when the dump is incomplete or mysqldump fails', function (string $output, int $exitCode): void {
    $old = dbBackupName(Carbon::now()->subDays(31));
    dbBackupPut($old);
    dbBackupFakeDump($output, $exitCode);
    Notification::fake();
    $admin = dbBackupStaff('admin');

    $this->artisan('db:backup:run')->assertFailed();

    expect(Storage::disk('db-backups')->files())->toBe([$old]);
    Notification::assertSentTo($admin, DatabaseBackupStatusNotification::class, function (DatabaseBackupStatusNotification $n) use ($admin): bool {
        $mail = $n->toMail($admin);

        return ! $n->succeeded
            && $mail->subject === 'Database backup FAILED'
            && str_contains(implode(' ', $mail->introLines), 'No new backup exists for today');
    });
})->with([
    'no completion trailer' => ["-- MariaDB dump 10.19\nCREATE TABLE `t` (`id` int);\n", 0],
    'non-zero exit' => [DB_BACKUP_COMPLETE_DUMP, 2],
]);

it('mails each active admin and developer individually after a scheduled run, with the size', function (): void {
    dbBackupFakeDump();
    Notification::fake();
    $admin = dbBackupStaff('admin');
    $developer = dbBackupStaff('developer');
    $frozenAdmin = dbBackupStaff('admin', 'frozen');
    $finance = dbBackupStaff('admin-finance');

    $this->artisan('db:backup:run')->assertSuccessful();

    $file = Storage::disk('db-backups')->files()[0];
    $size = Number::fileSize(Storage::disk('db-backups')->size($file), 1);

    foreach ([$admin, $developer] as $recipient) {
        Notification::assertSentTo($recipient, DatabaseBackupStatusNotification::class, function (DatabaseBackupStatusNotification $n, array $channels, User $notifiable) use ($recipient, $file, $size): bool {
            $mail = $n->toMail($recipient);

            return $notifiable->is($recipient)
                && $mail->subject === "Database backup succeeded — {$size}"
                && str_contains(implode(' ', $mail->introLines), $file);
        });
    }
    Notification::assertNotSentTo([$frozenAdmin, $finance], DatabaseBackupStatusNotification::class);
    Notification::assertSentTimes(DatabaseBackupStatusNotification::class, 2);
});

it('mails nobody for a manual run or with --no-mail', function (array $options): void {
    dbBackupFakeDump();
    Notification::fake();
    dbBackupStaff('admin');

    $this->artisan('db:backup:run', $options)->assertSuccessful();

    Notification::assertNothingSent();
})->with([
    'manual trigger' => [['--trigger' => 'manual']],
    'no mail' => [['--no-mail' => true]],
]);

function dbBackupExtraRecipients(string $value): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => 'notifications.backup_status_emails'],
        ['value' => $value, 'version' => 1, 'updated_at' => now()],
    );
}

it('also mails the extra addresses on a scheduled run, lowercased, alongside the staff', function (): void {
    dbBackupFakeDump();
    Notification::fake();
    $admin = dbBackupStaff('admin');
    $developer = dbBackupStaff('developer');
    dbBackupExtraRecipients('ops@example.com, Second@Example.com');

    $this->artisan('db:backup:run')->assertSuccessful();

    foreach (['ops@example.com', 'second@example.com'] as $address) {
        Notification::assertSentOnDemand(DatabaseBackupStatusNotification::class, fn (DatabaseBackupStatusNotification $n, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === $address);
    }
    Notification::assertSentTo([$admin, $developer], DatabaseBackupStatusNotification::class);
    Notification::assertSentTimes(DatabaseBackupStatusNotification::class, 4);
});

it('sends no second copy to an extra address that belongs to an active admin', function (): void {
    dbBackupFakeDump();
    Notification::fake();
    $admin = dbBackupStaff('admin');
    dbBackupExtraRecipients(strtoupper((string) $admin->email).', other@example.com');

    $this->artisan('db:backup:run')->assertSuccessful();

    Notification::assertSentTo($admin, DatabaseBackupStatusNotification::class);
    Notification::assertSentOnDemand(DatabaseBackupStatusNotification::class, fn (DatabaseBackupStatusNotification $n, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'other@example.com');
    Notification::assertSentTimes(DatabaseBackupStatusNotification::class, 2);
});

it('mails only the valid addresses when the stored extra list holds junk', function (): void {
    dbBackupFakeDump();
    Notification::fake();
    dbBackupExtraRecipients('not-an-email, ok@example.com');

    $this->artisan('db:backup:run')->assertSuccessful();

    Notification::assertSentOnDemand(DatabaseBackupStatusNotification::class, fn (DatabaseBackupStatusNotification $n, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'ok@example.com');
    Notification::assertSentTimes(DatabaseBackupStatusNotification::class, 1);
});

it('mails no extra address for a manual run or with --no-mail', function (array $options): void {
    dbBackupFakeDump();
    Notification::fake();
    dbBackupExtraRecipients('ops@example.com');

    $this->artisan('db:backup:run', $options)->assertSuccessful();

    Notification::assertNothingSent();
})->with([
    'manual trigger' => [['--trigger' => 'manual']],
    'no mail' => [['--no-mail' => true]],
]);

it('gives an extra address a mail with no button and no mention of the developer role', function (bool $succeeded): void {
    $notification = new DatabaseBackupStatusNotification(
        succeeded: $succeeded,
        environment: 'production',
        takenAt: '19 Nov 2026, 11:45 PM',
        file: $succeeded ? 'arovolife-production-20261119-234500-scheduled.sql.gz' : null,
        bytes: $succeeded ? 1_048_576 : null,
        durationSeconds: 12.3,
        heldCount: 30,
        prunedCount: 1,
        error: $succeeded ? null : 'mysqldump exited with code 2',
    );

    $mail = $notification->toMail(new AnonymousNotifiable);

    expect($mail->actionUrl)->toBeNull()
        ->and(strtolower($mail->subject.' '.implode(' ', $mail->introLines).' '.implode(' ', $mail->outroLines).' '.(string) $mail->render()))->not->toContain('developer');
})->with(['succeeded' => true, 'failed' => false]);

it('shows the stored extra recipients on the backups page for an admin', function (): void {
    dbBackupExtraRecipients('ops@example.com, second@example.com');

    $this->actingAs(dbBackupStaff('admin'))->get(route('admin.database-backups.index'))
        ->assertOk()
        ->assertSee('The daily result email also goes to: ops@example.com, second@example.com.')
        ->assertSee('Change in Settings');
});

it('invites other addresses on the backups page when none are set', function (): void {
    $this->actingAs(dbBackupStaff('admin'))->get(route('admin.database-backups.index'))
        ->assertOk()
        ->assertSee('Other addresses can receive the daily result email.');
});

it('links the backups page in the mail for admin and developer', function (bool $succeeded): void {
    $notification = new DatabaseBackupStatusNotification(
        succeeded: $succeeded,
        environment: 'production',
        takenAt: '19 Nov 2026, 11:45 PM',
        file: $succeeded ? 'arovolife-production-20261119-234500-scheduled.sql.gz' : null,
        bytes: $succeeded ? 1_048_576 : null,
        durationSeconds: 12.3,
        heldCount: 30,
        prunedCount: 1,
        error: $succeeded ? null : 'mysqldump exited with code 2',
    );

    expect($notification->toMail(dbBackupStaff('developer'))->actionUrl)->toBe(route('admin.database-backups.index'))
        ->and($notification->toMail(dbBackupStaff('admin'))->actionUrl)->toBe(route('admin.database-backups.index'));
})->with([true, false]);

it('never mentions the developer role in an admin\'s mail', function (bool $succeeded): void {
    $recipient = dbBackupStaff('admin');
    $notification = new DatabaseBackupStatusNotification(
        succeeded: $succeeded,
        environment: 'production',
        takenAt: '19 Nov 2026, 05:00 AM',
        file: $succeeded ? 'arovolife-production-20261119-050000-scheduled.sql.gz' : null,
        bytes: $succeeded ? 1_048_576 : null,
        durationSeconds: 12.3,
        heldCount: 30,
        prunedCount: 1,
        error: $succeeded ? null : 'mysqldump exited with code 2',
    );

    $mail = $notification->toMail($recipient);
    $text = strtolower($mail->subject.' '.implode(' ', $mail->introLines).' '.implode(' ', $mail->outroLines).' '.(string) $mail->render());

    expect($text)->not->toContain('developer')
        ->and($text)->toContain('arovolife');
})->with(['succeeded' => true, 'failed' => false]);

function dbBackupSettingsName(Carbon $at, string $trigger = 'scheduled'): string
{
    return 'arovolife-testing-'.$at->copy()->timezone('Asia/Kolkata')->format('Ymd-His').'-'.$trigger.'.env.zip';
}

function dbBackupZipOpen(string $file, ?string $password = null): ZipArchive
{
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('db-backups')->path($file), ZipArchive::RDONLY))->toBeTrue();
    if ($password !== null) {
        $zip->setPassword($password);
    }

    return $zip;
}

/** @return list<string> */
function dbBackupSettingsCopies(): array
{
    return array_values(array_filter(Storage::disk('db-backups')->files(), fn (string $f): bool => str_ends_with($f, '.env.zip')));
}

it('saves the settings file as a password-protected zip that opens only with the password', function (): void {
    config(['db_backup.settings_password' => DB_BACKUP_SETTINGS_PASSWORD]);
    dbBackupFakeDump();

    $this->artisan('db:backup:run', ['--no-mail' => true])->assertSuccessful();

    $copies = dbBackupSettingsCopies();
    expect($copies)->toHaveCount(1)
        ->and($copies[0])->toMatch('/\Aarovolife-testing-\d{8}-\d{6}-scheduled\.env\.zip\z/');

    $dump = collect(Storage::disk('db-backups')->files())->first(fn (string $f): bool => str_ends_with($f, '.sql.gz'));
    expect(explode('-scheduled', $copies[0])[0])->toBe(explode('-scheduled', $dump)[0]);

    $zip = dbBackupZipOpen($copies[0]);
    expect($zip->numFiles)->toBe(1)
        ->and($zip->getNameIndex(0))->toBe('.env')
        ->and($zip->getFromName('.env'))->toBeFalse();
    $zip->close();

    $zip = dbBackupZipOpen($copies[0], DB_BACKUP_SETTINGS_PASSWORD);
    expect($zip->getFromName('.env'))->toBe(file_get_contents($this->envFixture));
    $zip->close();

    $wrong = dbBackupZipOpen($copies[0], 'a-different-password-entirely');
    expect($wrong->getFromName('.env'))->toBeFalse();
    $wrong->close();
});

it('makes no settings copy without a usable password and still backs up the database', function (?string $password, string $reason): void {
    config(['db_backup.settings_password' => $password]);
    dbBackupFakeDump();
    Notification::fake();
    $admin = dbBackupStaff('admin');

    $this->artisan('db:backup:run')->assertExitCode(0);

    expect(dbBackupSettingsCopies())->toBe([])
        ->and(collect(Storage::disk('db-backups')->files())->filter(fn (string $f): bool => str_ends_with($f, '.sql.gz')))->toHaveCount(1);

    Notification::assertSentTo($admin, DatabaseBackupStatusNotification::class, function (DatabaseBackupStatusNotification $n) use ($admin, $reason): bool {
        $mail = $n->toMail($admin);

        return str_starts_with($mail->subject, 'Database backup succeeded')
            && in_array("**Settings file copy: NOT saved — {$reason}**", $mail->introLines, true);
    });
})->with([
    'no password' => [null, 'no password is set on the server'],
    'blank password' => ['   ', 'no password is set on the server'],
    '19 characters' => [str_repeat('x', 19), 'the password is shorter than 20 characters'],
]);

it('reports a missing settings file as not saved without failing the backup', function (): void {
    config(['db_backup.settings_password' => DB_BACKUP_SETTINGS_PASSWORD, 'db_backup.settings_file' => '/nonexistent/.env']);
    dbBackupFakeDump();
    Notification::fake();
    $admin = dbBackupStaff('admin');

    $this->artisan('db:backup:run')->assertExitCode(0);

    expect(dbBackupSettingsCopies())->toBe([]);
    Notification::assertSentTo($admin, DatabaseBackupStatusNotification::class, fn (DatabaseBackupStatusNotification $n): bool => in_array('**Settings file copy: NOT saved — the settings file was not found**', $n->toMail($admin)->introLines, true));
});

it('reports a failed uploaded-files copy without failing or undoing the database backup', function (): void {
    config(['db_backup.settings_password' => DB_BACKUP_SETTINGS_PASSWORD, 'db_backup.file_disks' => ['no-such-disk']]);
    dbBackupFakeDump();
    Notification::fake();
    $admin = dbBackupStaff('admin');

    $this->artisan('db:backup:run')->assertExitCode(0);

    expect(collect(Storage::disk('db-backups')->files())->filter(fn (string $f): bool => str_ends_with($f, '.sql.gz')))->toHaveCount(1);
    Notification::assertSentTo($admin, DatabaseBackupStatusNotification::class, function (DatabaseBackupStatusNotification $n) use ($admin): bool {
        $lines = $n->toMail($admin)->introLines;

        return in_array('Settings file copy: saved ('.Number::fileSize((int) $n->settingsBytes, 1).')', $lines, true)
            && count(array_filter($lines, fn (string $l): bool => str_starts_with($l, 'Uploaded files: copy FAILED — '))) === 1;
    });
});

it('puts the mirror result in the mail and never the password or the file contents in the mail or output', function (): void {
    config(['db_backup.settings_password' => DB_BACKUP_SETTINGS_PASSWORD]);
    Storage::disk('public')->put('catalog/a.jpg', 'AAAA');
    Storage::disk('public')->put('catalog/b.jpg', 'BB');
    dbBackupFakeDump();
    Notification::fake();
    $admin = dbBackupStaff('admin');

    Artisan::call('db:backup:run');
    $output = Artisan::output();

    $secrets = [DB_BACKUP_SETTINGS_PASSWORD, 'PII_ENCRYPTION_KEY', 'aGVsbG8gd29ybGQgdGhpcyBpcyBhIHRlc3QgZml4dHVyZSBvbmx5Cg==', base64_encode(str_repeat('fixture-key-material-', 3))];
    Notification::assertSentTo($admin, DatabaseBackupStatusNotification::class, function (DatabaseBackupStatusNotification $n) use ($admin, $secrets): bool {
        $mail = $n->toMail($admin);
        $text = $mail->subject.' '.implode(' ', $mail->introLines).' '.$mail->render();

        foreach ($secrets as $secret) {
            expect($text)->not->toContain($secret);
        }

        return in_array('Uploaded files: 2 new file(s) copied (6.0 B), 0 already saved', $mail->introLines, true);
    });
    foreach ($secrets as $secret) {
        expect($output)->not->toContain($secret);
    }
    expect($output)->toContain('Settings file copy saved')->toContain('2 new file(s) copied');
});

it('prunes settings copies past retention and never touches the files tree', function (): void {
    config(['db_backup.settings_password' => DB_BACKUP_SETTINGS_PASSWORD]);
    $old = dbBackupSettingsName(Carbon::now()->subDays(31));
    $recent = dbBackupSettingsName(Carbon::now()->subDays(29));
    Storage::disk('db-backups')->put($old, 'zip');
    Storage::disk('db-backups')->put($recent, 'zip');
    Storage::disk('db-backups')->put('arovolife-testing-20000101-000000-other.env.zip', 'not ours');
    Storage::disk('db-backups')->put('files/public/catalog/ancient.jpg', 'image');
    dbBackupFakeDump();

    $this->artisan('db:backup:run', ['--no-mail' => true])->assertSuccessful();

    Storage::disk('db-backups')->assertMissing($old);
    Storage::disk('db-backups')->assertExists([$recent, 'arovolife-testing-20000101-000000-other.env.zip', 'files/public/catalog/ancient.jpg']);
});

it('mirrors new and size-changed files, skips present ones, skips dotfiles and deletes nothing', function (): void {
    $local = Storage::disk('public');
    $backup = Storage::disk('db-backups');
    $local->put('catalog/new.jpg', 'NEWW');
    $local->put('catalog/same.jpg', 'SAME');
    $local->put('catalog/changed.jpg', 'CHANGED');
    $local->put('.gitignore', '*');
    $local->put('catalog/.hidden', 'x');
    $backup->put('files/public/catalog/same.jpg', 'KEEP');
    $backup->put('files/public/catalog/changed.jpg', 'old');
    $backup->put('files/public/catalog/only-in-backup.jpg', 'precious');

    $result = app(UploadedFilesBackupService::class)->mirror();

    expect($result->copied)->toBe(2)
        ->and($result->bytes)->toBe(strlen('NEWW') + strlen('CHANGED'))
        ->and($result->alreadyPresent)->toBe(1)
        ->and($backup->get('files/public/catalog/new.jpg'))->toBe('NEWW')
        ->and($backup->get('files/public/catalog/changed.jpg'))->toBe('CHANGED')
        ->and($backup->get('files/public/catalog/same.jpg'))->toBe('KEEP')
        ->and($backup->get('files/public/catalog/only-in-backup.jpg'))->toBe('precious');
    $backup->assertMissing(['files/public/.gitignore', 'files/public/catalog/.hidden']);
    $local->assertExists(['catalog/new.jpg', 'catalog/same.jpg', 'catalog/changed.jpg', '.gitignore']);

    // A second run copies nothing.
    expect(app(UploadedFilesBackupService::class)->mirror()->copied)->toBe(0);
});

it('keeps the files tree out of the database backup listing', function (): void {
    Storage::disk('db-backups')->put('files/public/catalog/arovolife-testing-20260101-000000-manual.sql.gz', 'x');
    Storage::disk('db-backups')->put('files/public/catalog/arovolife-testing-20260101-000000-manual.env.zip', 'x');

    expect(app(DatabaseBackupService::class)->all())->toBe([])
        ->and(app(SettingsFileBackupService::class)->all())->toBe([]);
});

it('restores only absent files and overwrites or deletes nothing', function (): void {
    $local = Storage::disk('public');
    $backup = Storage::disk('db-backups');
    $backup->put('files/public/catalog/missing.jpg', 'FROM-BACKUP');
    $backup->put('files/public/catalog/present.jpg', 'FROM-BACKUP');
    $backup->put('files/other/catalog/elsewhere.jpg', 'x');
    $local->put('catalog/present.jpg', 'LOCAL-NEWER');
    $local->put('catalog/local-only.jpg', 'mine');

    $restored = app(UploadedFilesBackupService::class)->restoreMissing('public');

    expect($restored)->toBe(1)
        ->and($local->get('catalog/missing.jpg'))->toBe('FROM-BACKUP')
        ->and($local->get('catalog/present.jpg'))->toBe('LOCAL-NEWER')
        ->and($local->get('catalog/local-only.jpg'))->toBe('mine');
    $backup->assertExists(['files/public/catalog/missing.jpg', 'files/public/catalog/present.jpg']);
    $local->assertMissing('catalog/elsewhere.jpg');
});

it('restores uploaded files by command and refuses a disk that is not backed up', function (): void {
    Storage::disk('db-backups')->put('files/public/catalog/a.jpg', 'A');

    $this->artisan('db:backup:files-restore', ['disk' => 's3'])->assertFailed();
    $this->artisan('db:backup:files-restore', ['disk' => 'public'])->assertSuccessful();

    expect(Storage::disk('public')->get('catalog/a.jpg'))->toBe('A');
});

it('lists settings copies for a developer and downloads one with an audit row', function (): void {
    $file = dbBackupSettingsName(Carbon::now()->subDay());
    Storage::disk('db-backups')->put($file, 'zip-bytes');
    $developer = dbBackupStaff('developer');

    $this->actingAs($developer)->get(route('admin.database-backups.index'))
        ->assertOk()
        ->assertSee('Settings file copies')
        ->assertSee($file)
        ->assertSee(route('admin.database-backups.settings.download', $file), false);

    $location = (string) $this->actingAs($developer)->get(route('admin.database-backups.settings.download', $file))
        ->assertRedirect()->headers->get('Location');
    expect($location)->toContain($file)->toContain('expiration=');

    $audit = AuditLog::where('action', 'admin.settings_backup_downloaded')->sole();
    expect($audit->actor_id)->toBe($developer->id)
        ->and($audit->details['file'])->toBe($file)
        ->and(AuditLog::where('action', 'admin.database_backup_downloaded')->count())->toBe(0);
});

it('downloads a settings copy for an admin and audit-logs it', function (): void {
    $file = dbBackupSettingsName(Carbon::now()->subDay());
    Storage::disk('db-backups')->put($file, 'zip-bytes');
    $admin = dbBackupStaff('admin');

    $location = (string) $this->actingAs($admin)->get(route('admin.database-backups.settings.download', $file))
        ->assertRedirect()->headers->get('Location');
    expect($location)->toContain($file)->toContain('expiration=');

    $audit = AuditLog::where('action', 'admin.settings_backup_downloaded')->sole();
    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->details['file'])->toBe($file);
});

it('serves settings copies only for env.zip names on the disk, and the two download routes never shadow each other', function (): void {
    $zip = dbBackupSettingsName(Carbon::now()->subDay());
    $dump = dbBackupName(Carbon::now()->subDay());
    Storage::disk('db-backups')->put($zip, 'zip-bytes');
    dbBackupPut($dump);
    $developer = dbBackupStaff('developer');

    $this->actingAs($developer)->get('/admin/database-backups/settings/'.$dump.'/download')->assertNotFound();
    $this->actingAs($developer)->get('/admin/database-backups/settings/arovolife-testing-20010102-050000-scheduled.env.zip/download')->assertNotFound();
    $this->actingAs($developer)->get('/admin/database-backups/settings/..%2F.env/download')->assertNotFound();
    $this->actingAs($developer)->get('/admin/database-backups/'.$zip.'/download')->assertNotFound();
    $this->actingAs($developer)->get('/admin/database-backups/'.$dump.'/download')->assertRedirect();

    expect(AuditLog::where('action', 'admin.settings_backup_downloaded')->count())->toBe(0);
});

it('words the missing-password notice for an admin without naming the setting', function (): void {
    $this->actingAs(dbBackupStaff('admin'))->get(route('admin.database-backups.index'))
        ->assertOk()
        ->assertSee('The backup password has not been set on the server.')
        ->assertDontSee('ENV_BACKUP_PASSWORD');
});

it('shows the amber notice only when the settings password is missing or short', function (?string $password, bool $notice): void {
    config(['db_backup.settings_password' => $password]);

    $response = $this->actingAs(dbBackupStaff('developer'))->get(route('admin.database-backups.index'))->assertOk();

    $notice
        ? $response->assertSee('Settings-file copies are not being made')
        : $response->assertDontSee('Settings-file copies are not being made');
})->with([
    'missing' => [null, true],
    'short' => [str_repeat('x', 19), true],
    'long enough' => [str_repeat('x', 20), false],
]);
