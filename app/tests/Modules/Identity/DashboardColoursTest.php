<?php
declare(strict_types=1);

it('every quick action has its own colour family', function () {
    $src = file_get_contents(resource_path('views/dashboard/_quick-actions.blade.php'));
    preg_match_all("/'tone' => 'bg-([a-z]+)-/", $src, $m);
    expect(count($m[1]))->toBe(count(array_unique($m[1])));
    expect($src)->toContain("'teal'")->toContain('bg-teal-100');
});

it('income snapshot cards carry distinct tones', function () {
    $src = file_get_contents(resource_path('views/dashboard/_income-snapshot.blade.php'));
    foreach (['this-month', 'lifetime', 'next-weekly', 'next-monthly', 'repurchase-alert'] as $tone) {
        expect($src)->toContain('data-card="'.$tone.'"');
    }
    expect($src)->toContain('from-emerald-50')->toContain('from-violet-50')->not->toContain('border-gray-200 bg-gray-50 p-4');
});

it('fortune bonus not-qualified chip is red', function () {
    $src = file_get_contents(resource_path('views/dashboard/_fortune-bonus.blade.php'));
    expect($src)->toMatch('/bg-red-50[^"]*"[^>]*>\s*<x-lucide-circle-x[^>]*\/>\s*Not qualified yet/s');
});
