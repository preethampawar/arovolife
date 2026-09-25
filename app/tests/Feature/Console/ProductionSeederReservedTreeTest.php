<?php

declare(strict_types=1);

use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

it('seeds the 63 reserved company distributors with sponsorship on a fresh database', function () {
    $this->seed(ProductionSeeder::class);

    expect(Distributor::query()->count())->toBe(63)
        ->and(Distributor::query()->pluck('adn')->sort()->values()->all())
        ->toBe(collect(ReservedAdns::all())->sort()->values()->all())
        ->and(Distributor::query()->where('adn', ReservedAdns::ROOT)->value('depth'))->toBe(0);

    // 62 horizontal edges — each child sponsored by its direct binary
    // parent; the root gets NO row (a self-edge would make the company
    // root its own direct referral). Closure = complete 63-node tree:
    // 63 self-rows + (2*1 + 4*2 + 8*3 + 16*4 + 32*5) = 321.
    $rootId = Distributor::query()->where('depth', 0)->value('id');
    expect(DB::table('sponsorship')->count())->toBe(62)
        ->and(DB::table('sponsorship')->whereColumn('sponsor_id', 'distributor_id')->count())->toBe(0)
        ->and(DB::table('sponsorship')->where('distributor_id', $rootId)->count())->toBe(0)
        ->and(
            DB::table('sponsorship')
                ->join('distributors', 'distributors.id', '=', 'sponsorship.distributor_id')
                ->whereColumn('sponsorship.sponsor_id', 'distributors.placement_parent_id')
                ->count()
        )->toBe(62)
        ->and(DB::table('genealogy_closure')->count())->toBe(321);
});

it('is idempotent — re-running never duplicates or mutates the reserved block', function () {
    $this->seed(ProductionSeeder::class);
    $before = Distributor::query()->orderBy('id')->get(['id', 'adn', 'sponsor_id', 'placement_parent_id'])->toArray();

    $this->seed(ProductionSeeder::class);

    expect(Distributor::query()->count())->toBe(63)
        ->and(DB::table('sponsorship')->count())->toBe(62)
        ->and(DB::table('genealogy_closure')->count())->toBe(321)
        ->and(Distributor::query()->orderBy('id')->get(['id', 'adn', 'sponsor_id', 'placement_parent_id'])->toArray())
        ->toBe($before);
});

it('backfills only the missing sponsorship edges on an environment seeded before the fix', function () {
    $this->seed(ProductionSeeder::class);

    // Simulate the pre-2026-08-31 state (R-66): block present, edges absent.
    DB::table('sponsorship')->delete();

    $this->seed(ProductionSeeder::class);

    expect(DB::table('sponsorship')->count())->toBe(62)
        ->and(
            DB::table('sponsorship')
                ->join('distributors', 'distributors.id', '=', 'sponsorship.distributor_id')
                ->whereColumn('sponsorship.sponsor_id', 'distributors.placement_parent_id')
                ->count()
        )->toBe(62)
        ->and(Distributor::query()->count())->toBe(63);
});

it('skips the reserved block when non-reserved distributors already exist', function () {
    $organic = Distributor::factory()->create();

    $this->seed(ProductionSeeder::class);

    expect(Distributor::query()->count())->toBe(1)
        ->and(Distributor::query()->value('id'))->toBe($organic->id)
        ->and(DB::table('sponsorship')->count())->toBe(0);
});

it('re-running keeps every other role the provisioned admin holds', function () {
    config([
        'arovolife.seeder.admin.email' => 'ops@arovolife.test',
        'arovolife.seeder.admin.password' => 'secret-password',
    ]);
    $this->seed(ProductionSeeder::class);

    $admin = User::query()->where('email', 'ops@arovolife.test')->firstOrFail();
    $admin->assignRole('developer');

    $this->seed(ProductionSeeder::class);

    expect($admin->fresh()->getRoleNames()->sort()->values()->all())->toBe(['admin', 'developer']);
});

