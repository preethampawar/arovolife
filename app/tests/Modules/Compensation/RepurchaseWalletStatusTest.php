<?php

declare(strict_types=1);

use App\Modules\Compensation\Support\RepurchaseWalletStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('escalates the tone by calendar day while the wallet holds a balance', function (string $date, string $tone) {
    expect(RepurchaseWalletStatus::for(50_000, Carbon::parse($date))->tone)->toBe($tone);
})->with([
    ['2026-09-01', 'green'],
    ['2026-09-10', 'green'],
    ['2026-09-11', 'amber'],
    ['2026-09-20', 'amber'],
    ['2026-09-21', 'red'],
    ['2026-09-30', 'red'],
]);

it('reports one day left on the last day of a short month', function () {
    $status = RepurchaseWalletStatus::for(50_000, Carbon::parse('2026-02-28'));

    expect($status->tone)->toBe('red')
        ->and($status->daysRemaining)->toBe(1)
        ->and($status->detail())->toContain('1 day left');
});

it('shows the cleared state whatever the day once the balance is zero', function () {
    $status = RepurchaseWalletStatus::for(0, Carbon::parse('2026-09-25'));

    expect($status->tone)->toBe('cleared')
        ->and($status->label())->toBe('Cleared — ₹0')
        ->and($status->detail())->toBe('Nothing to clear this month.')
        ->and($status->pillClasses())->toContain('bg-gray-100');
});

it('names the month-end deadline and the days left in the detail line', function () {
    expect(RepurchaseWalletStatus::for(50_000, Carbon::parse('2026-09-05'))->detail())
        ->toBe('Bring this to ₹0 by 30 Sep — 26 days left');
});
