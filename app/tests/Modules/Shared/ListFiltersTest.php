<?php

declare(strict_types=1);

use App\Modules\Shared\Support\FilterField;
use App\Modules\Shared\Support\ListFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Unit coverage for the shared list-page filter state.
 *
 * These tests never execute a query: `apply()` is asserted through the
 * builder's compiled SQL and bindings, so they need no migrated schema and
 * stay fast. What they do cover is the part that protects the query — the
 * discard rules in `make()` — because that is the layer standing between a
 * hand-edited URL and a `where` clause.
 *
 * @see docs/plans/list-page-filters-2026-09-13.md
 */
/** @param array<string, string> $query */
function lfRequest(array $query, string $url = 'http://localhost/admin/things'): Request
{
    return Request::create($url.($query === [] ? '' : '?'.http_build_query($query)), 'GET');
}

/**
 * @param  array<string, string>  $query
 * @param  list<FilterField>  $fields
 */
function lfMake(array $query, array $fields): ListFilters
{
    return ListFilters::make(lfRequest($query), $fields);
}

/**
 * SQL with the grammar's identifier quoting stripped.
 *
 * These tests run on MySQL (backticks) but the same code is exercised on
 * SQLite (double quotes) elsewhere, so asserting on a quoted identifier
 * would pin the test to one driver rather than to the behaviour.
 */
function lfSql(Illuminate\Contracts\Database\Query\Builder $query): string
{
    return str_replace(['`', '"'], '', $query->toSql());
}

function lfStatusField(): FilterField
{
    return FilterField::select('status', 'Status', ['open' => 'Open', 'closed' => 'Closed'], column: 'status');
}

it('keeps a value the field recognises', function () {
    $filters = lfMake(['status' => 'open'], [lfStatusField()]);

    expect($filters->value('status'))->toBe('open')
        ->and($filters->has('status'))->toBeTrue()
        ->and($filters->any())->toBeTrue();
});

it('discards a select value that is not one of its own options', function () {
    $filters = lfMake(['status' => '__nope__'], [lfStatusField()]);

    expect($filters->value('status'))->toBeNull()
        ->and($filters->any())->toBeFalse()
        ->and($filters->toQuery())->toBe([]);
});

it('discards blank and whitespace-only values', function () {
    $filters = lfMake(
        ['q' => '   ', 'status' => ''],
        [FilterField::text('q', 'Search', columns: ['name']), lfStatusField()],
    );

    expect($filters->any())->toBeFalse();
});

it('trims a text value it keeps', function () {
    $filters = lfMake(['q' => '  ram  '], [FilterField::text('q', 'Search', columns: ['name'])]);

    expect($filters->value('q'))->toBe('ram');
});

it('discards a malformed date and a malformed month', function () {
    $range = FilterField::dateRange('created', 'Created', dateColumn: 'created_at');
    $month = FilterField::month('month', 'Month', column: 'bonus_month');

    expect(lfMake(['created_from' => '13-09-2026'], [$range])->any())->toBeFalse()
        ->and(lfMake(['created_from' => '2026-02-31'], [$range])->any())->toBeFalse()
        ->and(lfMake(['month' => '2026-13'], [$month])->any())->toBeFalse()
        ->and(lfMake(['created_from' => '2026-09-13'], [$range])->value('created_from'))->toBe('2026-09-13')
        ->and(lfMake(['month' => '2026-09'], [$month])->value('month'))->toBe('2026-09');
});

it('treats only the truthy spellings of a checkbox as set', function () {
    $field = FilterField::boolean('active', 'Active only', column: 'is_active');

    expect(lfMake(['active' => '1'], [$field])->value('active'))->toBe('1')
        ->and(lfMake(['active' => 'on'], [$field])->value('active'))->toBe('1')
        ->and(lfMake(['active' => '0'], [$field])->any())->toBeFalse()
        ->and(lfMake([], [$field])->any())->toBeFalse();
});

it('applies an equals clause for a select that names a column', function () {
    $query = DB::table('things');
    lfMake(['status' => 'closed'], [lfStatusField()])->apply($query);

    expect(lfSql($query))->toContain('status = ?')
        ->and($query->getBindings())->toBe(['closed']);
});

it('applies no clause for a field that names no column', function () {
    $query = DB::table('things');
    $before = lfSql($query);

    lfMake(['status' => 'open'], [
        FilterField::select('status', 'Status', ['open' => 'Open']),
    ])->apply($query);

    expect(lfSql($query))->toBe($before)
        ->and($query->getBindings())->toBe([]);
});

