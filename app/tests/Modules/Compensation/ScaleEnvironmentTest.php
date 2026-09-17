<?php

declare(strict_types=1);

use App\Modules\Compensation\Support\ScaleEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The scale harness writes a synthetic population and its `--fresh` TRUNCATES
 * eight tables. Four locks stand between that and somebody's real data, and a
 * gate with no test is a gate nobody has tried to walk through.
 */
function scaleEnvironment(): ScaleEnvironment
{
    return app(ScaleEnvironment::class);
}

/**
 * Make the guard believe it is connected to `$name`, without moving the actual
 * connection off the test database.
 *
 * `setDatabaseName()` changes what `getDatabaseName()` reports while the PDO
 * behind it keeps talking to `arovolife_test` — which is the only way to
 * exercise the permitted path at all, since `arovolife_test` is itself on the
 * refuse-outright list (as it should be).
 */
function pretendConnectedTo(string $name): void
{
    DB::connection()->setDatabaseName($name);
}

afterEach(function (): void {
    DB::connection()->setDatabaseName(config('database.connections.mysql.database'));
});

it('refuses in production whatever it is configured with', function (): void {
    pretendConnectedTo('arovolife_scale');
    config(['arovolife.scale.database' => 'arovolife_scale']);
    app()->detectEnvironment(fn (): string => 'production');

    expect(scaleEnvironment()->isPermitted())->toBeFalse();
    expect(scaleEnvironment()->refusalReason())->toContain('never runs in production');
});

it('refuses the databases that hold real data, however it is configured', function (): void {
    // The list is the point: `arovolife_staging` has not existed since staging
    // moved to Cloudways-local MySQL in August 2026, and `ahdhesuhty` — which
    // does not look like a staging database at all — is the one that does.
    foreach (['arovolife', 'arovolife_test', 'arovolife_staging', 'ahdhesuhty'] as $database) {
        pretendConnectedTo($database);
        config(['arovolife.scale.database' => $database]);

        $reason = (string) scaleEnvironment()->refusalReason();

        expect(str_contains($reason, 'Refusing to write a synthetic population into'))
            ->toBeTrue("[{$database}] was not refused.");
    }
});

it('refuses when no scale database is configured', function (): void {
    pretendConnectedTo('arovolife_scale');
    config(['arovolife.scale.database' => '']);

    expect(scaleEnvironment()->refusalReason())->toContain('No scale database is configured');
});

it('refuses when it is connected to a database other than the configured one', function (): void {
    pretendConnectedTo('arovolife_scale');
    config(['arovolife.scale.database' => 'arovolife_somewhere_else']);

    expect(scaleEnvironment()->refusalReason())->toContain('but the scale database is');
});

it('permits the configured database', function (): void {
    pretendConnectedTo('arovolife_scale');
    config(['arovolife.scale.database' => 'arovolife_scale']);

    expect(scaleEnvironment()->refusalReason())->toBeNull();
    expect(scaleEnvironment()->isPermitted())->toBeTrue();
});

it('refuses to empty a database holding a distributor it did not write', function (): void {
    // The lock a rename, a copied .env or a restored dump cannot get past: the
    // name says what an operator meant, the rows say what is actually there.
    pretendConnectedTo('arovolife_scale');
    config(['arovolife.scale.database' => 'arovolife_scale']);

    expect(scaleEnvironment()->truncateRefusalReason())->toBeNull();

    disableTestForeignKeys();
    DB::table('distributors')->insert([
        'id' => 1,
        'user_id' => 1,
        'adn' => 'AR00000001',
        'pan_hash' => hash('sha256', 'real', true),
        'pan_last4' => '1234',
        'sponsor_id' => 1,
        'placement_parent_id' => 1,
        'depth' => 0,
        'effective_date' => now(),
        'cooling_off_end_at' => now()->addDays(30),
        'state' => 'TG',
        'is_primary_couple' => 1,
        'status' => 'active',
        'side_chosen_by' => 'spillover_left',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(scaleEnvironment()->truncateRefusalReason())
        ->toContain('distributor(s) this harness did not write');
});

it('empties a database holding only its own synthetic distributors', function (): void {
    pretendConnectedTo('arovolife_scale');
    config(['arovolife.scale.database' => 'arovolife_scale']);

    disableTestForeignKeys();
    DB::table('distributors')->insert([
        'id' => 1,
        'user_id' => 1,
        'adn' => 'SC00000001',
        'pan_hash' => hash('sha256', 'scale', true),
        'pan_last4' => '0001',
        'sponsor_id' => 1,
        'placement_parent_id' => 1,
        'depth' => 0,
        'effective_date' => now(),
        'cooling_off_end_at' => now()->addDays(30),
        'state' => 'TG',
        'is_primary_couple' => 1,
        'status' => 'active',
        'side_chosen_by' => 'spillover_left',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(scaleEnvironment()->truncateRefusalReason())->toBeNull();
});
