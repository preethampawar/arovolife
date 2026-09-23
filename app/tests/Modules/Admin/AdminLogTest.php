<?php

declare(strict_types=1);

/**
 * Developer settings → Logs: list every storage/logs/*.log file grouped by
 * log name and download one. Developer-only (other staff are refused and
 * never see the link); every download is audit-logged; only a bare
 * `name.log` / `name-YYYY-MM-DD.log` filename is ever served.
 */

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const ADMIN_LOG_TEST_FILES = ['payments-2001-01-02.log', 'zz-test-worker.log'];

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    foreach (ADMIN_LOG_TEST_FILES as $file) {
        file_put_contents(storage_path('logs/'.$file), "[2001-01-02 10:00:00] testing.INFO: marker for {$file}\n");
    }
});

afterEach(function (): void {
    foreach (ADMIN_LOG_TEST_FILES as $file) {
        @unlink(storage_path('logs/'.$file));
    }
});

function adminLogStaff(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('lists every log file grouped by log name for a developer, each with its download link', function (): void {
    $response = $this->actingAs(adminLogStaff('developer'))->get(route('admin.logs.index'))->assertOk();

    foreach (ADMIN_LOG_TEST_FILES as $file) {
        $response->assertSee($file)->assertSee(route('admin.logs.download', $file), false);
    }
    $response->assertSee('payments')->assertSee('zz-test-worker');
});

it('downloads a log file for a developer and audit-logs it', function (): void {
    $developer = adminLogStaff('developer');

    $response = $this->actingAs($developer)->get(route('admin.logs.download', 'payments-2001-01-02.log'));

    $response->assertOk()->assertDownload('payments-2001-01-02.log');
    expect($response->streamedContent())->toContain('marker for payments-2001-01-02.log');

    $audit = AuditLog::where('action', 'admin.log_downloaded')->sole();
    expect($audit->actor_id)->toBe($developer->id)
        ->and($audit->details['file'])->toBe('payments-2001-01-02.log');
});

it('refuses the page and the download to non-developer staff', function (string $role): void {
    $user = adminLogStaff($role);

    $this->actingAs($user)->get(route('admin.logs.index'))->assertStatus(403);
    $this->actingAs($user)->get(route('admin.logs.download', 'zz-test-worker.log'))->assertStatus(403);

    expect(AuditLog::where('action', 'admin.log_downloaded')->count())->toBe(0);
})->with(['admin', 'admin-finance']);

it('serves only bare .log filenames that exist', function (string $path): void {
    $this->actingAs(adminLogStaff('developer'))->get('/admin/logs/'.$path)->assertNotFound();
})->with([
    'traversal' => '..%2F.env',
    'not a log' => 'env.txt',
    'missing file' => 'payments-2001-01-03.log',
]);

it('shows the settings link to a developer only', function (): void {
    $this->actingAs(adminLogStaff('developer'))->get(route('admin.settings'))
        ->assertOk()->assertSee(route('admin.logs.index'), false);

    $this->actingAs(adminLogStaff('admin'))->get(route('admin.settings'))
        ->assertOk()->assertDontSee(route('admin.logs.index'), false);
});
