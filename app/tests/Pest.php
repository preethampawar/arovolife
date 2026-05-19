<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->in('Feature', 'Modules');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Disable foreign-key enforcement on the active test connection.
 *
 * Tests seed self-referential rows (root distributors whose sponsor_id /
 * placement_parent_id point at their own id), which is impossible while
 * the FK constraints are armed.
 *
 *  - On MySQL we flip the session-level `FOREIGN_KEY_CHECKS` switch.
 *  - On SQLite the `PRAGMA foreign_keys` knob is ignored inside an active
 *    transaction (and `RefreshDatabase` wraps every test in one), so we
 *    use `PRAGMA defer_foreign_keys = ON` instead. That flag defers FK
 *    validation until COMMIT, by which time the seed code has stamped
 *    sponsor_id / placement_parent_id back to the row's own id and the
 *    constraint is satisfied. The flag auto-resets at transaction end,
 *    so the matching `enableTestForeignKeys()` is a no-op on SQLite but
 *    still required on MySQL.
 */
function disableTestForeignKeys(): void
{
    $driver = \Illuminate\Support\Facades\DB::getDriverName();

    if ($driver === 'mysql') {
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0');
    } elseif ($driver === 'sqlite') {
        \Illuminate\Support\Facades\DB::statement('PRAGMA defer_foreign_keys = ON');
    }
}

function enableTestForeignKeys(): void
{
    $driver = \Illuminate\Support\Facades\DB::getDriverName();

    if ($driver === 'mysql') {
        \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
    // SQLite: defer_foreign_keys auto-resets at the end of the txn.
}
