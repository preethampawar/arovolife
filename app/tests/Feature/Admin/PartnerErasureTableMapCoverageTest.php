<?php

declare(strict_types=1);

use App\Modules\Admin\Services\Erasure\PartnerErasureTableMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** @return list<string> */
function erasureSchemaTables(): array
{
    // Scoped to this connection's database: unscoped, the listing also returns
    // tables from every other schema the MySQL user can see.
    return Schema::getTableListing(schema: DB::connection()->getDatabaseName(), schemaQualified: false);
}

// A new table that can name a person must be classified, or an erased
// partner's data would silently survive in it.
it('classifies every table with a person reference as delete, anonymise or keep', function (): void {
    $classified = [...PartnerErasureTableMap::DELETE, ...PartnerErasureTableMap::ANONYMISE, ...PartnerErasureTableMap::KEEP];

    $unclassified = [];
    foreach (erasureSchemaTables() as $table) {
        $hasPersonColumn = collect(Schema::getColumnListing($table))
            ->contains(fn (string $column): bool => preg_match('/distributor_id|user_id|customer_id|order_id|ancestor_id|descendant_id|sponsor_id|notifiable_id|model_id/', $column) === 1);
        if ($hasPersonColumn && ! in_array($table, $classified, true)) {
            $unclassified[] = $table;
        }
    }

    expect($unclassified)->toBe([], 'Add each table to PartnerErasureTableMap::DELETE, ANONYMISE or KEEP');
});

it('puts each table in exactly one list', function (): void {
    $all = [...PartnerErasureTableMap::DELETE, ...PartnerErasureTableMap::ANONYMISE, ...PartnerErasureTableMap::KEEP];

    expect(array_values(array_diff_assoc($all, array_unique($all))))->toBe([]);
});

it('names only tables that exist', function (): void {
    $all = [...PartnerErasureTableMap::DELETE, ...PartnerErasureTableMap::ANONYMISE, ...PartnerErasureTableMap::KEEP];

    expect(array_values(array_diff($all, erasureSchemaTables())))->toBe([]);
});
