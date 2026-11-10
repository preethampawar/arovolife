<?php

declare(strict_types=1);

use App\Modules\Shared\Testing\E2eDatabaseTemplate;
use App\Modules\Shared\Testing\E2eMode;
use App\Modules\Shared\Testing\E2eServiceProvider;
use Illuminate\Support\Facades\Artisan;

/**
 * The e2e stack shares the dev code mount and .env, so its mode must fail
 * closed: off unless ARV_E2E=1, and refusing any database not named *_e2e.
 */
it('is not registered without the ARV_E2E flag', function (): void {
    expect(E2eMode::requested())->toBeFalse()
        ->and(app()->getProviders(E2eServiceProvider::class))->toBe([])
        ->and(array_filter(array_keys(Artisan::all()), fn (string $name): bool => str_starts_with($name, 'e2e:')))->toBe([]);
});

it('refuses a database whose name does not end in _e2e', function (): void {
    expect(fn () => E2eMode::assertSafe(app()))
        ->toThrow(RuntimeException::class, 'REFUSING E2E MODE');
});

it('refuses production even before looking at the database', function (): void {
    app()->instance('env', 'production');

    try {
        expect(fn () => E2eMode::assertSafe(app()))
            ->toThrow(RuntimeException::class, 'APP_ENV is production');
    } finally {
        app()->instance('env', 'testing');
    }
});

it('will not copy or restore a template for a non-e2e database', function (): void {
    $template = app(E2eDatabaseTemplate::class);

    expect(fn () => $template->restore())->toThrow(RuntimeException::class, 'is not an e2e database')
        ->and(fn () => $template->capture())->toThrow(RuntimeException::class, 'is not an e2e database');
});
