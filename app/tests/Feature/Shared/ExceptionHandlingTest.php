<?php

declare(strict_types=1);

/**
 * EH-H1 / EH-L10: the withExceptions() handler in bootstrap/app.php.
 *
 * - branded error views render for 403/404/419/429
 * - API clients get a stable JSON shape with no exception message
 * - server-side failures alert the PII-scrubbed `slack` log channel
 * - client-side HTTP errors never alert Slack
 * - sensitive input is never flashed back into the session
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->withoutVite();
});

it('renders the branded 404 view for browser requests', function (): void {
    $this->get('/_definitely-missing-page')
        ->assertNotFound()
        ->assertSee('Page not found')
        ->assertSee('Go to home');
});

it('returns a stable JSON shape for API clients', function (): void {
    $this->getJson('/_definitely-missing-page')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Page not found.', 'error' => 404]);
});

it('renders the branded 403 view', function (): void {
    Route::get('/_test/forbidden', fn () => abort(403))->middleware('web');

    $this->get('/_test/forbidden')
        ->assertForbidden()
        ->assertSee('Access denied')
        ->assertSee('Go to home');
});

it('renders the branded 419 view', function (): void {
    Route::get('/_test/expired', fn () => abort(419))->middleware('web');

    $this->get('/_test/expired')
        ->assertStatus(419)
        ->assertSee('Session expired');
});

it('renders the branded 429 view', function (): void {
    Route::get('/_test/throttled', fn () => abort(429))->middleware('web');

    $this->get('/_test/throttled')
        ->assertStatus(429)
        ->assertSee('Too many requests');
});

it('alerts the slack log channel on a server-side failure', function (): void {
    config(['logging.channels.slack.url' => 'https://hooks.slack.test/x']);

    Route::get('/_test/boom', function (): void {
        throw new RuntimeException('boom', 500);
    })->middleware('web');

    Log::shouldReceive('channel')->with('slack')->once()->andReturnSelf();
    Log::shouldReceive('critical')->once();
    Log::shouldReceive('error')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('log')->zeroOrMoreTimes();

    $this->get('/_test/boom')->assertStatus(500);
});

it('does not alert the slack log channel on a 404', function (): void {
    config(['logging.channels.slack.url' => 'https://hooks.slack.test/x']);

    Log::shouldReceive('channel')->with('slack')->never();
    Log::shouldReceive('error')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('log')->zeroOrMoreTimes();

    $this->get('/_definitely-missing-page')->assertNotFound();
});

it('never flashes sensitive input back into the session', function (): void {
    Route::post('/_test/validated', function (Request $request) {
        $request->validate(['name' => 'required']);
    })->middleware('web');

    $this->from('/_test/form')
        ->post('/_test/validated', ['pan' => 'ABCDE1234F', 'password' => 'secret', 'otp' => '123456'])
        ->assertSessionHasErrors('name');

    $old = session('_old_input', []);

    expect($old)->not->toHaveKey('pan')
        ->and($old)->not->toHaveKey('password')
        ->and($old)->not->toHaveKey('otp');
});
