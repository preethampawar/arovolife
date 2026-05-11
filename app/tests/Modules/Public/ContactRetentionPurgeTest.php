<?php

declare(strict_types=1);

use App\Modules\Public\Models\ContactInquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Risk register R-15 / DPDP §8(3) — `contact-inquiries:purge` deletes stale
 * personal data after the configured retention window.
 */
function makeInquiry(array $overrides = []): ContactInquiry
{
    return ContactInquiry::create(array_merge([
        'name' => 'Test '.rand(1, 9999),
        'email' => 'r-'.rand(1000, 9999).'@test.com',
        'phone_e164' => '+919999999999',
        'address' => 'somewhere',
        'city' => 'Hyderabad',
        'district' => 'Hyderabad',
        'state' => 'TG',
        'pin_code' => '500001',
        'purpose' => 'support',
        'message' => 'hi',
        'reason' => 'general',
        'ip' => '127.0.0.1',
        'user_agent' => 'PestTest',
        'privacy_consent_at' => now(),
    ], $overrides));
}

it('PURGE-01: unhandled inquiry older than 90d is deleted', function () {
    $stale = makeInquiry();
    $stale->forceFill(['created_at' => now()->subDays(91)])->save();

    $this->artisan('contact-inquiries:purge')->assertSuccessful();

    expect(ContactInquiry::find($stale->id))->toBeNull();
});

it('PURGE-02: unhandled inquiry younger than 90d is preserved', function () {
    $fresh = makeInquiry();
    $fresh->forceFill(['created_at' => now()->subDays(45)])->save();

    $this->artisan('contact-inquiries:purge')->assertSuccessful();

    expect(ContactInquiry::find($fresh->id))->not->toBeNull();
});

it('PURGE-03: handled inquiry older than 365d is deleted on the longer window', function () {
    $oldHandled = makeInquiry();
    $oldHandled->forceFill([
        'created_at' => now()->subDays(400),
        'handled_at' => now()->subDays(370),
    ])->save();

    $this->artisan('contact-inquiries:purge')->assertSuccessful();

    expect(ContactInquiry::find($oldHandled->id))->toBeNull();
});

it('PURGE-04: handled inquiry younger than 365d is preserved even past 90d', function () {
    $recentHandled = makeInquiry();
    $recentHandled->forceFill([
        'created_at' => now()->subDays(120),
        'handled_at' => now()->subDays(100),
    ])->save();

    $this->artisan('contact-inquiries:purge')->assertSuccessful();

    expect(ContactInquiry::find($recentHandled->id))->not->toBeNull();
});

it('PURGE-05: writes one audit_log row with counts but no PII', function () {
    makeInquiry()->forceFill(['created_at' => now()->subDays(91)])->save();
    makeInquiry()->forceFill(['created_at' => now()->subDays(95)])->save();

    $this->artisan('contact-inquiries:purge')->assertSuccessful();

    $audit = DB::table('audit_log')
        ->where('action', 'contact_inquiry.retention_purge')
        ->orderByDesc('id')
        ->first();

    expect($audit)->not->toBeNull();
    $details = json_decode($audit->details, true);
    expect($details['unhandled_deleted'])->toBe(2)
        ->and($details['handled_deleted'])->toBe(0)
        ->and($details)->not->toHaveKey('email')
        ->and($details)->not->toHaveKey('name')
        ->and($details)->not->toHaveKey('phone_e164');
});

it('PURGE-06: --unhandled-days flag shifts the threshold', function () {
    $thirty = makeInquiry();
    $thirty->forceFill(['created_at' => now()->subDays(31)])->save();

    // Default 90 → preserve. With --unhandled-days=30 → delete.
    $this->artisan('contact-inquiries:purge', ['--unhandled-days' => 30])->assertSuccessful();

    expect(ContactInquiry::find($thirty->id))->toBeNull();
});
