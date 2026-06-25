<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

function incomeDistributor(): array
{
    $user = User::create([
        'full_name' => 'Income Test',
        'email' => 'income-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    disableTestForeignKeys();
    try {
        $id = DB::table('distributors')->insertGetId([
            'user_id' => $user->id,
            'adn' => 'ADN'.random_int(10000, 99999),
            'pan_hash' => random_bytes(32),
            'pan_last4' => '1234',
            'bank_account_enc' => 'stub',
            'bank_ifsc' => 'SBIN0000000',
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
        DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $id, 'placement_parent_id' => $id]);
    } finally {
        enableTestForeignKeys();
    }

    return ['user' => $user, 'distributorId' => $id];
}

it('redirects unauthenticated users from all income routes', function (): void {
    $routes = [
        route('income.dashboard'),
        route('income.genos-bv'),
        route('income.gsb-history'),
        route('income.mentorship'),
        route('income.wallet'),
    ];

    foreach ($routes as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
});

it('returns 403 for authenticated user with no distributor record', function (): void {
    Feature::for(null)->activate(MentorshipBonusFeature::class);
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);

    $user = User::create([
        'full_name' => 'No Dist',
        'email' => 'nodist-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
    $this->actingAs($user);

    $this->get(route('income.dashboard'))->assertForbidden();
    $this->get(route('income.genos-bv'))->assertForbidden();
    $this->get(route('income.gsb-history'))->assertForbidden();
    $this->get(route('income.mentorship'))->assertForbidden();
    $this->get(route('income.wallet'))->assertForbidden();
});

it('renders income dashboard for a distributor', function (): void {
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.dashboard'))
        ->assertOk()
        ->assertSee('My Income');
});

it('renders genos bv page with empty state', function (): void {
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.genos-bv'))
        ->assertOk()
        ->assertSee('Genos BV');
});

it('renders gsb history page with empty state', function (): void {
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.gsb-history'))
        ->assertOk()
        ->assertSee('GSB History');
});

it('renders mentorship page with empty state', function (): void {
    Feature::for(null)->activate(MentorshipBonusFeature::class);
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.mentorship'))
        ->assertOk()
        ->assertSee('Mentorship Bonus');
});

it('returns 404 for mentorship page when feature flag is off', function (): void {
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.mentorship'))->assertNotFound();
});

it('returns 404 for growth booster page when feature flag is off', function (): void {
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.growth-booster'))->assertNotFound();
});

it('renders wallet page with empty state', function (): void {
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.wallet'))
        ->assertOk()
        ->assertSee('Wallet');
});

it('streams gsb history csv for authenticated distributor', function (): void {
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.gsb-history.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});

it('streams wallet ledger csv for authenticated distributor', function (): void {
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.wallet.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});
