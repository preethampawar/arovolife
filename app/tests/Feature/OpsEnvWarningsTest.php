<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;

/**
 * The boot-time ops env check runs on every request, so a warning it raises
 * about something nobody uses drowns the log: 3,517 of the 3,525 warnings in
 * the 2026-09-10 QA window were the Slack line, on an app whose log stack has
 * no Slack channel in it (F123).
 *
 * The check itself returns early under tests, so the decision it turns on is
 * exercised directly.
 */
function slackIsActive(): bool
{
    $method = new ReflectionMethod(AppServiceProvider::class, 'slackIsAnActiveLogChannel');

    return (bool) $method->invoke(new AppServiceProvider(app()));
}

it('does not treat Slack as active when the log stack does not include it', function (): void {
    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['single', 'daily'],
    ]);

    expect(slackIsActive())->toBeFalse();
});

it('treats Slack as active when the log stack writes to it', function (): void {
    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['daily', 'slack'],
    ]);

    expect(slackIsActive())->toBeTrue();
});

it('treats Slack as active when it is the default channel outright', function (): void {
    config(['logging.default' => 'slack']);

    expect(slackIsActive())->toBeTrue();
});
