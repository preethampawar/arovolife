<?php
declare(strict_types=1);
use App\Modules\Genealogy\Support\GenosSideColors;

it('left is blue and right is green', function () {
    expect(GenosSideColors::for('L')['bar'])->toContain('sky')
        ->and(GenosSideColors::for('right')['bar'])->toContain('emerald')
        ->and(GenosSideColors::for('R')['text'])->toBe('text-emerald-700');
});

it('no side-coloured dashboard or income view still uses indigo for Right', function () {
    foreach (['dashboard/_genos-balance', 'dashboard/_my-team', 'income/genos-bv', 'income/genos-ledger', 'partials/_distributor-sidenav-groups'] as $v) {
        expect(file_get_contents(resource_path("views/$v.blade.php")))->not->toContain('indigo');
    }
});
