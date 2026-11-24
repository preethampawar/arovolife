<?php

declare(strict_types=1);

/**
 * Developer-only partner erasure screens. Fixture: root → A (L) and X (R);
 * A → B (L), C (R). Every disk the erase deletes from is faked: a test that
 * forgot once wiped real staging KYC scans (2026-10-18).
 */

use App\Modules\Admin\Services\Erasure\PartnerErasureService;
use App\Modules\Commerce\Models\OfflinePayment;
use App\Modules\Compensation\Services\AreteCenterApplicationService;
use App\Modules\Genealogy\Events\DistributorRegistered;
use App\Modules\Genealogy\Events\PlacementCreated;
use App\Modules\Genealogy\Services\DTOs\PlaceDistributorInput;
use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\DistributorRequestService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['kyc', 's3', DistributorRequestService::DISK, AreteCenterApplicationService::DISK, OfflinePayment::DISK, 'grievance', 'private', 'db-backups'] as $disk) {
        Storage::fake($disk);
    }
    // Outside the 00:00–05:00 IST engine window, whatever the wall clock says.
    Carbon::setTestNow(Carbon::parse('2026-11-23 12:00:00', 'Asia/Kolkata'));
    Event::fake([PlacementCreated::class, DistributorRegistered::class]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function ctrlStaff(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function ctrlPlace(int $sponsorId, int $placementId, string $side): int
{
    $user = User::factory()->create();

    return app(PlacementEngine::class)->place(new PlaceDistributorInput(
        userId: $user->id,
        sponsorId: $sponsorId,
        placementId: $placementId,
        panHash: random_bytes(32),
        panLast4: '1234',
        bankAccountEnc: 'stub',
        bankIfsc: 'SBIN0000000',
        state: 'TS',
        sideOpt: $side,
    ))->distributorId;
}

/**
 * @return array{root: int, a: int, b: int, c: int, x: int}
 */
function ctrlTree(): array
{
    $root = uiDistributor(['full_name' => 'Root'])['id'];
    $a = ctrlPlace($root, $root, 'L');
    $x = ctrlPlace($root, $root, 'R');
    $b = ctrlPlace($a, $a, 'L');
    $c = ctrlPlace($a, $a, 'R');

    return ['root' => $root, 'a' => $a, 'b' => $b, 'c' => $c, 'x' => $x];
}

function ctrlAdn(int $id): string
{
    return (string) DB::table('distributors')->where('id', $id)->value('adn');
}

function ctrlPreviewFingerprint(string $adn): string
{
    return app(PartnerErasureService::class)->preview($adn)->fingerprint;
}

function erasureViaService(string $adn, User $actor): void
{
    $service = app(PartnerErasureService::class);
    $service->execute($adn, $service->preview($adn)->fingerprint, $actor);
}

it('answers 404 to every other admin role on all three routes', function (string $role): void {
    $tree = ctrlTree();
    $adn = ctrlAdn($tree['a']);
    $user = ctrlStaff($role);

    $this->actingAs($user)->get(route('admin.distributors.erase.form'))->assertNotFound();
    $this->actingAs($user)->post(route('admin.distributors.erase.preview'), ['adn' => $adn])->assertNotFound();
    $this->actingAs($user)->post(route('admin.distributors.erase.destroy'), [
        'adn' => $adn, 'fingerprint' => str_repeat('a', 64), 'confirm_adn' => $adn,
    ])->assertNotFound();

    expect(DB::table('distributors')->where('id', $tree['a'])->value('erased_at'))->toBeNull();
})->with(['admin', 'admin-operations', 'admin-finance', 'admin-compliance']);

it('keeps a plain partner out of the admin area on all three routes', function (): void {
    $tree = ctrlTree();
    $adn = ctrlAdn($tree['a']);
    $partner = User::find(DB::table('distributors')->where('id', $tree['x'])->value('user_id'));

    // The admin route group's own role middleware answers 403 for a non-staff user.
    $this->actingAs($partner)->get(route('admin.distributors.erase.form'))->assertForbidden();
    $this->actingAs($partner)->post(route('admin.distributors.erase.preview'), ['adn' => $adn])->assertForbidden();
    $this->actingAs($partner)->post(route('admin.distributors.erase.destroy'), [
        'adn' => $adn, 'fingerprint' => str_repeat('a', 64), 'confirm_adn' => $adn,
    ])->assertForbidden();

    expect(DB::table('distributors')->where('id', $tree['a'])->value('erased_at'))->toBeNull();
});

it('shows the form to a developer and prefills the APN from the list link', function (): void {
    $this->actingAs(ctrlStaff('developer'))
        ->get(route('admin.distributors.erase.form', ['adn' => '123456789']))
        ->assertOk()
        ->assertSee('value="123456789"', false);
});

it('previews the target and every child APN split into Left and Right Genos', function (): void {
    $tree = ctrlTree();

    $response = $this->actingAs(ctrlStaff('developer'))
        ->post(route('admin.distributors.erase.preview'), ['adn' => ctrlAdn($tree['a'])])
        ->assertOk()
        ->assertSee('Left Genos (1)')
        ->assertSee('Right Genos (1)')
        ->assertSee(ctrlAdn($tree['b']))
        ->assertSee(ctrlAdn($tree['c']))
        ->assertSee('Parent APN');

    $response->assertDontSee(ctrlAdn($tree['x']));
});

it('sends an unknown APN back to the form with an error', function (): void {
    ctrlTree();

    $this->actingAs(ctrlStaff('developer'))
        ->from(route('admin.distributors.erase.form'))
        ->post(route('admin.distributors.erase.preview'), ['adn' => '999999999'])
        ->assertRedirect(route('admin.distributors.erase.form'))
        ->assertSessionHasErrors('adn');
});

it('rejects a malformed APN', function (): void {
    $this->actingAs(ctrlStaff('developer'))
        ->post(route('admin.distributors.erase.preview'), ['adn' => 'abc'])
        ->assertSessionHasErrors('adn');
});

it('erases nothing when the APN is not typed again correctly', function (): void {
    $tree = ctrlTree();
    $adn = ctrlAdn($tree['a']);

    $this->actingAs(ctrlStaff('developer'))
        ->post(route('admin.distributors.erase.destroy'), [
            'adn' => $adn,
            'fingerprint' => ctrlPreviewFingerprint($adn),
            'confirm_adn' => '111111111',
        ])
        // Drawn again in place: a redirect "back" would GET the POST-only preview and answer 405.
        ->assertOk()
        ->assertSee('The APN you typed again does not match.')
        ->assertSee('Left Genos (1)');

    expect(DB::table('distributors')->whereIn('id', [$tree['a'], $tree['b'], $tree['c']])->whereNotNull('erased_at')->count())->toBe(0);
});

it('erases the partner and the whole subtree, then returns to the list', function (): void {
    $tree = ctrlTree();
    $adn = ctrlAdn($tree['a']);

    $this->actingAs(ctrlStaff('developer'))
        ->post(route('admin.distributors.erase.destroy'), [
            'adn' => $adn,
            'fingerprint' => ctrlPreviewFingerprint($adn),
            'confirm_adn' => $adn,
        ])
        ->assertRedirect(route('admin.distributors.index'))
        ->assertSessionHas('status', "APN {$adn} and 2 partners below it were deleted.");

    expect(DB::table('distributors')->whereIn('id', [$tree['a'], $tree['b'], $tree['c']])->whereNotNull('erased_at')->count())->toBe(3)
        ->and(DB::table('distributors')->whereIn('id', [$tree['root'], $tree['x']])->whereNotNull('erased_at')->count())->toBe(0);
});

it('re-renders the preview with the reason when the tree changed after the preview', function (): void {
    $tree = ctrlTree();
    $adn = ctrlAdn($tree['a']);
    $fingerprint = ctrlPreviewFingerprint($adn);
    ctrlPlace($tree['b'], $tree['b'], 'L');

    $this->actingAs(ctrlStaff('developer'))
        ->post(route('admin.distributors.erase.destroy'), [
            'adn' => $adn, 'fingerprint' => $fingerprint, 'confirm_adn' => $adn,
        ])
        ->assertOk()
        ->assertSee('Nothing was deleted.')
        ->assertSee('Left Genos (2)');

    expect(DB::table('distributors')->where('id', $tree['a'])->value('erased_at'))->toBeNull();
});

it('shows the delete links to a developer', function (): void {
    ctrlTree();

    $this->actingAs(ctrlStaff('developer'))->get(route('admin.distributors.index'))
        ->assertOk()->assertSee('Delete a partner')->assertSee('distributors/erase?adn=', false);
});

it('hides the delete links from an admin', function (): void {
    ctrlTree();

    $this->actingAs(ctrlStaff('admin'))->get(route('admin.distributors.index'))
        ->assertOk()->assertDontSee('Delete a partner')->assertDontSee('distributors/erase', false);
});

it('drops erased partners from the register and its status counts', function (): void {
    $tree = ctrlTree();
    $adn = ctrlAdn($tree['a']);
    $developer = ctrlStaff('developer');

    erasureViaService($adn, $developer);

    $this->actingAs($developer)->get(route('admin.distributors.index'))
        ->assertOk()
        ->assertDontSee('ERASED-')
        ->assertDontSee($adn);
    $this->actingAs($developer)->get(route('admin.distributors.show', $tree['a']))->assertNotFound();
});

it('erases nothing and explains why when a backup or restore holds the lock', function (): void {
    $tree = ctrlTree();
    $adn = ctrlAdn($tree['a']);
    // The erase waits up to 5 s for this lock. The clock is frozen, so let
    // each fake sleep move it forward or the wait never ends.
    Sleep::fake(syncWithCarbon: true);
    $lock = Cache::lock('db-backup:operation', 60);
    $lock->get();

    try {
        $this->actingAs(ctrlStaff('developer'))
            ->post(route('admin.distributors.erase.destroy'), [
                'adn' => $adn,
                'fingerprint' => ctrlPreviewFingerprint($adn),
                'confirm_adn' => $adn,
            ])
            ->assertRedirect(route('admin.distributors.erase.form'))
            ->assertSessionHasErrors('adn');
    } finally {
        $lock->release();
    }

    expect(DB::table('distributors')->whereNotNull('erased_at')->count())->toBe(0);
});

it('signs out a partner who was erased while signed in', function (): void {
    $tree = ctrlTree();
    $user = User::query()->findOrFail((int) DB::table('distributors')->where('id', $tree['b'])->value('user_id'));
    erasureViaService(ctrlAdn($tree['a']), ctrlStaff('developer'));

    $this->actingAs($user->fresh())
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('shows the server command and erases nothing on production when the web PHP cannot start the backup', function (): void {
    $tree = ctrlTree();
    $adn = ctrlAdn($tree['a']);
    $fingerprint = ctrlPreviewFingerprint($adn);
    $developer = ctrlStaff('developer');
    Process::fake();
    config(['db_backup.processes_available' => false]);
    // Outside the testing environment the CSRF check is live again.
    $this->withoutMiddleware(PreventRequestForgery::class);
    $env = app()['env'];
    app()['env'] = 'production';

    try {
        $this->actingAs($developer)
            ->post(route('admin.distributors.erase.preview'), ['adn' => $adn])
            ->assertOk()
            ->assertSee('This partner cannot be deleted yet.')
            ->assertSee('php artisan partners:erase');

        $this->actingAs($developer)
            ->post(route('admin.distributors.erase.destroy'), [
                'adn' => $adn, 'fingerprint' => $fingerprint, 'confirm_adn' => $adn,
            ])
            ->assertOk()
            ->assertSee('Nothing was deleted.')
            ->assertSee('php artisan partners:erase');
    } finally {
        app()['env'] = $env;
    }

    Process::assertNothingRan();
    expect(DB::table('distributors')->whereNotNull('erased_at')->count())->toBe(0);
});
