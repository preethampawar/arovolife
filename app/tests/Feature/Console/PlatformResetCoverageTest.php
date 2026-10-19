<?php

declare(strict_types=1);

use App\Console\Actions\PlatformResetAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// The 2026-10-19 prod 500: distributor_profiles was in neither list, survived
// the wipe, and collided with the first real sign-up on a reused id.
it('accounts for every table in the schema as either wiped or deliberately kept', function () {
    $accountedFor = [...PlatformResetAction::wipeTables(), ...PlatformResetAction::KEPT_TABLES];

    $unaccounted = collect(Schema::getTableListing(schemaQualified: false))
        ->reject(fn (string $table): bool => in_array($table, $accountedFor, true))
        ->values()
        ->all();

    expect($unaccounted)->toBe([], 'Add each table to PlatformResetAction::PLATFORM_TABLES (wiped) or KEPT_TABLES (kept)');
});

it('never lists a table as both wiped and kept', function () {
    expect(array_intersect(PlatformResetAction::wipeTables(), PlatformResetAction::KEPT_TABLES))->toBe([]);
});

// Ids restart after the wipe, so a kept row pointing at a person points at the
// next real joiner. Matches assigned_distributor_id, *_user_id, updated_by...
it('nulls every person reference left in a kept table', function () {
    $unhandled = [];
    foreach (PlatformResetAction::KEPT_TABLES as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }
        foreach (Schema::getColumnListing($table) as $column) {
            $isPersonReference = preg_match('/(distributor_id|user_id|_by|actor_id|verifier_id)$/', $column) === 1;
            if ($isPersonReference && ! in_array($column, PlatformResetAction::KEPT_TABLE_PERSON_COLUMNS[$table] ?? [], true)) {
                $unhandled[] = "{$table}.{$column}";
            }
        }
    }

    expect($unhandled)->toBe([], 'Add each column to PlatformResetAction::KEPT_TABLE_PERSON_COLUMNS');
});
