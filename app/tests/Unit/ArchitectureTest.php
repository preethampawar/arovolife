<?php

declare(strict_types=1);

use App\Modules\Compensation\Services\WalletService;
use App\Modules\Identity\Services\RegistrationService;

/*
 * Architecture rules from CLAUDE.md "Default engineering conventions", checked
 * on every test run so they cannot drift. Each rule passes on the code as it
 * stands; an exception is listed with its reason instead of loosening the rule.
 */

arch('every application file declares strict types', function (): void {
    expect('App')->toUseStrictTypes();
});

// One function per expectation: toBeUsedIn() given an array silently passes.
arch('no debugging calls are left in application code', function (): void {
    foreach (['dd', 'dump', 'ddd', 'ray', 'var_dump', 'die', 'exit'] as $function) {
        expect($function)->not->toBeUsedIn('App');
    }
});

// env() returns null once the config is cached (as on staging and production),
// so application code must read settings through config().
arch('application code reads settings through config(), never env()', function (): void {
    expect('env')->not->toBeUsedIn('App');
});

// Enums, interfaces and traits are skipped: only classes can be declared final.
it('makes module service classes final', function (): void {
    $root = dirname(__DIR__, 2);
    $exempt = [
        // Tests replace it with Mockery::mock(), which cannot mock a final class.
        RegistrationService::class,
        // GsbCutoffServiceTest swaps in an anonymous subclass that fails once.
        WalletService::class,
    ];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/app/Modules"));
    $notFinal = [];

    foreach ($files as $file) {
        $path = $file->getPathname();
        if (! str_ends_with($path, '.php') || ! str_contains($path, '/Services/')) {
            continue;
        }

        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($path, strlen("{$root}/app/")));
        if (! class_exists($class) || in_array($class, $exempt, true)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if (! $reflection->isFinal() && ! $reflection->isAbstract()) {
            $notFinal[] = $class;
        }
    }

    expect($notFinal)->toBe([]);
});
