<?php

declare(strict_types=1);

/**
 * Smoke tests that the distributor dashboard actually RENDERS. A missing
 * render test let a Blade compile error (an inline @php(...) with a method
 * call) ship a 500 on /dashboard. DSH-01 exercises the account-status pill
 * (rendered for every user); DSH-02 exercises the distributor ID-card panel.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AreteCenterApplicationsFeature;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\DistributorRequestsFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

function dshUser(string $status = 'active'): User
{
    return User::create([
        'full_name' => 'Dash User',
        'email' => 'dsh-'.uniqid().'@example.com',
        'phone_e164' => '+91955'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
        'password_hash' => Hash::make('dsh-test-pwd-2026'),
        'password_set_at' => now(),
        'status' => $status,
        'email_verified_at' => now(),
    ]);
}

function dshDistributor(User $user): int
{
    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => (string) random_int(100000000, 999999999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '0000',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
            'sponsor_id' => 0,
            'placement_parent_id' => 0,
            'placement_side' => null,
            'side_chosen_by' => 'referral_default',
            'depth' => 0,
            'effective_date' => now()->format('Y-m-d H:i:s.v'),
            'cooling_off_end_at' => now()->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
            'state' => 'TS',
            'is_primary_couple' => 0,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }
    DB::table('genealogy_closure')->insert(['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0]);

    return $id;
}

it('DSH-01: dashboard renders for an authenticated user with the account-status pill', function () {
    $user = dshUser('active');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Status:', false)
        ->assertSee('Active', false);
});

it('DSH-02: dashboard renders the ID-card panel for a distributor', function () {
    $user = dshUser('active');
    dshDistributor($user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Profile Stats', false)
        ->assertSee('ID Number', false)
        ->assertSee('Status', false);
});

it('PS-01: profile-stats prints the ID-card panel with the company header for a distributor', function () {
    $user = dshUser('active');
    dshDistributor($user);

    $this->actingAs($user)
        ->get(route('profile-stats.show'))
        ->assertOk()
        ->assertSee('Profile Stats', false)
        ->assertSee('Arovolife Private Limited', false)
        ->assertSee('ID Number', false);
});

it('PS-02: profile-stats redirects to the dashboard when registration is incomplete (no distributor)', function () {
    $user = dshUser('active');

    $this->actingAs($user)
        ->get(route('profile-stats.show'))
        ->assertRedirect(route('dashboard'));
});

it('DSH-03: dashboard ID-photo input opens the crop modal (no auto-submit)', function () {
    $user = dshUser('active');
    dshDistributor($user);

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    // The Cropper.js crop/pan/zoom modal markup is present...
    $response->assertSee('id="idPhotoCropModal"', false);
    $response->assertSee('id="idPhotoCropImage"', false);
    $response->assertSee('id="idPhotoCropSave"', false);
    $response->assertSee('Crop your ID photo', false);

    // ...and the file input no longer auto-submits the form on change.
    $response->assertDontSee("onchange=\"document.getElementById('idPhotoForm').submit();\"", false);
});

it('DSH-04: dashboard keeps every legacy element alongside the new KPI strip and quick actions', function () {
    $user = dshUser('active');
    dshDistributor($user);

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    foreach ([
        // Legacy elements that must survive the redesign.
        'Manage my KYC documents', 'My Referral Link', 'Personal invite', 'Copy',
        'Profile Stats', 'Download PDF', 'Placement', 'My Business', 'Request line-change',
        'Cooling-Off Period', 'Cancel registration', 'Messages', 'Documents', 'Membership Card',
        'My Team', 'data-team-roster="total"', 'data-team-roster="direct"',
        'data-team-roster="left"', 'data-team-roster="right"', 'id="team-roster-modal"',
        'Phase 1 Platform',
        // New surfaces.
        'Personal BV', 'Wallet balance', 'Total team', 'Direct referrals',
        'Quick actions', 'Genos balance', 'Team growth', 'Member since',
    ] as $needle) {
        $response->assertSee($needle, false);
    }
});

it('DSH-05: flag-gated dashboard surfaces leave no trace while their features are off', function () {
    $user = dshUser('active');
    dshDistributor($user);

    foreach ([
        GenosSalesBonusFeature::class,
        MentorshipBonusFeature::class,
        GrowthBoosterBonusFeature::class,
        RankBonusFeature::class,
        FortuneBonusFeature::class,
        AreteDevelopmentCenterBonusFeature::class,
        PurchaseOffersFeature::class,
        AreteCenterApplicationsFeature::class,
        DistributorRequestsFeature::class,
    ] as $flag) {
        Feature::for(null)->deactivate($flag);
    }

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Left Genos BV', false)
        ->assertDontSee('Income snapshot', false)
        // The quick-action tiles specifically (the topnav has its own, separately gated, links).
        ->assertDontSee('leading-tight">My Offers</span>', false)
        ->assertDontSee('leading-tight">Arete Centres</span>', false)
        ->assertDontSee('leading-tight">My Requests</span>', false)
        ->assertDontSee('Power side', false)
        ->assertDontSee('Weaker side', false);
});

it('DSH-06: flag-gated dashboard surfaces appear with wallet-credited figures once their features are on', function () {
    $user = dshUser('active');
    $id = dshDistributor($user);

    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    Feature::for(null)->activate(PurchaseOffersFeature::class);
    Feature::for(null)->activate(AreteCenterApplicationsFeature::class);
    Feature::for(null)->activate(DistributorRequestsFeature::class);

    DB::table('wallet_ledger_entries')->insert([
        ['distributor_id' => $id, 'type' => 'gsb_credit', 'amount_paise' => 200_000, 'created_at' => now()],
        ['distributor_id' => $id, 'type' => 'gsb_credit', 'amount_paise' => 100_000, 'created_at' => now()->subMonths(2)],
        ['distributor_id' => $id, 'type' => 'payout_debit', 'amount_paise' => -50_000, 'created_at' => now()],
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Left Genos BV', false)
        ->assertSee('Right Genos BV', false)
        ->assertSee('Income snapshot', false)
        ->assertSee('Genos Sales Bonus', false)
        ->assertSee('₹2,000', false)   // this month
        ->assertSee('₹3,000', false)   // lifetime
        ->assertSee('₹2,500', false)   // wallet balance after the debit
        ->assertSee('Wallet credits, last 6 months', false)
        ->assertSee('leading-tight">My Offers</span>', false)
        ->assertSee('leading-tight">Arete Centres</span>', false)
        ->assertSee('leading-tight">My Requests</span>', false)
        ->assertDontSee('Power side', false)
        ->assertDontSee('Weaker side', false);
});
