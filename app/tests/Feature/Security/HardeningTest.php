<?php

declare(strict_types=1);

/**
 * The controls the T-6.1 audit found missing. Each one is here because
 * "we added a header" and "the header is on every response" are different
 * claims, and only the second is worth anything.
 *
 * SEC-01: every web response carries the security headers
 * SEC-02: the CSP forbids framing, plugins and cross-origin form posts
 * SEC-03: HSTS is sent over TLS and withheld over plain HTTP
 * SEC-04: a controller's own header is never overwritten
 * SEC-05: the PII scrubber redacts a PAN inside an exception in log context
 * SEC-06: the scrubber is attached to every log channel, not just the file ones
 * SEC-07: password spraying across many accounts from one IP is capped
 * SEC-08: account creation and checkout are rate limited
 */

use App\Modules\Shared\Logging\PiiScrubberProcessor;
use App\Modules\Shared\Logging\TapPiiScrubber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Monolog\Level;
use Monolog\LogRecord;

uses(RefreshDatabase::class);

it('SEC-01: every web response carries the security headers', function () {
    $response = $this->get('/');

    foreach ([
        'Content-Security-Policy',
        'X-Frame-Options',
        'X-Content-Type-Options',
        'Referrer-Policy',
        'Permissions-Policy',
    ] as $header) {
        expect($response->headers->get($header))->not->toBeNull("missing {$header}");
    }
});

it('SEC-02: the CSP forbids framing, plugins and cross-origin form posts', function () {
    $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

    // Framing is the one that matters most: without it any site can iframe the
    // admin console and overlay it, and an operator who thinks they are
    // dismissing a cookie banner is approving a payout batch.
    expect($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("form-action 'self'")
        ->and($this->get('/')->headers->get('X-Frame-Options'))->toBe('DENY');
});

it('SEC-03: HSTS is sent over TLS and withheld over plain HTTP', function () {
    // Over plain HTTP it is at best ignored and at worst pins a dev host to a
    // scheme it cannot serve — an outage that survives a browser restart.
    expect($this->get('http://localhost/')->headers->get('Strict-Transport-Security'))->toBeNull()
        ->and($this->get('https://localhost/')->headers->get('Strict-Transport-Security'))
        ->toContain('max-age=31536000');
});

it('SEC-04: a header a controller set deliberately is not overwritten', function () {
    // The invoice download and the KYC document stream set their own. A
    // middleware that clobbers them breaks the download rather than securing
    // it.
    $this->app['router']->get('/__sec_test', fn () => response('ok')
        ->header('Referrer-Policy', 'no-referrer'))->middleware('web');

    expect($this->get('/__sec_test')->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('SEC-05: a PAN inside an exception in log context is redacted', function () {
    // Laravel puts the Throwable itself under context.exception and the
    // formatter renders its message and stack trace. An object is neither a
    // string nor an array, so it walked past every rule in the scrubber.
    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Error,
        message: 'Registration failed',
        context: ['exception' => new RuntimeException('PAN ABCDE1234F rejected')],
    );

    $scrubbed = (new PiiScrubberProcessor)($record);

    expect(json_encode($scrubbed->context))->not->toContain('ABCDE1234F')
        ->and($scrubbed->context['exception']['class'])->toBe(RuntimeException::class);
});

it('SEC-06: the scrubber is attached to every log channel', function () {
    // It hung off `single` and `daily` only, so pointing LOG_STACK at stderr —
    // exactly what a containerised deploy does — silently disabled it. A
    // control one env var can switch off is not a control.
    foreach (['single', 'daily', 'slack', 'syslog', 'errorlog'] as $channel) {
        expect(config("logging.channels.{$channel}.tap") ?? [])
            ->toContain(TapPiiScrubber::class);
    }

    foreach (['stderr', 'papertrail'] as $channel) {
        expect(config("logging.channels.{$channel}.processors") ?? [])
            ->toContain(PiiScrubberProcessor::class);
    }
});

it('SEC-07: spraying one password across many accounts from one IP is capped', function () {
    // The per-account bucket stops five guesses at ONE account and does
    // nothing about one guess against five hundred accounts — which is how a
    // weak password on any one of a thousand distributors gets found.
    for ($i = 0; $i < 31; $i++) {
        $response = $this->post('/login', [
            'login' => "victim{$i}@example.com",
            'password' => 'Password123!',
        ]);
    }

    $response->assertSessionHasErrors('login');
    expect(session('errors')->first('login'))->toContain('Too many failed login attempts');
});

it('SEC-08: account creation and checkout are rate limited', function () {
    $limits = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array($route->getName(), ['join.submit', 'register.post', 'shop.checkout.place'], true))
        ->mapWithKeys(fn ($route) => [
            $route->getName() => collect($route->gatherMiddleware())->first(fn ($m) => str_starts_with((string) $m, 'throttle:')),
        ]);

    // Unauthenticated routes that write rows and burn the ADN sequence.
    expect($limits['join.submit'])->toBe('throttle:10,60')
        ->and($limits['register.post'])->toBe('throttle:10,60')
        ->and($limits['shop.checkout.place'])->toBe('throttle:20,60');
});