it('ORs a text search across every column it names', function () {
    $query = DB::table('things');
    lfMake(['q' => 'ram'], [FilterField::text('q', 'Search', columns: ['name', 'email'])])->apply($query);

    expect(lfSql($query))->toContain('name like ?')
        ->and(lfSql($query))->toContain('or email like ?')
        ->and($query->getBindings())->toBe(['%ram%', '%ram%']);
});

it('escapes LIKE wildcards so a literal percent is not a match-all', function () {
    $query = DB::table('things');
    lfMake(['q' => '50%_off'], [FilterField::text('q', 'Search', columns: ['name'])])->apply($query);

    expect($query->getBindings())->toBe(['%50\\%\\_off%']);
});

it('applies each present bound of a date range independently', function () {
    $field = FilterField::dateRange('created', 'Created', dateColumn: 'created_at');

    $both = DB::table('things');
    lfMake(['created_from' => '2026-09-01', 'created_to' => '2026-09-30'], [$field])->apply($both);
    expect($both->getBindings())->toBe(['2026-09-01', '2026-09-30']);

    $fromOnly = DB::table('things');
    lfMake(['created_from' => '2026-09-01'], [$field])->apply($fromOnly);
    expect($fromOnly->getBindings())->toBe(['2026-09-01'])
        ->and(lfSql($fromOnly))->toContain('>=');
});

it('builds one chip per active value, each dropping only its own key', function () {
    $filters = lfMake(
        ['q' => 'ram', 'status' => 'open'],
        [FilterField::text('q', 'Search', columns: ['name']), lfStatusField()],
    );

    $chips = $filters->chips();
    expect($chips)->toHaveCount(2);

    $byKey = collect($chips)->keyBy('key');
    expect($byKey['q']['url'])->toContain('status=open')
        ->and($byKey['q']['url'])->not->toContain('q=ram')
        ->and($byKey['status']['url'])->toContain('q=ram')
        ->and($byKey['status']['url'])->not->toContain('status=open');
});

it('shows the option label, not the raw value, on a select chip', function () {
    $chips = lfMake(['status' => 'closed'], [lfStatusField()])->chips();

    expect($chips[0]['display'])->toBe('Closed');
});

it('labels the two ends of a date range separately', function () {
    $chips = lfMake(
        ['created_from' => '2026-09-01', 'created_to' => '2026-09-30'],
        [FilterField::dateRange('created', 'Created', dateColumn: 'created_at')],
    )->chips();

    expect(array_column($chips, 'label'))->toBe(['Created from', 'Created to']);
});

it('clears every filter but keeps a non-filter param such as a tab', function () {
    $filters = lfMake(['q' => 'ram', 'status' => 'open', 'tab' => 'archived'], [
        FilterField::text('q', 'Search', columns: ['name']),
        lfStatusField(),
    ]);

    expect($filters->clearUrl())->toBe('http://localhost/admin/things?tab=archived')
        ->and($filters->carriedParams())->toBe(['tab' => 'archived']);
});

it('clears to the bare path when there is nothing else to keep', function () {
    $filters = lfMake(['status' => 'open'], [lfStatusField()]);

    expect($filters->clearUrl())->toBe('http://localhost/admin/things');
});

it('never carries the page number into a chip, a clear link or the query', function () {
    $filters = lfMake(['status' => 'open', 'page' => '3'], [lfStatusField()]);

    expect($filters->toQuery())->toBe(['status' => 'open'])
        ->and($filters->carriedParams())->not->toHaveKey('page')
        ->and($filters->clearUrl())->not->toContain('page')
        ->and($filters->chips()[0]['url'])->not->toContain('page');
});

it('exposes only the active values for a paginator or an export link', function () {
    $filters = lfMake(['q' => 'ram', 'status' => 'bogus'], [
        FilterField::text('q', 'Search', columns: ['name']),
        lfStatusField(),
    ]);

    expect($filters->toQuery())->toBe(['q' => 'ram']);
});

it('finds a field by key and reports an unknown one as null', function () {
    $filters = lfMake([], [lfStatusField()]);

    expect($filters->field('status'))->not->toBeNull()
        ->and($filters->field('status')->label)->toBe('Status')
        ->and($filters->field('nope'))->toBeNull();
});
