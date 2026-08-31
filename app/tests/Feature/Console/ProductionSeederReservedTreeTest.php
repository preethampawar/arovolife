<?php

declare(strict_types=1);

use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\Distributor;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

it('seeds the 31 reserved company distributors with sponsorship on a fresh database', function () {
    $this->seed(ProductionSeeder::class);

    expect(Distributor::query()->count())->toBe(31)
        ->and(Distributor::query()->pluck('adn')->sort()->values()->all())
        ->toBe(collect(ReservedAdns::all())->sort()->values()->all())
        ->and(Distributor::query()->where('adn', ReservedAdns::ROOT)->value('depth'))->toBe(0);

    // 30 horizontal edges — each child sponsored by its direct binary
    // parent; the root gets NO row (a self-edge would make the company
    // root its own direct referral). Closure = complete 31-node tree.
    $rootId = Distributor::query()->where('depth', 0)->value('id');
    expect(DB::table('sponsorship')->count())->toBe(30)
        ->and(DB::table('sponsorship')->whereColumn('sponsor_id', 'distributor_id')->count())->toBe(0)
        ->and(DB::table('sponsorship')->where('distributor_id', $rootId)->count())->toBe(0)
        ->and(
            DB::table('sponsorship')
                ->join('distributors', 'distributors.id', '=', 'sponsorship.distributor_id')
                ->whereColumn('sponsorship.sponsor_id', 'distributors.placement_parent_id')
                ->count()
        )->toBe(30)
        ->and(DB::table('genealogy_closure')->count())->toBe(129);
});

it('is idempotent — re-running never duplicates or mutates the reserved block', function () {
    $this->seed(ProductionSeeder::class);
    $before = Distributor::query()->orderBy('id')->get(['id', 'adn', 'sponsor_id', 'placement_parent_id'])->toArray();

    $this->seed(ProductionSeeder::class);

    expect(Distributor::query()->count())->toBe(31)
        ->and(DB::table('sponsorship')->count())->toBe(30)
        ->and(DB::table('genealogy_closure')->count())->toBe(129)
        ->and(Distributor::query()->orderBy('id')->get(['id', 'adn', 'sponsor_id', 'placement_parent_id'])->toArray())
        ->toBe($before);
});

it('backfills only the missing sponsorship edges on an environment seeded before the fix', function () {
    $this->seed(ProductionSeeder::class);

    // Simulate the pre-2026-08-31 state (R-66): block present, edges absent.
    DB::table('sponsorship')->delete();

    $this->seed(ProductionSeeder::class);

    expect(DB::table('sponsorship')->count())->toBe(30)
        ->and(
            DB::table('sponsorship')
                ->join('distributors', 'distributors.id', '=', 'sponsorship.distributor_id')
                ->whereColumn('sponsorship.sponsor_id', 'distributors.placement_parent_id')
                ->count()
        )->toBe(30)
        ->and(Distributor::query()->count())->toBe(31);
});

it('skips the reserved block when non-reserved distributors already exist', function () {
    $organic = Distributor::factory()->create();

    $this->seed(ProductionSeeder::class);

    expect(Distributor::query()->count())->toBe(1)
        ->and(Distributor::query()->value('id'))->toBe($organic->id)
        ->and(DB::table('sponsorship')->count())->toBe(0);
});
