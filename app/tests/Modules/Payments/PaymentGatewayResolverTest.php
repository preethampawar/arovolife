<?php

declare(strict_types=1);

/**
 * Compliance C-2 / R-56: there is no fallback from Razorpay to the stub.
 *
 * PAY-R01: flag ON + valid credentials → Razorpay
 * PAY-R02: flag ON + credentials missing → checkout closed, never the stub, critical logged once
 * PAY-R03: flag ON + wrong-mode key → checkout closed
 * PAY-R04: flag OFF + stub enabled + permitted → stub
 * PAY-R05: flag OFF in production → no online payment, and checkout is NOT closed
 * PAY-R06: the stub refuses when the Razorpay flag is on, whatever the allow-list says
 * PAY-R07: the stub refuses when a live key is present at all
 */

use App\Modules\Payments\Services\PaymentGatewayResolver;
use App\Modules\Payments\Services\RazorpayGateway;
use App\Modules\Payments\Services\StubGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

function paymentSetting(string $key, string $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'version' => 1, 'updated_at' => now()]);
}

function validRazorpayConfig(string $keyId = 'rzp_test_ABCDEF123456'): void
{
    config()->set('arovolife.payments.razorpay', [
        'key_id' => $keyId, 'key_secret' => 's', 'webhook_secret' => 'w',
        'base_url' => 'https://api.razorpay.com/v1', 'timeout_seconds' => 5,
    ]);
}

/** One alert on the app log and one on the payments channel — and no more. */
function expectOneCriticalAlert(): void
{
    $channel = Mockery::mock(LoggerInterface::class);
    $channel->shouldReceive('critical')->once();
    Log::shouldReceive('channel')->with('payments')->andReturn($channel);
    Log::shouldReceive('critical')->once();
}

beforeEach(function () {
    Cache::flush();
    paymentSetting('payments.gateway.stub.enabled', 'true');
    paymentSetting('payments.gateway.razorpay.enabled', 'false');
    config()->set('arovolife.payments.razorpay', ['key_id' => '', 'key_secret' => '', 'webhook_secret' => '', 'base_url' => 'https://api.razorpay.com/v1', 'timeout_seconds' => 5]);
});

it('PAY-R01: flag on with valid credentials resolves to Razorpay', function () {
    paymentSetting('payments.gateway.razorpay.enabled', 'true');
    validRazorpayConfig();

    $resolver = app(PaymentGatewayResolver::class);

    expect($resolver->state())->toBe(PaymentGatewayResolver::STATE_RAZORPAY)
        ->and($resolver->active())->toBeInstanceOf(RazorpayGateway::class)
        ->and($resolver->checkoutClosed())->toBeFalse();
});

it('PAY-R02: flag on with credentials missing closes checkout and never falls back to the stub', function () {
    paymentSetting('payments.gateway.razorpay.enabled', 'true');
    expectOneCriticalAlert();

    $resolver = app(PaymentGatewayResolver::class);

    expect($resolver->state())->toBe(PaymentGatewayResolver::STATE_CHECKOUT_CLOSED)
        ->and($resolver->active())->toBeNull()
        ->and($resolver->onlineAvailable())->toBeFalse()
        ->and($resolver->checkoutClosed())->toBeTrue();

    // Second evaluation inside the hour does not alert again.
    $resolver->state();
});

it('PAY-R03: flag on with a key of the wrong mode for the environment closes checkout', function () {
    paymentSetting('payments.gateway.razorpay.enabled', 'true');
    validRazorpayConfig('rzp_live_ABCDEF123456'); // live key in the testing environment
    expectOneCriticalAlert();

    expect(app(PaymentGatewayResolver::class)->state())->toBe(PaymentGatewayResolver::STATE_CHECKOUT_CLOSED);
});

it('PAY-R04: flag off with the stub enabled and permitted resolves to the stub', function () {
    $resolver = app(PaymentGatewayResolver::class);

    expect($resolver->state())->toBe(PaymentGatewayResolver::STATE_STUB)
        ->and($resolver->active())->toBeInstanceOf(StubGateway::class);
});

it('PAY-R05: flag off in production offers no online payment but does not close checkout', function () {
    app()->detectEnvironment(fn () => 'production');

    $resolver = app(PaymentGatewayResolver::class);

    expect($resolver->state())->toBe(PaymentGatewayResolver::STATE_NONE)
        ->and($resolver->onlineAvailable())->toBeFalse()
        ->and($resolver->checkoutClosed())->toBeFalse();
});

it('PAY-R06: the stub refuses the moment the Razorpay flag is on, whatever the allow-list says', function () {
    config()->set('arovolife.payments.stub.allowed_environments', ['local', 'testing', 'staging']);
    paymentSetting('payments.gateway.razorpay.enabled', 'true');

    expect(app(StubGateway::class)->permitted())->toBeFalse();
});

it('PAY-R07: the stub refuses when a live key is present at all', function () {
    config()->set('arovolife.payments.razorpay.key_id', 'rzp_live_ABCDEF123456');

    expect(app(StubGateway::class)->permitted())->toBeFalse();
});
