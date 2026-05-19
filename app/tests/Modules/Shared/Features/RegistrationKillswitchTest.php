<?php

declare(strict_types=1);

use App\Modules\Shared\Features\RegistrationKillswitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

it('FF-01: registration is open by default — /register does not 503', function (): void {
    // With no sponsor/placement query params, start() redirects to /contact-us
    // (referral_link_required). The KEY assertion is that we don't return 503;
    // the killswitch is OFF means 503, ON means we get the normal flow.
    $response = $this->get('/register');

    expect($response->status())->not->toBe(503);
});

it('FF-02: when the killswitch is deactivated, /register returns 503 with the closed view', function (): void {
    Feature::deactivate(RegistrationKillswitch::class);

    $response = $this->get('/register');

    $response->assertStatus(503);
    $response->assertViewIs('registration.closed');
});

it('FF-03: when the killswitch is deactivated, /register/account returns 503', function (): void {
    Feature::deactivate(RegistrationKillswitch::class);

    $response = $this->get('/register/account');

    $response->assertStatus(503);
    $response->assertViewIs('registration.closed');
});

it('FF-04: when the killswitch is deactivated, /join (back-compat) returns 503', function (): void {
    Feature::deactivate(RegistrationKillswitch::class);

    $response = $this->get('/join');

    $response->assertStatus(503);
    $response->assertViewIs('registration.closed');
});

it('FF-05: re-activating the killswitch restores normal behaviour', function (): void {
    Feature::deactivate(RegistrationKillswitch::class);
    Feature::activate(RegistrationKillswitch::class);

    $response = $this->get('/register');

    expect($response->status())->not->toBe(503);
});
