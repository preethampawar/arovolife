<?php
declare(strict_types=1);
use App\Modules\Compensation\Support\CarryOverDisplay;

it('shows only business since the last match', function (int $carried, int $cf, int $expected) {
    expect(CarryOverDisplay::sinceLastMatch($carried, $cf))->toBe($expected);
})->with([
    'before any match' => [4200, 0, 4200],
    'right after a match' => [5000, 5000, 0],
    'new business since' => [6500, 5000, 1500],
    'never negative' => [100, 300, 0],
]);

it('carry cards show numbers only and keep both sides', function () {
    $src = file_get_contents(resource_path('views/my-business.blade.php'));
    expect($src)->toContain('Carried-over Left Genos BV')->toContain('Carried-over Right Genos BV')
        ->toContain('CarryOverDisplay::sinceLastMatch')
        ->not->toContain('Remaining after your last slab match')
        ->not->toContain('No new business on this side since your last match')
        ->not->toContain('UnchangedSinceMatch')
        ->toContain('from-emerald-500 to-green-700');
});
