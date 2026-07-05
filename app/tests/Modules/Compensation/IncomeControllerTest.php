<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        route('income.genos-ledger'),
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

it('shows group BV as 0 on the dashboard when personal BV is below 600', function (): void {
    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    // Downline BV has accumulated, but the distributor has no personal BV.
    DB::table('group_bv_daily')->insert([
        'distributor_id' => $distributorId,
        'date' => Carbon::today('Asia/Kolkata')->toDateString(),
        'left_bv_paise' => 150_000, // 1,500 BV
        'right_bv_paise' => 80_000, // 800 BV
    ]);

    $this->get(route('income.dashboard'))
        ->assertOk()
        ->assertSee('requires 600 BV of personal purchases')
        ->assertDontSee('1,500')
        ->assertDontSee('as of last page load');
});

it('shows accumulated group BV on the dashboard once personal BV reaches 600', function (): void {
    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    disableTestForeignKeys();
    try {
        DB::table('bv_ledger_entries')->insert([
            'distributor_id' => $distributorId,
            'order_id' => 999_999,
            'bv_paise' => 60_000, // exactly 600 BV — the gate is >=
            'type' => 'accrual',
            'effective_at' => now()->format('Y-m-d H:i:s.v'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } finally {
        enableTestForeignKeys();
    }

    DB::table('group_bv_daily')->insert([
        'distributor_id' => $distributorId,
        'date' => Carbon::today('Asia/Kolkata')->toDateString(),
        'left_bv_paise' => 150_000, // 1,500 BV
        'right_bv_paise' => 80_000, // 800 BV
    ]);

    $this->get(route('income.dashboard'))
        ->assertOk()
        ->assertSee('1,500')
        ->assertSee('as of last page load')
        ->assertDontSee('requires 600 BV of personal purchases');
});

it('shows the genos ledger with buyer ADN only — never the buyer name', function (): void {
    ['user' => $user, 'distributorId' => $rootId] = incomeDistributor();
    ['user' => $buyerUser, 'distributorId' => $buyerId] = incomeDistributor();
    $this->actingAs($user);

    DB::table('users')->where('id', $buyerUser->id)->update(['full_name' => 'Secret Buyer Name']);
    DB::table('distributors')->where('id', $buyerId)
        ->update(['placement_parent_id' => $rootId, 'placement_side' => 'L']);
    DB::table('genealogy_closure')->insert([
        ['ancestor_id' => $buyerId, 'descendant_id' => $buyerId, 'depth' => 0],
        ['ancestor_id' => $rootId, 'descendant_id' => $buyerId, 'depth' => 1],
    ]);

    disableTestForeignKeys();
    try {
        // Root has 600 personal BV — eligible to see the ledger.
        DB::table('bv_ledger_entries')->insert([
            'distributor_id' => $rootId,
            'order_id' => 999_998,
            'bv_paise' => 60_000,
            'type' => 'accrual',
            'effective_at' => now()->format('Y-m-d H:i:s.v'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } finally {
        enableTestForeignKeys();
    }

    DB::table('bv_propagation_log')->insert([
        'order_id' => 2001,
        'distributor_id' => $buyerId,
        'bv_paise' => 50_000, // 500 BV on the left
        'date' => today()->toDateString(),
    ]);

    $buyerAdn = DB::table('distributors')->where('id', $buyerId)->value('adn');

    $this->get(route('income.genos-ledger'))
        ->assertOk()
        ->assertSee($buyerAdn)
        ->assertSee('+500')
        ->assertSee('Cut-off pending for this day.')
        ->assertDontSee('Secret Buyer Name');
});

it('hides the genos ledger below 600 personal BV', function (): void {
    ['user' => $user, 'distributorId' => $rootId] = incomeDistributor();
    $this->actingAs($user);

    DB::table('bv_propagation_log')->insert([
        'order_id' => 2002,
        'distributor_id' => $rootId + 1, // any downline row; ledger must not render regardless
        'bv_paise' => 50_000,
        'date' => today()->toDateString(),
    ]);

    $this->get(route('income.genos-ledger'))
        ->assertOk()
        ->assertSee('Genos BV is not being counted yet.')
        ->assertDontSee('Purchase BV');
});

it('renders genos bv page with empty state', function (): void {
    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.genos-bv'))
        ->assertOk()
        ->assertSee('Genos BV');
});

it('shows the slab ladder with the next target and remaining matched BV', function (): void {
    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    disableTestForeignKeys();
    try {
        // 600 personal BV — eligible for group BV counting, but below the
        // 3,000 BV Retailer title, so every slab is still title-locked.
        DB::table('bv_ledger_entries')->insert([
            'distributor_id' => $distributorId,
            'order_id' => 999_997,
            'bv_paise' => 60_000,
            'type' => 'accrual',
            'effective_at' => now()->format('Y-m-d H:i:s.v'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } finally {
        enableTestForeignKeys();
    }

    DB::table('group_bv_daily')->insert([
        'distributor_id' => $distributorId,
        'date' => Carbon::today('Asia/Kolkata')->toDateString(),
        'left_bv_paise' => 1_250_000, // 12,500 BV
        'right_bv_paise' => 300_000,  // 3,000 BV — matched side
    ]);

    $this->get(route('income.genos-bv'))
        ->assertOk()
        ->assertSee('Slab ladder')
        ->assertSee('12,500')
        ->assertSee('Next target')
        ->assertSee('12,000 BV more to match') // slab 1: 15,000 − 3,000 matched
        ->assertSee('unlocks at 3,000 BV of personal purchases');
});

it('highlights earned slabs on the ladder', function (): void {
    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    DB::table('gsb_cutoff_results')->insert([
        'distributor_id' => $distributorId,
        'cutoff_date' => today()->toDateString(),
        'left_bv_paise' => 2_000_000,
        'right_bv_paise' => 1_500_000,
        'weaker_bv_paise' => 1_500_000,
        'slab' => 1,
        'gross_gsb_paise' => 180_000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_gsb_paise' => 180_000,
        'power_cf_before_paise' => 0,
        'power_cf_after_paise' => 500_000,
        'power_side_after' => 'L',
        'slab1_weaker_cf_before_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'status' => 'credited',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->get(route('income.genos-bv'))
        ->assertOk()
        ->assertSee('Highest slab earned: Slab 1')
        ->assertSee('Earned ×1');
});

it('shows the ladder gate note instead of group BV below 600 personal BV', function (): void {
    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    DB::table('group_bv_daily')->insert([
        'distributor_id' => $distributorId,
        'date' => Carbon::today('Asia/Kolkata')->toDateString(),
        'left_bv_paise' => 1_250_000, // 12,500 BV — must not be shown
        'right_bv_paise' => 300_000,
    ]);

    $this->get(route('income.genos-bv'))
        ->assertOk()
        ->assertSee('Genos BV is not being counted yet.')
        ->assertDontSee('12,500');
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
