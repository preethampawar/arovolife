<?php

declare(strict_types=1);

/**
 * Contact form tests — ContactController (ADR-0003 public entry surface).
 *
 * CON-001: GET /contact-us renders the default banner (reason=general)
 * CON-002: GET /contact-us?reason=referral_link_required renders contextual banner
 * CON-003: POST with valid payload creates contact_inquiries row + queues notification + success flash
 * CON-004: POST with missing required fields returns 422
 * CON-005: 4 rapid POSTs from same IP — the 4th is throttled
 *
 * CSRF note: POST /contact-us is behind the global VerifyCsrfToken middleware.
 * In APP_ENV=testing, Laravel's PreventRequestForgery skips the token check
 * when runningInConsole() && runningUnitTests() — both true during Pest.
 * The withoutMiddleware() calls below are belt-and-braces for any environment
 * where the bypass does not fire (e.g. custom middleware stack).
 */

use App\Modules\Public\Models\ContactInquiry;
use App\Modules\Public\Notifications\NewContactInquiryNotification;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

// ─── helpers ─────────────────────────────────────────────────────────────────

/** Valid minimal payload for the contact form. */
function conValidPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ravi Kumar',
        'email' => 'ravi.kumar@example.com',
        'phone_e164' => '9876543210',
        'address' => '12 MG Road, Hyderabad, TS',
        'purpose' => 'become_distributor',
        'message' => 'I would like to know how to join as a distributor.',
        // DPDP Act 2023 §6 — explicit privacy consent is mandatory.
        // The controller validates this as 'required|accepted'.
        'consent_privacy' => '1',
    ], $overrides);
}

// ─── CON-001 ─────────────────────────────────────────────────────────────────

it('CON-001: GET /contact-us without reason param renders with reason=general', function () {
    $response = $this->get('/contact-us');

    $response->assertOk();
    $response->assertViewHas('reason', 'general');
});

it('CON-001b: GET /contact-us?reason=unknown is sanitised to general', function () {
    $response = $this->get('/contact-us?reason=inject_or_unknown_value');

    $response->assertOk();
    $response->assertViewHas('reason', 'general');
});

// ─── CON-002 ─────────────────────────────────────────────────────────────────

it('CON-002: GET /contact-us?reason=referral_link_required passes reason through to the view', function () {
    $response = $this->get('/contact-us?reason=referral_link_required');

    $response->assertOk();
    $response->assertViewHas('reason', 'referral_link_required');
});

it('CON-002b: GET /contact-us?reason=invalid_referral_link passes reason through to the view', function () {
    $response = $this->get('/contact-us?reason=invalid_referral_link');

    $response->assertOk();
    $response->assertViewHas('reason', 'invalid_referral_link');
});

// ─── CON-003 ─────────────────────────────────────────────────────────────────

it('CON-003: valid POST creates a contact_inquiries row with normalised phone and queued notification', function () {
    Notification::fake();
    RateLimiter::clear('contact:127.0.0.1');

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/contact-us', conValidPayload());

    $response->assertRedirect(route('contact.show'));

    // DB row created
    $row = ContactInquiry::latest()->first();
    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('Ravi Kumar')
        ->and($row->phone_e164)->toBe('+919876543210')  // normalised to +91 prefix
        ->and($row->purpose)->toBe('become_distributor');

    // Queued notification sent to support inbox
    Notification::assertSentOnDemand(NewContactInquiryNotification::class);
});

it('CON-003b: successful POST sets success flash on redirect', function () {
    RateLimiter::clear('contact:127.0.0.1');

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/contact-us', conValidPayload())
        ->assertSessionHas('status');
});

it('CON-003c: valid POST with reason param stores sanitised reason on the row', function () {
    Notification::fake();
    RateLimiter::clear('contact:127.0.0.1');

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/contact-us', conValidPayload(['reason' => 'referral_link_required']));

    $row = ContactInquiry::latest()->first();
    expect($row->reason)->toBe('referral_link_required');
});

