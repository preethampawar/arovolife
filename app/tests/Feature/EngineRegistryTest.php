<?php

declare(strict_types=1);

use App\Modules\Compensation\Support\EnginePeriodType;
use App\Modules\Compensation\Support\EngineRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/**
 * The Engine Runs page is only as trustworthy as its registry: a compensation
 * command that is missing from it silently disappears from the admin surface,
 * and a stale entry offers admins a button that fails at run time. This test
 * pins the registry against the console commands, the artisan table and the
 * route table so neither side can drift unnoticed.
 */
it('has exactly one registry entry per compensation console command', function (): void {
    $commandFiles = glob(app_path('Modules/Compensation/Console/Commands/*.php'));

    // Commands that orchestrate the engines rather than being one. They take no
    // period, produce no bonus, and must never appear on the Engine Runs page
    // or in a dependency chain.
    $notEngines = [
        // TESTING ONLY — deleted with the recompute scaffold at client sign-off.
        'CompensationRecomputeAllCommand',
    ];

    $commandClasses = collect($commandFiles ?: [])
        ->map(fn (string $path): string => basename($path, '.php'))
        ->reject(fn (string $class): bool => in_array($class, $notEngines, true))
        ->map(fn (string $class): string => 'App\\Modules\\Compensation\\Console\\Commands\\'.$class)
        ->sort()
        ->values()
        ->all();

    $registered = collect(EngineRegistry::all())
        ->map(fn ($definition): string => $definition->commandClass)
        ->sort()
        ->values()
        ->all();

    expect($registered)->toBe($commandClasses);
});

it('registers ten engines with unique keys and signatures', function (): void {
    $all = EngineRegistry::all();

    expect($all)->toHaveCount(10);
    expect(array_keys($all))->toBe(EngineRegistry::keys());
    expect(collect($all)->pluck('commandSignature')->unique())->toHaveCount(10);
});

it('points every registry entry at a real artisan command with the declared period option', function (): void {
    $artisan = Artisan::all();

    foreach (EngineRegistry::all() as $key => $definition) {
        expect(array_key_exists($definition->commandSignature, $artisan))->toBeTrue(
            "Engine [{$key}] names an unregistered command."
        );

        $command = $artisan[$definition->commandSignature];

        expect($command)->toBeInstanceOf($definition->commandClass);

        $optionName = ltrim($definition->periodOption, '-');
        expect($command->getDefinition()->hasOption($optionName))->toBeTrue(
            "Engine [{$key}] passes {$definition->periodOption}, which the command does not accept."
        );
    }
});

it('uses the period option that matches the engine period type', function (): void {
    foreach (EngineRegistry::all() as $key => $definition) {
        $expected = $definition->periodType === EnginePeriodType::Month ? '--month' : '--date';

        expect($definition->periodOption)->toBe($expected, "Engine [{$key}] period option mismatch.");
    }
});

it('declares only known dependency keys, shifts and expansions', function (): void {
    $keys = EngineRegistry::keys();

    foreach (EngineRegistry::all() as $key => $definition) {
        foreach ($definition->dependencies as $dependency) {
            expect(in_array($dependency['key'], $keys, true))->toBeTrue(
                "Engine [{$key}] depends on an unknown engine [{$dependency['key']}]."
            );
            expect($dependency['key'])->not->toBe($key, "Engine [{$key}] depends on itself.");

            if (isset($dependency['shift'])) {
                expect($dependency['shift'])->toBe('prev-month');
            }

            if (isset($dependency['expand'])) {
                expect(in_array($dependency['expand'], ['month', 'week'], true))->toBeTrue(
                    "Engine [{$key}] declares an unknown expansion."
                );
            }
        }
    }
});

it('forms an acyclic dependency graph', function (): void {
    $visit = function (string $key, array $stack) use (&$visit): void {
        expect(in_array($key, $stack, true))->toBeFalse('Dependency cycle through ['.$key.'].');

        $stack[] = $key;

        foreach (EngineRegistry::get($key)->dependencies as $dependency) {
            $visit($dependency['key'], $stack);
        }
    };

    foreach (EngineRegistry::keys() as $key) {
        $visit($key, []);
    }
});

