<?php

declare(strict_types=1);

use App\Modules\Shared\Logging\PiiScrubberProcessor;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * The PII scrubber must redact:
 *  - PAN (^[A-Z]{5}[0-9]{4}[A-Z]$)
 *  - Aadhaar (12 digits, optionally space- or dash-separated in groups of 4)
 *  - Sensitive context keys: password, password_confirmation, otp, mfa_secret*,
 *    token, _token, api_key, secret
 * Whether the value lives in the message string or in the structured context.
 */
function makeRecord(string $message, array $context = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: $message,
        context: $context,
    );
}

it('PII-01: PAN in the log message is redacted', function () {
    $rec = (new PiiScrubberProcessor)(makeRecord('User PAN ABCDE1234F submitted'));
    expect($rec->message)->toContain('[REDACTED:PAN]')
        ->and($rec->message)->not->toContain('ABCDE1234F');
});

it('PII-02: 12-digit Aadhaar (with or without spacing) is redacted', function () {
    $rec1 = (new PiiScrubberProcessor)(makeRecord('aadhaar=234567890123 ok'));
    $rec2 = (new PiiScrubberProcessor)(makeRecord('aadhaar=2345 6789 0123 ok'));
    $rec3 = (new PiiScrubberProcessor)(makeRecord('aadhaar=2345-6789-0123 ok'));

    expect($rec1->message)->toContain('[REDACTED:AADHAAR]')->and($rec1->message)->not->toContain('234567890123')
        ->and($rec2->message)->toContain('[REDACTED:AADHAAR]')->and($rec2->message)->not->toContain('2345 6789 0123')
        ->and($rec3->message)->toContain('[REDACTED:AADHAAR]')->and($rec3->message)->not->toContain('2345-6789-0123');
});

it('PII-03: sensitive context keys are replaced wholesale', function () {
    $rec = (new PiiScrubberProcessor)(makeRecord('login attempt', [
        'email' => 'a@b.com',           // not sensitive — kept
        'password' => 'hunter2',
        'password_confirmation' => 'hunter2',
        'mfa_secret' => 'JBSWY3DPEHPK3PXP',
        'mfa_secret_enc' => 'cipher',
        'otp' => '123456',
        'token' => 'tk-xyz',
        'api_key' => 'ak-abc',
        '_token' => 'csrf-tok',
        'secret' => 'shh',
    ]));

    expect($rec->context['email'])->toBe('a@b.com');
    foreach (['password', 'password_confirmation', 'mfa_secret', 'mfa_secret_enc', 'otp', 'token', 'api_key', '_token', 'secret'] as $key) {
        expect($rec->context[$key])->toBe('[REDACTED]');
    }
});

it('PII-04: PAN inside a nested context value is redacted (string sweep)', function () {
    $rec = (new PiiScrubberProcessor)(makeRecord('post', [
        'request_body' => 'name=Foo&pan=ABCDE1234F&state=TG',
    ]));

    expect($rec->context['request_body'])->toContain('[REDACTED:PAN]')
        ->and($rec->context['request_body'])->not->toContain('ABCDE1234F');
});

it('PII-05: 12-digit numbers that are not Aadhaar-shaped are still scrubbed (false positive accepted)', function () {
    // We deliberately accept the false positive of redacting any 12-digit
    // sequence — under-scrubbing would be a compliance breach; over-scrubbing
    // only loses incidental telemetry. Lock the over-scrub direction here.
    $rec = (new PiiScrubberProcessor)(makeRecord('order id = 999999999999'));
    expect($rec->message)->toContain('[REDACTED:AADHAAR]');
});

it('PII-06: short numbers and ordinary text are left alone', function () {
    $rec = (new PiiScrubberProcessor)(makeRecord('login from 127.0.0.1, status 200, took 42ms'));
    expect($rec->message)->toBe('login from 127.0.0.1, status 200, took 42ms');
});

it('PII-07: arovolife column / form-field keys are scrubbed by name', function () {
    $rec = (new PiiScrubberProcessor)(makeRecord('audit', [
        'pan_number' => 'ABCDE1234F',
        'pan_hash' => 'binary-bytes',
        'aadhaar_number' => '234567890123',
        'aadhaar_ref' => 'STUB-REF',
        'account_number' => '912345678012',
        'bank_account_enc' => 'eyJpdiI6...',
    ]));

    foreach (['pan_number', 'pan_hash', 'aadhaar_number', 'aadhaar_ref', 'account_number', 'bank_account_enc'] as $key) {
        expect($rec->context[$key])->toBe('[REDACTED]');
    }
});