it('CON-003d: POST with invalid reason stores null (sanitised)', function () {
    Notification::fake();
    RateLimiter::clear('contact:127.0.0.1');

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/contact-us', conValidPayload(['reason' => 'evil_reason_value']));

    $row = ContactInquiry::latest()->first();
    expect($row->reason)->toBeNull();
});

// ─── CON-004 ─────────────────────────────────────────────────────────────────

it('CON-004: POST with missing name returns 422 with validation error for name', function () {
    RateLimiter::clear('contact:127.0.0.1');

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/contact-us', conValidPayload(['name' => '']));

    $response->assertSessionHasErrors('name');
});

it('CON-004b: POST with missing email returns validation error for email', function () {
    RateLimiter::clear('contact:127.0.0.1');

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/contact-us', conValidPayload(['email' => '']))
        ->assertSessionHasErrors('email');
});

it('CON-004c: POST with invalid email format returns validation error for email', function () {
    RateLimiter::clear('contact:127.0.0.1');

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/contact-us', conValidPayload(['email' => 'not-an-email']))
        ->assertSessionHasErrors('email');
});

it('CON-004d: POST with invalid phone returns validation error for phone_e164', function () {
    RateLimiter::clear('contact:127.0.0.1');

    // Starts with 5 — not a valid Indian mobile
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/contact-us', conValidPayload(['phone_e164' => '5123456789']))
        ->assertSessionHasErrors('phone_e164');
});

it('CON-004e: POST with invalid purpose enum returns validation error for purpose', function () {
    RateLimiter::clear('contact:127.0.0.1');

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/contact-us', conValidPayload(['purpose' => 'not_a_valid_purpose']))
        ->assertSessionHasErrors('purpose');
});

it('CON-004f: POST with missing message returns validation error for message', function () {
    RateLimiter::clear('contact:127.0.0.1');

    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/contact-us', conValidPayload(['message' => '']))
        ->assertSessionHasErrors('message');
});

it('CON-004g: POST with no fields at all returns multiple validation errors', function () {
    RateLimiter::clear('contact:127.0.0.1');

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/contact-us', []);

    $response->assertSessionHasErrors(['name', 'email', 'phone_e164', 'address', 'purpose', 'message']);
});

// ─── CON-005 ─────────────────────────────────────────────────────────────────

it('CON-005: 4th POST from the same IP within the rate-limit window returns a throttle error', function () {
    // The controller allows maxAttempts: 3 per IP per hour.
    // We clear any previous hits, make 3 valid submissions, then assert the 4th is throttled.
    RateLimiter::clear('contact:127.0.0.1');
    Notification::fake();

    for ($i = 0; $i < 3; $i++) {
        $this->withoutMiddleware(PreventRequestForgery::class)
            ->post('/contact-us', conValidPayload(['email' => "user{$i}@test.com"]));
    }

    // 4th attempt — must be throttled
    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/contact-us', conValidPayload(['email' => 'throttled@test.com']));

    $response->assertSessionHasErrors('message');

    // Only 3 rows should exist in the DB — the throttled request must not write
    expect(ContactInquiry::count())->toBe(3);
});

it('CON-005b: throttle error message mentions "Too many submissions"', function () {
    RateLimiter::clear('contact:127.0.0.1');
    Notification::fake();

    for ($i = 0; $i < 3; $i++) {
        $this->withoutMiddleware(PreventRequestForgery::class)
            ->post('/contact-us', conValidPayload(['email' => "user{$i}@throttle.test"]));
    }

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/contact-us', conValidPayload(['email' => 'over@throttle.test']));

    // assertSessionHasErrors populates the in-memory error bag from the response.
    $response->assertSessionHasErrors('message');

    // Verify the message text via the response's error bag (avoids the raw-array
    // issue with session() helper in the array session driver).
    $errorBag = $response->baseResponse->getSession()->get('errors');
    $messageErrors = $errorBag->getBag('default')->get('message');

    expect(implode(' ', $messageErrors))->toContain('Too many submissions');
});
