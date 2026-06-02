<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * WPM-02 .. WPM-04 — EnsureRegistrationProgress middleware behaviour on the
 * wizard step routes (pure session-only registration; the draft/av_draft
 * resume infrastructure was removed in ffd816b). The middleware's branches:
 *
 *  • Active wizard state in the session → pass through
 *  • No state → redirect to /join (with an expired-session notice)
 *
 * A stray/garbage av_draft cookie is ignored (it no longer means anything) and
 * still bounces to /join.
 */
function wpmSeedSponsorRoot(): int
{
    $user = User::create([
        'email' => 'wpm-sponsor-'.rand(10000, 99999).'@arovolife.local',
        'phone_e164' => '+91'.str_pad((string) rand(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('placeholder'),
        'password_set_at' => null,
        'full_name' => 'Arovolife Private Limited',
        'status' => 'active',
        'activated_at' => now(),
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) rand(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'aadhaar_ref' => 'WPM_ROOT',
            'aadhaar_last4' => '0000',
            'bank_account_enc' => null,
            'bank_ifsc' => null,
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update([
            'sponsor_id' => $id, 'placement_parent_id' => $id,
        ]);
    } finally {
        enableTestForeignKeys();
    }
    DB::table('genealogy_closure')->insert([
        'ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0,
    ]);

    return $id;
}

it('WPM-02: NO session + NO cookie → middleware redirects to /join (not /login)', function (): void {
    // The fallback path — no way to identify which wizard belongs to the
    // visitor. A fresh user mid-wizard whose session vanished has no account
    // to log in to yet, so we send them to /join (the start of the funnel)
    // with a session-expired notice. The previous behaviour redirected to
    // /login, which stranded fresh users on a page they couldn't use.
    $response = $this->get(route('register.orientation'));
    $response->assertRedirect(route('join.show'));
});

it('WPM-03: active wizard session + correct step → passthrough', function (): void {
    // Regular case: the user is mid-flow with their wizard session intact.
    // Drive the session via the real WizardStateService API: start() seeds
    // step 2, saving step-2 data advances the furthest-allowed step to 3, so
    // the orientation route (wizard.progress:3) passes through.
    $rootId = wpmSeedSponsorRoot();

    $wizard = app(WizardStateService::class);
    $wizard->start(sponsorId: $rootId, placementId: $rootId, sideOpt: 'L');
    $wizard->saveStepData(2, ['email' => 'wpm3-'.rand(1000, 9999).'@test.com']);

    $response = $this->get(route('register.orientation'));
    $response->assertStatus(200);
});

it('WPM-04: garbage av_draft cookie (no matching draft) → middleware ignores it + bounces to /join', function (): void {
    // Defensive: a tampered or stale cookie value mustn't promote the
    // visitor into a stranger's session. Like WPM-02, the destination is
    // /join (the funnel start) rather than /login — a tampered cookie
    // doesn't imply the visitor has an account to log into.
    $response = $this->withCookies(['av_draft' => bin2hex(random_bytes(32))])
        ->get(route('register.orientation'));

    $response->assertRedirect(route('join.show'));
    expect(auth()->check())->toBeFalse();
});
