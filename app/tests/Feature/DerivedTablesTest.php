<?php

declare(strict_types=1);

use App\Console\Actions\PurchaseDataResetAction;
use App\Modules\Compensation\Support\DerivedTables;

/**
 * Every table the repurchase forfeit model writes to must be in the ONE
 * registry both resets read, or a table quietly survives a wipe: rank_aogo_grants,
 * gsb_personal_bv_topups and engine_runs all did exactly that once.
 *
 * @return list<string>
 */
function repurchasePlanTables(): array
{
    return [
        'repurchase_cycles',
        'wallet_ledger_entries',
        'payout_batches',
        'payout_line_items',
        'engine_runs',
        'gsb_cutoff_results',
        'gsb_carryforward',
        'rank_qualifications',
        'rank_bonus_results',
        'gbb_monthly_results',
        'fortune_bonus_results',
    ];
}

it('registers every table the repurchase forfeit model touches', function (string $table): void {
    expect(DerivedTables::contains($table))->toBeTrue(
        "{$table} is written by the repurchase forfeit model but is not in DerivedTables::TABLES, "
        .'so a recompute or a purchase reset would leave its rows behind.',
    );
})->with(repurchasePlanTables());

it('dates every plan table a windowed replay must rebuild from a day or a month', function (): void {
    // The three deliberate exceptions carry no date of their own:
    // gsb_carryforward is rewound from the *_before columns, group_bv_debts
    // arithmetically, and payout_line_items by their parent batch id.
    $undated = ['gsb_carryforward', 'payout_line_items'];

    foreach (repurchasePlanTables() as $table) {
        $filter = DerivedTables::dateFilter($table);

        if (in_array($table, $undated, true)) {
            expect($filter)->toBeNull("{$table} is rewound or deleted by parent, not by date.");

            continue;
        }

        expect($filter)->not->toBeNull("{$table} has no date column, so a windowed replay cannot rebuild it.")
            ->and($filter['granularity'])->toBeIn(['day', 'month']);
    }
});

it('wipes purchase data through the same registry, never a second list', function (): void {
    // platform:reset-purchases adds the commerce source tables on top; it must
    // never carry its own copy of the compensation list.
    $reset = PurchaseDataResetAction::wipeTables();

    expect(array_slice($reset, 0, count(DerivedTables::inTruncationOrder())))
        ->toBe(DerivedTables::inTruncationOrder());

    foreach (repurchasePlanTables() as $table) {
        expect($reset)->toContain($table);
    }

    // The orders and the BV they produced go too, or there is nothing left to
    // recompute from and the forfeited rows would be rebuilt from stale input.
    expect($reset)->toContain('orders')
        ->and($reset)->toContain('bv_ledger_entries');
});
