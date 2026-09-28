<?php
declare(strict_types=1);

it('rank status bars use the side palette and totals are tiles', function () {
    $src = file_get_contents(resource_path('views/income/rank-bonus.blade.php'))
        .@file_get_contents(resource_path('views/income/_rank-conditions.blade.php'));
    expect($src)->toContain("GenosSideColors::for('L')")
        ->toContain('data-tile="credited"')->toContain('data-tile="months"')
        ->toContain('bg-emerald-100')->toContain('bg-brand-100');
});