/** Drop the depth-5 reserved nodes, leaving the pre-2026-09-25 31-node block. */
function psrtRemoveDepthFive(): void
{
    $ids = Distributor::query()->where('depth', 5)->pluck('id');
    $userIds = Distributor::query()->whereIn('id', $ids)->pluck('user_id');
    DB::table('genealogy_closure')->whereIn('descendant_id', $ids)->delete();
    DB::table('sponsorship')->whereIn('distributor_id', $ids)->delete();
    Distributor::query()->whereIn('id', $ids)->delete();
    User::query()->whereIn('id', $userIds)->delete();
}

it('adds the depth-5 level to an environment that has only the original 31-node block', function () {
    $this->seed(ProductionSeeder::class);
    psrtRemoveDepthFive();
    expect(Distributor::query()->count())->toBe(31);

    $this->seed(ProductionSeeder::class);

    expect(Distributor::query()->count())->toBe(63)
        ->and(Distributor::query()->where('depth', 5)->count())->toBe(32)
        ->and(DB::table('sponsorship')->count())->toBe(62)
        ->and(DB::table('genealogy_closure')->count())->toBe(321)
        ->and(
            DB::table('sponsorship')
                ->join('distributors', 'distributors.id', '=', 'sponsorship.distributor_id')
                ->whereColumn('sponsorship.sponsor_id', 'distributors.placement_parent_id')
                ->count()
        )->toBe(62);

    // Every new leaf hangs off a depth-4 reserved node, one per side.
    $leafParents = Distributor::query()->where('depth', 5)->pluck('placement_parent_id')->countBy();
    expect($leafParents)->toHaveCount(16)
        ->and($leafParents->values()->unique()->values()->all())->toBe([2]);
});

it('leaves a depth-5 position alone when an organic distributor already holds it', function () {
    $this->seed(ProductionSeeder::class);
    psrtRemoveDepthFive();

    $leafParent = Distributor::query()->where('adn', ReservedAdns::CHILDREN[14])->firstOrFail();
    Distributor::factory()->create([
        'sponsor_id' => $leafParent->id,
        'placement_parent_id' => $leafParent->id,
        'placement_side' => 'L',
        'depth' => 5,
    ]);

    $this->seed(ProductionSeeder::class);

    // CHILDREN[14] is the first depth-4 node; its left child is CHILDREN[30].
    // 31 original + 31 new — the organic node's slot is skipped.
    expect(Distributor::query()->whereIn('adn', ReservedAdns::all())->count())->toBe(62)
        ->and(Distributor::query()->where('adn', ReservedAdns::CHILDREN[30])->exists())->toBeFalse();
});

it('issues each never-activated reserved account a plus-address and a password, once', function () {
    Storage::fake('local');
    config(['arovolife.seeder.reserved.email_base' => 'owner@example.com']);

    $this->seed(ProductionSeeder::class);

    $files = Storage::disk('local')->files('reserved-credentials');
    expect($files)->toHaveCount(1);

    $rows = array_map('str_getcsv', array_filter(explode("\n", Storage::disk('local')->get($files[0]))));
    expect($rows[0])->toBe(['ADN', 'Email', 'Password'])
        ->and(count($rows) - 1)->toBe(63);

    [$adn, $email, $password] = $rows[1];
    $user = Distributor::query()->where('adn', $adn)->firstOrFail()->user;
    expect($adn)->toBe(ReservedAdns::ROOT)
        ->and($email)->toBe('owner+444555666@example.com')
        ->and($user->email)->toBe($email)
        ->and($user->password_set_at)->not->toBeNull()
        ->and(Hash::check($password, $user->password_hash))->toBeTrue();

    // The password never reaches the audit trail.
    expect(DB::table('audit_log')->where('action', 'reserved.credentials_issued')->count())->toBe(63)
        ->and(DB::table('audit_log')->where('details', 'like', '%'.$password.'%')->exists())->toBeFalse();

    // Second run: every account already has a password, nothing is re-issued.
    $this->seed(ProductionSeeder::class);
    expect(Storage::disk('local')->files('reserved-credentials'))->toHaveCount(1)
        ->and(Hash::check($password, $user->fresh()->password_hash))->toBeTrue();
});

it('issues nothing when no email base is configured', function () {
    Storage::fake('local');
    config(['arovolife.seeder.reserved.email_base' => null]);

    $this->seed(ProductionSeeder::class);

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(User::query()->whereNotNull('password_set_at')->whereIn('id', Distributor::query()->pluck('user_id'))->count())->toBe(0);
});
