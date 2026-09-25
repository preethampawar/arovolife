<?php

declare(strict_types=1);

/**
 * The previous arovolife.com site registered a PWA service worker at /sw.js.
 * A registered worker survives a 404 at its URL and keeps serving the old site
 * from cache, so public/sw.js must stay a self-destructing replacement until
 * those browsers have all visited again. Deleting it, or turning it into a
 * caching worker, brings the old site back for them.
 */
it('keeps the kill-switch service worker at /sw.js', function (): void {
    $path = dirname(__DIR__, 2).'/public/sw.js';

    expect(is_file($path))->toBeTrue('public/sw.js was removed; it retires the old site\'s service worker.');

    $source = (string) file_get_contents($path);

    expect($source)
        ->toContain('skipWaiting()')
        ->toContain('caches.delete(')
        ->toContain('registration.unregister()')
        ->not->toContain("addEventListener('fetch'");
});
