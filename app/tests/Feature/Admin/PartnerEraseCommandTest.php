<?php

declare(strict_types=1);

/**
 * `partners:erase`, the server-side twin of the developer's delete page.
 * Fixture: root → A (L) and X (R); A → B (L), C (R).
 *
 * Every disk the erase deletes from is faked: a test that forgot once wiped
 * real staging KYC scans (2026-10-18).
 */

use App\Modules\Commerce\Models\OfflinePayment;
use App\Modules\Compensation\Services\AreteCenterApplicationService;
use App\Modules\Genealogy\Events\DistributorRegistered;
use App\Modules\Genealogy\Events\PlacementCreated;
use App\Modules\Genealogy\Services\DTOs\PlaceDistributorInput;
use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\DistributorRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
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

function eraseCmdStaff(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function eraseCmdPlace(int $sponsorId, int $placementId, string $side): int
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
function eraseCmdTree(): array
{
    $root = uiDistributor(['full_name' => 'Root'])['id'];
    $a = eraseCmdPlace($root, $root, 'L');
    $x = eraseCmdPlace($root, $root, 'R');
    $b = eraseCmdPlace($a, $a, 'L');
    $c = eraseCmdPlace($a, $a, 'R');

    return ['root' => $root, 'a' => $a, 'b' => $b, 'c' => $c, 'x' => $x];
}

function eraseCmdAdn(int $id): string
{
    return (string) DB::table('distributors')->where('id', $id)->value('adn');
}

function eraseCmdErasedCount(): int
{
    return DB::table('distributors')->whereNotNull('erased_at')->count();
}

it('deletes the partner and the whole subtree when the APN is confirmed', function (): void {
    $t = eraseCmdTree();
    $adn = eraseCmdAdn($t['a']);
    $developer = eraseCmdStaff('developer');

    $this->artisan('partners:erase', ['adn' => $adn, '--actor' => $developer->email, '--confirm' => $adn])
        ->expectsOutputToContain('2 partners below them will be deleted with them.')
        ->expectsOutputToContain("APN {$adn} and 2 partners below it were deleted.")
        ->assertSuccessful();

    $a = Distributor::query()->findOrFail($t['a']);
    expect($a->isErased())->toBeTrue()
        ->and($a->adn)->toBe("ERASED-{$t['a']}")
        ->and($a->placement_parent_id)->toBe($t['a'])
        ->and(DB::table('distributors')->whereIn('id', [$t['a'], $t['b'], $t['c']])->whereNotNull('erased_at')->count())->toBe(3)
        ->and(DB::table('distributors')->whereIn('id', [$t['root'], $t['x']])->whereNotNull('erased_at')->count())->toBe(0)
        ->and(DB::table('genealogy_closure')->where('ancestor_id', $t['a'])->orWhere('descendant_id', $t['a'])->count())->toBe(0);
});

it('deletes nothing when the APN typed again does not match', function (): void {
    $adn = eraseCmdAdn(eraseCmdTree()['a']);

    $this->artisan('partners:erase', ['adn' => $adn, '--actor' => eraseCmdStaff('developer')->email, '--confirm' => '111111111'])
        ->expectsOutputToContain('The APN you typed again does not match. Nothing was deleted.')
        ->assertFailed();

    expect(eraseCmdErasedCount())->toBe(0);
});

it('refuses an actor who is not a developer, even an admin', function (string $role): void {
    $adn = eraseCmdAdn(eraseCmdTree()['a']);

    $this->artisan('partners:erase', ['adn' => $adn, '--actor' => eraseCmdStaff($role)->email, '--confirm' => $adn])
        ->assertFailed();

    expect(eraseCmdErasedCount())->toBe(0);
})->with(['admin', 'distributor']);

it('refuses an unknown or missing actor', function (array $actor): void {
    $adn = eraseCmdAdn(eraseCmdTree()['a']);

    $this->artisan('partners:erase', ['adn' => $adn, '--confirm' => $adn, ...$actor])
        ->assertFailed();

    expect(eraseCmdErasedCount())->toBe(0);
})->with([
    'unknown email' => [['--actor' => 'nobody@example.test']],
    'no --actor' => [[]],
]);

it('lists the blockers and deletes nothing when something blocks the delete', function (): void {
    $t = eraseCmdTree();
    DB::table('distributors')->where('id', $t['c'])->update(['adn' => ReservedAdns::CHILDREN[40]]);
    $adn = eraseCmdAdn($t['a']);

    $this->artisan('partners:erase', ['adn' => $adn, '--actor' => eraseCmdStaff('developer')->email, '--confirm' => $adn])
        ->expectsOutputToContain('is a company-reserved account.')
        ->expectsOutputToContain('Nothing was deleted.')
        ->assertFailed();

    expect(eraseCmdErasedCount())->toBe(0);
});

it('refuses an APN that is not nine digits', function (string $adn): void {
    eraseCmdTree();

    $this->artisan('partners:erase', ['adn' => $adn, '--actor' => eraseCmdStaff('developer')->email, '--confirm' => $adn])
        ->expectsOutputToContain('An APN is nine digits.')
        ->assertFailed();

    expect(eraseCmdErasedCount())->toBe(0);
})->with(['12345', 'abcdefghi', '1234567890']);
