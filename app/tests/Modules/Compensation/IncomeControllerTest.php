<?php

declare(strict_types=1);

use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // GSB is the core engine these pages describe; its surfaces are
    // flag-gated, so the suite runs with the flag on (dedicated flag-off
    // tests deactivate it explicitly).
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
});

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
        ->assertSee('Income');
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

it('shows carry-forward folded into the dashboard group BV cards as the opening balance', function (): void {
    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    disableTestForeignKeys();
    try {
        DB::table('bv_ledger_entries')->insert([
            'distributor_id' => $distributorId,
            'order_id' => 999_996,
            'bv_paise' => 60_000, // 600 BV — eligible for group BV counting
            'type' => 'accrual',
            'effective_at' => now()->format('Y-m-d H:i:s.v'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } finally {
        enableTestForeignKeys();
    }

    // Yesterday's unmatched Left BV sits in power-side carry-forward, and the
    // weaker side's BV sits in the side-less slab-1 weaker bucket…
    DB::table('gsb_carryforward')->insert([
        'distributor_id' => $distributorId,
        'power_side_bv_paise' => 600_000, // 6,000 BV
        'power_side' => 'L',
        'slab1_weaker_bv_paise' => 60_000, // 600 BV
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // …and today's fresh Left BV lands on top of it.
    DB::table('group_bv_daily')->insert([
        'distributor_id' => $distributorId,
        'date' => Carbon::today('Asia/Kolkata')->toDateString(),
        'left_bv_paise' => 1_500_000, // 15,000 BV
        'right_bv_paise' => 0,
    ]);

    $this->get(route('income.dashboard'))
        ->assertOk()
        // Headline = the figure tonight's cut-off will use: 15,000 + 6,000.
        ->assertSee('21,000')
        ->assertSee('15,000 today + 6,000 carried over')
        ->assertSee('Power-side carry over (opening balance)')
        // Left holds the power carry-forward, so Right is the weaker side.
        ->assertSee('Power side')
        ->assertSee('Weaker side')
        // The side-less slab-1 bucket is surfaced under the weaker side…
        ->assertSee('+ 600 BV in slab-1 weaker carry over (see card below)')
        // …and its own card names the side it is currently accumulating from.
        ->assertSee('Currently accumulating from your Right (weaker) side')
        // Guard against uncompiled Blade leaking to the page: a directive whose
        // @ is glued to a preceding word character is rendered as literal text.
        ->assertDontSee('@if', false)
        ->assertDontSee('@endif', false);
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
    DB::table('group_bv_credits')->insert([
        'order_id' => 2001,
        'ancestor_id' => $rootId,
        'side' => 'L',
        'bv_paise' => 50_000,
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

it('shows per-side slab progress and the slab-1 weaker carry-forward on the genos bv page', function (): void {
    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    disableTestForeignKeys();
    try {
        DB::table('bv_ledger_entries')->insert([
            'distributor_id' => $distributorId,
            'order_id' => 999_995,
            'bv_paise' => 60_000, // 600 BV — eligible for group BV counting
            'type' => 'accrual',
            'effective_at' => now()->format('Y-m-d H:i:s.v'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } finally {
        enableTestForeignKeys();
    }

    // Side-less slab-1 accumulator; no power-side carry-forward.
    DB::table('gsb_carryforward')->insert([
        'distributor_id' => $distributorId,
        'power_side_bv_paise' => 0,
        'power_side' => 'L',
        'slab1_weaker_bv_paise' => 60_000, // 600 BV
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Both sides below the 15,000 BV first slab, so no personal-BV top-up is
    // previewed and the per-side figures stay deterministic.
    DB::table('group_bv_daily')->insert([
        'distributor_id' => $distributorId,
        'date' => Carbon::today('Asia/Kolkata')->toDateString(),
        'left_bv_paise' => 1_000_000, // 10,000 BV — power side
        'right_bv_paise' => 300_000,  // 3,000 BV — weaker side
    ]);

    $this->get(route('income.genos-bv'))
        ->assertOk()
        ->assertSee('Power side')
        ->assertSee('Weaker side')
        ->assertSee('600 BV in slab-1 weaker carry over')
        // Slab 1 row: the weaker (Right) side carries the 600 BV accumulator,
        // the Left side does not — min(10,000, 3,600) is the matched figure.
        ->assertSee('L 10,000 / 15,000')
        ->assertSee('R 3,600 / 15,000')
        ->assertDontSee('@if', false)
        ->assertDontSee('@endif', false);
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

/**
 * Dashboard clarity additions (goal 2026-08-06): the income-calendar strip
 * (tonight's cut-off + payout days — schedule facts only, no amounts) and the
 * per-bonus "credited to wallet" summary sourced from the wallet ledger.
 */
it('shows the key-dates strip and per-bonus wallet summary on the dashboard', function (): void {
    ['user' => $user, 'distributorId' => $id] = incomeDistributor();
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);
    Feature::for(null)->activate(MentorshipBonusFeature::class);

    DB::table('wallet_ledger_entries')->insert([
        ['distributor_id' => $id, 'type' => 'gsb_credit', 'amount_paise' => 200_000, 'created_at' => now()],
        ['distributor_id' => $id, 'type' => 'gbb_credit', 'amount_paise' => 294_500, 'created_at' => now()],
        // A lifetime-only credit from a previous month must appear in the
        // lifetime figure but not in this month's.
        ['distributor_id' => $id, 'type' => 'gsb_credit', 'amount_paise' => 100_000, 'created_at' => now()->subMonths(2)],
        // Debits must never inflate the credited-to-wallet figures.
        ['distributor_id' => $id, 'type' => 'payout_debit', 'amount_paise' => -50_000, 'created_at' => now()],
    ]);

    $this->actingAs($user)
        ->get(route('income.dashboard'))
        ->assertOk()
        ->assertSee("Tonight's cut-off", false)
        ->assertSee('Next weekly payout')
        ->assertSee('Next monthly payout')
        ->assertSee('My bonuses — credited to wallet')
        ->assertSee('Genos Sales Bonus')
        ->assertSee('Growth Booster Bonus')
        // GSB: 2,000 this month, 3,000 lifetime (Indian grouping, whole ₹).
        ->assertSee('₹2,000')
        ->assertSee('₹3,000')
        // GBB: same figure this month and lifetime.
        ->assertSee('₹2,945');
});

it('hides the monthly payout card and flag-gated bonuses when no monthly bonus is active', function (): void {
    ['user' => $user] = incomeDistributor();

    $this->actingAs($user)
        ->get(route('income.dashboard'))
        ->assertOk()
        ->assertSee('Next weekly payout')
        ->assertDontSee('Next monthly payout')
        ->assertDontSee('Growth Booster')
        ->assertDontSee('Mentorship');
});

it('shows friendly wallet ledger type labels, never raw machine types', function (): void {
    ['user' => $user, 'distributorId' => $id] = incomeDistributor();

    DB::table('wallet_ledger_entries')->insert([
        ['distributor_id' => $id, 'type' => 'gsb_credit', 'amount_paise' => 200_000, 'created_at' => now()],
        ['distributor_id' => $id, 'type' => 'repurchase_deduction', 'amount_paise' => -20_000, 'created_at' => now()],
    ]);

    $this->actingAs($user)
        ->get(route('income.wallet'))
        ->assertOk()
        ->assertSee('Genos Sales Bonus')
        ->assertSee('Repurchase deduction')
        ->assertDontSee('gsb_credit')
        ->assertDontSee('repurchase_deduction');
});

it('hides every GSB surface for distributors while the feature is off', function (): void {
    Feature::for(null)->deactivate(GenosSalesBonusFeature::class);

    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    // The GSB History tab, the cut-off card, the slab/carry-forward panels and
    // the Genos Sales Bonus summary row all disappear from the dashboard.
    $this->get(route('income.dashboard'))
        ->assertOk()
        ->assertDontSee('GSB History')
        ->assertDontSee("Tonight's cut-off", false)
        ->assertDontSee('Genos Sales Bonus')
        ->assertDontSee('Power-side carry over');

    // The GSB history page and its CSV export 404.
    $this->get(route('income.gsb-history'))->assertNotFound();
    $this->get(route('income.gsb-history.export'))->assertNotFound();
});

it('shows the distributor their own rank status and the next rank conditions', function (): void {
    Feature::for(null)->activate(RankBonusFeature::class);

    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    $monthStart = Carbon::today('Asia/Kolkata')->startOfMonth();

    disableTestForeignKeys();
    try {
        DB::table('rank_qualifications')->insert([
            'distributor_id' => $distributorId,
            'rank_number' => 1,
            'month_start' => $monthStart->toDateString(),
            'occurrence_in_month' => 1,
            'is_carry_forward' => false,
            'status' => 'qualified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } finally {
        enableTestForeignKeys();
    }

    $rankOne = app(CompensationPlanSettingsService::class)->rankName(1);
    $rankTwo = app(CompensationPlanSettingsService::class)->rankName(2);

    $this->get(route('income.rank-bonus'))
        ->assertOk()
        ->assertSee('My rank status')
        ->assertSee('Current rank')
        ->assertSee('Highest rank')
        ->assertSee($rankOne)
        ->assertSee('Conditions for '.$rankTwo)
        ->assertSee('Left Genos BV this month');
});

it('shows the AO-GO offer and its conditions once a rank has been achieved, and not before', function (): void {
    Feature::for(null)->activate(RankBonusFeature::class);

    // Never ranked → the offer cannot apply, so the panel stays off the page.
    ['user' => $fresh] = incomeDistributor();
    $this->actingAs($fresh)
        ->get(route('income.rank-bonus'))
        ->assertOk()
        ->assertDontSee('AO-GO offer');

    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();

    disableTestForeignKeys();
    try {
        DB::table('rank_qualifications')->insert([
            'distributor_id' => $distributorId,
            'rank_number' => 1,
            'month_start' => Carbon::today('Asia/Kolkata')->startOfMonth()->subMonths(2)->toDateString(),
            'occurrence_in_month' => 1,
            'is_carry_forward' => false,
            'status' => 'qualified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } finally {
        enableTestForeignKeys();
    }

    $this->actingAs($user)
        ->get(route('income.rank-bonus'))
        ->assertOk()
        ->assertSee('AO-GO offer')
        ->assertSee('Achieve Once – Get Once', false)
        ->assertSee('Used 0 of 3')
        ->assertSee("This month's conditions", false)
        ->assertSee('A rank achieved in an earlier month')
        ->assertSee('No rank held this month')
        ->assertSee('Lifetime uses remaining');
});

it('keeps the rank status panel out of every surface while the Rank Bonus flag is off', function (): void {
    Feature::for(null)->deactivate(RankBonusFeature::class);

    ['user' => $user] = incomeDistributor();
    $this->actingAs($user);

    $this->get(route('income.rank-bonus'))->assertNotFound();
    $this->get(route('income.dashboard'))
        ->assertOk()
        ->assertDontSee('Rank Bonus');
});
