<?php

declare(strict_types=1);

use App\Modules\Admin\Http\Controllers\AdminSettingsController;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;

/**
 * Every compensation-plan scalar must be reachable from the settings UI.
 *
 * The plan is deliberately DB-driven so a developer can retune it without a
 * deploy, but a key only becomes tunable once it appears in the settings
 * registry. A key added to SCALAR_DEFAULTS and nowhere else silently becomes a
 * hardcoded constant with extra steps: it reads its default forever and no
 * screen ever shows it. This test closes that gap by construction.
 *
 * Read-only registry entries still satisfy it — being visible-but-locked is a
 * deliberate choice for keys like a go-live boundary, and the registry records
 * the reason. Being absent is not a choice, it is an oversight.
 */
it('exposes every compensation plan scalar in the settings registry', function () {
    $reflection = new ReflectionClass(CompensationPlanSettingsService::class);
    /** @var array<string, mixed> $defaults */
    $defaults = $reflection->getConstant('SCALAR_DEFAULTS');

    expect($defaults)->not->toBeEmpty();

    $registered = array_keys(AdminSettingsController::registry());

    $missing = array_values(array_filter(
        array_keys($defaults),
        fn (string $key) => ! in_array($key, $registered, true),
    ));

    expect($missing)->toBe([], 'Plan scalars with no settings-UI entry (a developer cannot tune these): '.implode(', ', $missing));
});

/**
 * The registry drives validation, so a plan scalar registered under the wrong
 * group would be editable by the wrong role. Plan economics are developer-owned
 * — see the settings ownership split.
 */
it('files every compensation plan scalar under the developer-owned group', function () {
    $reflection = new ReflectionClass(CompensationPlanSettingsService::class);
    /** @var array<string, mixed> $defaults */
    $defaults = $reflection->getConstant('SCALAR_DEFAULTS');

    $registry = AdminSettingsController::registry();

    $wrongGroup = [];
    foreach (array_keys($defaults) as $key) {
        if (! isset($registry[$key])) {
            continue; // covered by the test above
        }
        $group = $registry[$key]['group'] ?? null;
        if (! in_array($group, ['compensation_plan', 'compensation', 'payout'], true)) {
            $wrongGroup[$key] = $group;
        }
    }

    expect($wrongGroup)->toBe([]);
});
