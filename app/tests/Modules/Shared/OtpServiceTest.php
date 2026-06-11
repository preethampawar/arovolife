<?php

declare(strict_types=1);

use App\Modules\Shared\Otp\OtpService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The reusable OTP engine (single source of truth for code issue/verify across
 * the app). Channel-agnostic: it does not send — it generates, stores (hashed)
 * and verifies, returning the issuer's payload on success.
 */
function otp(): OtpService
{
    return app(OtpService::class);
}

beforeEach(function (): void {
    Cache::flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('OTP-01: issues a 6-digit code and verifies it, returning the payload', function (): void {
    $svc = otp();
    $code = $svc->issue('test_purpose', 'k1', ['email' => 'new@example.com']);

    expect($code)->toMatch('/^\d{6}$/');

    $result = $svc->verify('test_purpose', 'k1', $code);
    expect($result->ok)->toBeTrue();
    expect($result->payload['email'])->toBe('new@example.com');
});

it('OTP-02: a wrong code fails as invalid; the right code still works within the attempt cap', function (): void {
    $svc = otp();
    $code = $svc->issue('test_purpose', 'k2', []);

    $wrong = $svc->verify('test_purpose', 'k2', $code === '000000' ? '111111' : '000000');
    expect($wrong->ok)->toBeFalse();
    expect($wrong->reason)->toBe('invalid');

    expect($svc->verify('test_purpose', 'k2', $code)->ok)->toBeTrue();
});

it('OTP-03: consumes the code on success — it cannot be reused', function (): void {
    $svc = otp();
    $code = $svc->issue('test_purpose', 'k3', []);

    expect($svc->verify('test_purpose', 'k3', $code)->ok)->toBeTrue();
    $again = $svc->verify('test_purpose', 'k3', $code);
    expect($again->ok)->toBeFalse();
    expect($again->reason)->toBe('expired'); // record gone
});

it('OTP-04: locks out after MAX_ATTEMPTS wrong guesses', function (): void {
    $svc = otp();
    $code = $svc->issue('test_purpose', 'k4', []);
    $wrong = $code === '000000' ? '111111' : '000000';

    for ($i = 0; $i < OtpService::MAX_ATTEMPTS; $i++) {
        expect($svc->verify('test_purpose', 'k4', $wrong)->reason)->toBe('invalid');
    }

    // Even the CORRECT code is now rejected (lock cleared the record).
    $locked = $svc->verify('test_purpose', 'k4', $code);
    expect($locked->ok)->toBeFalse();
    expect($locked->reason)->toBeIn(['too_many_attempts', 'expired']);
});

it('OTP-05: expires after its TTL', function (): void {
    $svc = otp();
    $code = $svc->issue('test_purpose', 'k5', [], ttlSeconds: 60);

    Carbon::setTestNow(Carbon::now()->addSeconds(61));

    expect($svc->verify('test_purpose', 'k5', $code)->reason)->toBe('expired');
});

it('OTP-06: peek returns the payload without consuming; clear removes it', function (): void {
    $svc = otp();
    $svc->issue('test_purpose', 'k6', ['phone' => '+919812345678']);

    expect($svc->peek('test_purpose', 'k6'))->toMatchArray(['phone' => '+919812345678']);
    expect($svc->pending('test_purpose', 'k6'))->toBeTrue();

    $svc->clear('test_purpose', 'k6');
    expect($svc->pending('test_purpose', 'k6'))->toBeFalse();
    expect($svc->peek('test_purpose', 'k6'))->toBeNull();
});

it('OTP-07: codes are scoped by purpose + key (no cross-talk)', function (): void {
    $svc = otp();
    $code = $svc->issue('purpose_a', 'k7', []);

    // Same code, different purpose/key → not valid there.
    expect($svc->verify('purpose_b', 'k7', $code)->ok)->toBeFalse();
    expect($svc->verify('purpose_a', 'other', $code)->ok)->toBeFalse();
    // Original scope still works.
    expect($svc->verify('purpose_a', 'k7', $code)->ok)->toBeTrue();
});
