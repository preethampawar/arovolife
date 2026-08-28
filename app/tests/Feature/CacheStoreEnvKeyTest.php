<?php

declare(strict_types=1);

/**
 * Laravel 11 renamed CACHE_DRIVER to CACHE_STORE. Nothing warns about the old
 * name: config/cache.php reads env('CACHE_STORE', 'database'), so a .env still
 * setting CACHE_DRIVER=redis resolves to the *database* store and every cache
 * read, cache lock, Pennant flag lookup and rate-limiter bucket quietly becomes
 * a MySQL round-trip. That shipped to staging and production unnoticed, copied
 * out of the env examples and the Cloudways runbook.
 *
 * app:deploy now refuses to run against a .env carrying the stale key; these
 * assertions stop the templates that seed those .env files from reintroducing
 * it in the first place.
 */
it('uses CACHE_STORE, not the stale CACHE_DRIVER key, in every env template', function () {
    $templates = array_filter([
        base_path('.env.example'),
        dirname(base_path()).'/.env.example',
    ], 'is_readable');

    expect($templates)->not->toBeEmpty();

    foreach ($templates as $template) {
        $lines = preg_grep(
            '/^\s*CACHE_DRIVER\s*=/m',
            explode("\n", (string) file_get_contents($template))
        );

        expect($lines)->toBe([], "{$template} sets CACHE_DRIVER, which Laravel ignores; use CACHE_STORE.");
    }
});

it('resolves the cache store from CACHE_STORE', function () {
    expect((string) file_get_contents(config_path('cache.php')))
        ->toContain("env('CACHE_STORE'");
});