it('names feature flag classes and report routes that actually exist', function (): void {
    foreach (EngineRegistry::all() as $key => $definition) {
        if ($definition->featureFlagClass !== null) {
            expect(class_exists($definition->featureFlagClass))->toBeTrue(
                "Engine [{$key}] names a missing feature class."
            );
        }

        if ($definition->reportRouteName !== null) {
            expect(Route::has($definition->reportRouteName))->toBeTrue(
                "Engine [{$key}] links to an undefined route."
            );
        }
    }
});

it('gives every engine an operator-facing description of what it writes', function (): void {
    foreach (EngineRegistry::all() as $key => $definition) {
        expect(mb_strlen($definition->description))->toBeGreaterThan(80, "Engine [{$key}] description is too thin.");
        expect(str_contains($definition->description, 'Impact:'))->toBeTrue(
            "Engine [{$key}] description omits its impact."
        );
        expect($definition->label)->not->toBeEmpty();
        expect($definition->scheduleText())->not->toBeEmpty();
    }
});

it('resolves a sane default period for every engine', function (): void {
    foreach (EngineRegistry::all() as $key => $definition) {
        $default = $definition->defaultPeriodDate();
        $formatted = $definition->formatPeriod($default);

        expect($definition->formatPeriod($definition->parsePeriod($formatted)))
            ->toBe($formatted, "Engine [{$key}] default period does not round-trip.");

        expect($default->isFuture() && ! $default->isToday())->toBeFalse(
            "Engine [{$key}] defaults to a future period."
        );
    }
});

it('declares a cadence that matches what the scheduler actually registers', function (): void {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    // artisan name => the cron expression Laravel registered for it.
    $registered = [];
    foreach ($schedule->events() as $event) {
        foreach (EngineRegistry::all() as $definition) {
            // The registered string looks like "'php' 'artisan' gsb:daily-cutoff --date='...'",
            // so anchor on the signature followed by whitespace or end-of-string —
            // a bare str_contains would let rank:monthly-run match rank:check-qualifications.
            $pattern = "/\\bartisan'?\\s+".preg_quote($definition->commandSignature, '/')."(\\s|$)/";

            if (preg_match($pattern, (string) $event->command) === 1) {
                $registered[$definition->commandSignature] = $event->expression;
            }
        }
    }

    foreach (EngineRegistry::all() as $key => $definition) {
        $expression = $registered[$definition->commandSignature] ?? null;

        if (! $definition->cadence->isScheduled()) {
            expect($expression)->toBeNull(
                "Engine [{$key}] is declared unscheduled but the scheduler registers it as [{$expression}]."
            );

            continue;
        }

        expect($expression)->not->toBeNull(
            "Engine [{$key}] declares a cadence but nothing registers it in routes/console.php."
        );

        // Cron is `minute hour dayOfMonth month dayOfWeek`.
        [$minute, $hour, $dayOfMonth, , $dayOfWeek] = explode(' ', (string) $expression);

        expect(sprintf('%02d:%02d', (int) $hour, (int) $minute))->toBe(
            $definition->cadence->time,
            "Engine [{$key}] cadence time disagrees with the scheduler."
        );

        if ($definition->cadence->dayOfMonth !== null) {
            expect((int) $dayOfMonth)->toBe(
                $definition->cadence->dayOfMonth,
                "Engine [{$key}] cadence day-of-month disagrees with the scheduler."
            );
        }

        if ($definition->cadence->dayOfWeek !== null) {
            // Laravel writes cron day-of-week (0 = Sunday); the cadence uses
            // ISO-8601 (7 = Sunday). Normalise before comparing.
            $isoFromCron = (int) $dayOfWeek === 0 ? 7 : (int) $dayOfWeek;

            expect($isoFromCron)->toBe(
                $definition->cadence->dayOfWeek,
                "Engine [{$key}] cadence day-of-week disagrees with the scheduler."
            );
        }
    }
});
