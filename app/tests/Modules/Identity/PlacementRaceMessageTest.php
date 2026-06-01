<?php

declare(strict_types=1);

use App\Modules\Genealogy\Services\Exceptions\PlacementSlotFullError;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RegistrationService;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * PRM-01 / PRM-02 — when the placement slot is claimed between step 1
 * and final Confirm, the user must land on /contact-us with the
 * dedicated `placement_taken` reason so the page shows the friendly
 * "your slot got raced" message, NOT the loud "That referral link
 * couldn't be verified" red banner that used to fire and confuse
 * users into thinking their link was bad.
 *
 * Regression-locks the production bug where users completing
 * registration with a manually-entered Sponsor/Placement ID (or any
 * scenario where the placement target's slot fills up during the
 * 7-day draft window) saw a misleading "invalid_referral_link"
 * error instead of the actionable placement-race message.
 */
function prmSetWizardAtComplete(User $user): void
{
    test()->actingAs($user);
    test()->withSession([
        'registration_wizard' => [
            'step' => 10,
            'user_id' => $user->id,
            'sponsor_id' => 1,
            'data' => [
                'account' => ['email' => $user->email, 'phone_e164' => $user->phone_e164],
                'pan' => ['pan_number' => 'PRMRA1234X'],
                'aadhaar' => ['ref' => 'STUB', 'last4' => '9012', 'aadhaar_number' => '123456789012'],
                'bank' => ['account_number' => null, 'ifsc' => null],
                'personal' => ['date_of_birth' => '1990-01-01', 'state' => 'TG'],
                'consent' => ['accepted' => true],
                'orientation' => ['watched' => true],
                'documents' => ['documents' => []],
                'placement' => ['placement_id' => 1, 'side' => null],
            ],
        ],
    ]);
}

it('PRM-01: PlacementSlotFullError at Confirm step redirects to ?reason=placement_taken (NOT invalid_referral_link)', function (): void {
    $user = User::create([
        'email' => 'race-'.rand(10000, 99999).'@test.com',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => now(),
        'full_name' => 'Race Subject',
        'status' => 'pending',
    ]);

    // Mock RegistrationService so finalise() always throws the
    // placement-race exception. Removes the need to seed a full tree
    // + race two concurrent registrations; the controller's catch
    // block is what we're actually testing. Mockery's mock() can
    // proxy the final class as long as we resolve through the
    // container.
    $mock = Mockery::mock(RegistrationService::class);
    $mock->shouldReceive('finalise')->andThrow(new PlacementSlotFullError('L', 1));
    $this->app->instance(RegistrationService::class, $mock);

    prmSetWizardAtComplete($user);

    $response = $this->withoutMiddleware(PreventRequestForgery::class)
        ->post('/register/complete');

    $response->assertRedirect('/contact-us?reason=placement_taken');
});

it('PRM-02: contact page renders the placement-race banner for ?reason=placement_taken', function (): void {
    $response = $this->get('/contact-us?reason=placement_taken');

    $response->assertStatus(200);
    // The friendly placement-race banner copy, NOT the red
    // "That referral link couldn't be verified" copy.
    $response->assertSee('Your placement slot was just claimed');
    $response->assertDontSee("That referral link couldn't be verified");
});
