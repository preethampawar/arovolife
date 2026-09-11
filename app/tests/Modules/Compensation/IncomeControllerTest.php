<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\PayoutBatch;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Compensation\Services\CompensationPlanSettingsService;
use App\Modules\Compensation\Services\IncomeOverviewService;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
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
        ->assertSee('Income')
        // F64: the page title must follow the "My Income — …" pattern every
        // other income tab uses.
        ->assertSee('My Income — Overview', false)
        ->assertSee('Weekly income for each Wednesday-to-Tuesday earning week is paid on the following Tuesday')
        ->assertDontSee('cooling-off');
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
        ->assertSee('Wallet')
        // The payout week, stated as the week rule and never as "cooling-off"
        // (that is the statutory 30-day cancellation window, hard rule 5).
        ->assertSee('Weekly income for each Wednesday-to-Tuesday earning week is paid on the following Tuesday')
        ->assertSee('Covers earnings through')
        ->assertDontSee('cooling-off');
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

it('keyDates points the monthly payout at the 8th, not the 1st', function (): void {
    // Crediting closes on the 1st; payment waits a week. The 1st is no longer a
    // payout day at all, so on the 1st the next payout is still ahead.
    Carbon::setTestNow('2026-09-01 06:00:00');
    expect(IncomeOverviewService::keyDates()['nextMonthlyPayout']->toDateString())->toBe('2026-09-08');
    Carbon::setTestNow();

    Carbon::setTestNow('2026-09-07 23:00:00');
    expect(IncomeOverviewService::keyDates()['nextMonthlyPayout']->toDateString())->toBe('2026-09-08');
    Carbon::setTestNow();
});

it('keyDates rolls the monthly payout forward once the 04:00 batch has run, not at midnight', function (): void {
    // Before the batch on the 8th: today really is the next payout.
    Carbon::setTestNow('2026-09-08 02:30:00');
    expect(IncomeOverviewService::keyDates()['nextMonthlyPayout']->toDateString())->toBe('2026-09-08');
    Carbon::setTestNow();

    // Later the same day the transfer has already gone out — saying "today"
    // for the remaining 20 hours of the 8th would simply be wrong.
    Carbon::setTestNow('2026-09-08 06:00:00');
    expect(IncomeOverviewService::keyDates()['nextMonthlyPayout']->toDateString())->toBe('2026-10-08');
    Carbon::setTestNow();

    Carbon::setTestNow('2026-09-09 06:00:00');
    expect(IncomeOverviewService::keyDates()['nextMonthlyPayout']->toDateString())->toBe('2026-10-08');
    Carbon::setTestNow();

    // February: startOfMonth()->addDays(7) is the 8th in every month length.
    Carbon::setTestNow('2027-02-09 06:00:00');
    expect(IncomeOverviewService::keyDates()['nextMonthlyPayout']->toDateString())->toBe('2027-03-08');
    Carbon::setTestNow();
});

it('keyDates rolls the weekly payout forward once the 03:00 Tuesday batch has run, not at midnight', function (): void {
    // 2026-09-01 and 2026-09-08 are Tuesdays; 2026-09-03 is a Thursday.
    Carbon::setTestNow('2026-09-01 02:30:00');
    expect(IncomeOverviewService::keyDates()['nextWeeklyPayout']->toDateString())->toBe('2026-09-01');
    Carbon::setTestNow();

    // Tuesday afternoon: the batch went out at 03:00, so the next one is a week away.
    Carbon::setTestNow('2026-09-01 15:00:00');
    expect(IncomeOverviewService::keyDates()['nextWeeklyPayout']->toDateString())->toBe('2026-09-08');
    Carbon::setTestNow();

    // Mid-week still points at the coming Tuesday.
    Carbon::setTestNow('2026-09-03 09:00:00');
    expect(IncomeOverviewService::keyDates()['nextWeeklyPayout']->toDateString())->toBe('2026-09-08');
    Carbon::setTestNow();
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

it('streams the same friendly wallet ledger type labels in the CSV export (F64)', function (): void {
    ['user' => $user, 'distributorId' => $id] = incomeDistributor();

    DB::table('wallet_ledger_entries')->insert([
        ['distributor_id' => $id, 'type' => 'gsb_credit', 'amount_paise' => 200_000, 'created_at' => now()],
    ]);

    $csv = $this->actingAs($user)
        ->get(route('income.wallet.export'))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Genos Sales Bonus')
        ->and($csv)->not->toContain('gsb_credit');
});

it('labels a "no bank account on file" payout hold instead of the raw enum (F64)', function (): void {
    ['user' => $user, 'distributorId' => $id] = incomeDistributor();

    $batch = PayoutBatch::create([
        'batch_type' => PayoutBatch::TYPE_WEEKLY,
        'batch_date' => now()->toDateString(),
        'status' => PayoutBatch::STATUS_COMPLETED,
    ]);
    PayoutLineItem::create([
        'payout_batch_id' => $batch->id,
        'distributor_id' => $id,
        'wallet_balance_paise' => 50_000,
        'gross_paise' => 50_000,
        'repurchase_deduction_paise' => 0,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_transferred_paise' => 0,
        'status' => PayoutLineItem::STATUS_NO_BANK_ACCOUNT,
    ]);

    $this->actingAs($user)
        ->get(route('income.wallet'))
        ->assertOk()
        ->assertSee('No bank account on file')
        ->assertDontSee('No_bank_account');
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

it('shows the repurchase deduction and the credited amount on the gsb history page and csv — never admin charge or TDS', function (): void {
    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    DB::table('gsb_cutoff_results')->insert([
        'distributor_id' => $distributorId,
        'cutoff_date' => today()->toDateString(),
        'left_bv_paise' => 2_000_000,
        'right_bv_paise' => 1_600_000,
        'weaker_bv_paise' => 1_600_000,
        'slab' => 1,
        'score' => 8,
        'gross_gsb_paise' => 200_000,
        'repurchase_deduction_paise' => 20_000,
        'net_gsb_paise' => 180_000,
        'status' => 'credited',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->get(route('income.gsb-history'))
        ->assertOk()
        ->assertSee('Repurchase deduction')
        ->assertSee('Credited to wallet')
        ->assertSee('-₹200.00')
        ->assertSee('₹1,800.00')
        ->assertDontSee('TDS 5%')
        ->assertDontSee('Admin 3%');

    $csv = $this->get(route('income.gsb-history.export'))->assertOk()->streamedContent();
    expect($csv)->toContain('Repurchase Deduction (₹)')
        ->toContain('Credited to Wallet (₹)')
        ->toContain('2000.00,200.00,1800.00')
        ->not->toContain('TDS');
});

it('shows the repurchase alert traffic-light on the wallet page while a balance is outstanding', function (): void {
    ['user' => $user, 'distributorId' => $id] = incomeDistributor();

    DB::table('wallet_ledger_entries')->insert([
        ['distributor_id' => $id, 'type' => 'repurchase_deduction', 'amount_paise' => 50_000, 'created_at' => now()],
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-25 10:00:00'));

    try {
        $this->actingAs($user)
            ->get(route('income.wallet'))
            ->assertOk()
            ->assertSee('Clear now')
            ->assertSee('Bring this to ₹0 by');
    } finally {
        Carbon::setTestNow();
    }
});

it('shows a forfeited day on the gsb history page and csv, with no slab badge and no effect on the month total', function (): void {
    // Client spec 2026-09-07 §2.1: the distributor is entitled to see why a day
    // of Genos business paid nothing. The row carries no money, so the page's
    // month totals must be identical with and without it.
    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    DB::table('gsb_cutoff_results')->insert([
        [
            'distributor_id' => $distributorId,
            'cutoff_date' => today()->subDay()->toDateString(),
            'left_bv_paise' => 2_000_000,
            'right_bv_paise' => 1_600_000,
            'weaker_bv_paise' => 1_600_000,
            'slab' => 1,
            'score' => 8,
            'gross_gsb_paise' => 200_000,
            'repurchase_deduction_paise' => 20_000,
            'net_gsb_paise' => 180_000,
            'status' => 'credited',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
        [
            'distributor_id' => $distributorId,
            'cutoff_date' => today()->toDateString(),
            'left_bv_paise' => 900_000,
            'right_bv_paise' => 700_000,
            'weaker_bv_paise' => 0,
            'slab' => null,
            'score' => null,
            'gross_gsb_paise' => 0,
            'repurchase_deduction_paise' => 0,
            'net_gsb_paise' => 0,
            'status' => 'repurchase_forfeited',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
    ]);

    $html = $this->get(route('income.gsb-history'))
        ->assertOk()
        ->assertSee('Repurchase not met — day not counted', false)
        // The day's raw Genos BV is shown, but no slab badge is rendered for it.
        ->assertSee('9,000')
        // Only the credited row carries money, so the month total is unchanged.
        ->assertSee('₹2,000')
        ->getContent();

    // Exactly one slab badge on the page — the credited row's. The forfeited
    // row renders an em dash, never an empty "Slab " badge.
    expect(substr_count($html, 'bg-indigo-100 text-indigo-700">Slab '))->toBe(1);

    $csv = $this->get(route('income.gsb-history.export'))->assertOk()->streamedContent();
    expect($csv)->toContain('repurchase_forfeited')
        ->toContain('9000,7000,,0.00,0.00,0.00,repurchase_forfeited');
});

it('keeps every other cut-off status out of the distributor gsb history', function (): void {
    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    DB::table('gsb_cutoff_results')->insert(array_map(fn (array $row): array => [
        'distributor_id' => $distributorId,
        'weaker_bv_paise' => 0,
        'gross_gsb_paise' => 0,
        'repurchase_deduction_paise' => 0,
        'net_gsb_paise' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
        ...$row,
    ], [
        ['cutoff_date' => today()->subDays(1)->toDateString(), 'left_bv_paise' => 111_100, 'right_bv_paise' => 0, 'status' => 'no_match'],
        ['cutoff_date' => today()->subDays(2)->toDateString(), 'left_bv_paise' => 222_200, 'right_bv_paise' => 0, 'status' => 'below_600bv'],
        ['cutoff_date' => today()->subDays(3)->toDateString(), 'left_bv_paise' => 333_300, 'right_bv_paise' => 0, 'status' => 'frozen'],
        ['cutoff_date' => today()->subDays(4)->toDateString(), 'left_bv_paise' => 444_400, 'right_bv_paise' => 0, 'status' => 'repurchase_held'],
    ]));

    $this->get(route('income.gsb-history'))
        ->assertOk()
        ->assertSee('No GSB history yet.')
        ->assertDontSee('1,111')
        ->assertDontSee('2,222')
        ->assertDontSee('3,333')
        ->assertDontSee('4,444');
});

// ── Monthly bonus history: the wallet-blocked month is shown, never paid ──
// Client spec 2026-09-07 §2: the month-end repurchase-wallet condition is a
// published plan condition, so a distributor is entitled to see the month it
// cost them. The row carries no money (gross 0, net 0), so page totals are
// unchanged. Own data only, historical fact — never a projection (hard rule 3).

it('shows a wallet-blocked month on the growth booster page without paying it', function (): void {
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);

    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    DB::table('gbb_monthly_results')->insert([
        [
            'distributor_id' => $distributorId,
            'year_month' => '2026-07-01',
            'agp_earned' => 12,
            'company_turnover_paise' => 100_000_000,
            'pool_paise' => 5_000_000,
            'total_pool_agp' => 100,
            'point_value_paise' => 50_000,
            'gbb_gross_paise' => 600_000,
            'admin_charge_paise' => 0,
            'tds_paise' => 0,
            'repurchase_deduction_paise' => 60_000,
            'gbb_net_paise' => 540_000,
            'status' => 'credited',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
        [
            // Shaped the way the engine writes a blocked row: the AGP and the
            // month's point value are real, only the money is zero. A fixture
            // with a null point value would not exercise the suppression.
            'distributor_id' => $distributorId,
            'year_month' => '2026-08-01',
            'agp_earned' => 17,
            'company_turnover_paise' => 100_000_000,
            'pool_paise' => 5_000_000,
            'total_pool_agp' => 100,
            'point_value_paise' => 50_000,
            'gbb_gross_paise' => 0,
            'admin_charge_paise' => 0,
            'tds_paise' => 0,
            'repurchase_deduction_paise' => 0,
            'gbb_net_paise' => 0,
            'status' => 'repurchase_wallet_blocked',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
    ]);

    $this->get(route('income.growth-booster'))
        ->assertOk()
        ->assertSee('August 2026')
        ->assertSee('Repurchase wallet not cleared at month end — not paid', false)
        // Credited-only totals: the ₹5,400 credited July, nothing from August.
        ->assertSee('₹5,400')
        // The AGP is shown as the historical fact it is, but never multiplied
        // out into an income line for a month that will never be paid.
        ->assertSee('17 AGP')
        ->assertDontSee('17 AGP × ', false);
});

it('shows a wallet-blocked month on the fortune bonus page without paying it', function (): void {
    Feature::for(null)->activate(FortuneBonusFeature::class);

    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    DB::table('fortune_bonus_results')->insert([
        [
            'distributor_id' => $distributorId,
            'month_start' => '2026-07-01',
            'position' => 4,
            'matrix_level' => 1,
            'points' => 9,
            'point_value_paise' => 10_000,
            'gross_paise' => 90_000,
            'admin_charge_paise' => 0,
            'tds_paise' => 0,
            'repurchase_deduction_paise' => 9_000,
            'net_paise' => 81_000,
            'status' => 'credited',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
        [
            // Shaped the way the engine writes a blocked row: the matrix
            // position, points and the level's point value are all real, and
            // only the money is zero.
            'distributor_id' => $distributorId,
            'month_start' => '2026-08-01',
            'position' => 4,
            'matrix_level' => 1,
            'points' => 9,
            'point_value_paise' => 10_000,
            'gross_paise' => 0,
            'admin_charge_paise' => 0,
            'tds_paise' => 0,
            'repurchase_deduction_paise' => 0,
            'net_paise' => 0,
            'status' => 'repurchase_wallet_blocked',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ],
    ]);

    $html = $this->get(route('income.fortune-bonus'))
        ->assertOk()
        ->assertSee('August 2026')
        ->assertSee('Repurchase wallet not cleared at month end — not paid', false)
        // Credited-only total: only July's ₹810 reaches the summary card.
        ->assertSee('₹810')
        ->getContent();

    // Both months carry 9 points at ₹100, but only the credited one may show
    // the arithmetic: an income line on a blocked month states an amount as
    // though it were owed.
    expect(substr_count($html, '9 × ₹100.00'))->toBe(1);
});

it('counts a wallet-blocked month out of the fortune page total', function (): void {
    Feature::for(null)->activate(FortuneBonusFeature::class);

    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    // A blocked row that (defensively) still carries a gross must never be
    // added to the "credited to wallet" card.
    DB::table('fortune_bonus_results')->insert([
        'distributor_id' => $distributorId,
        'month_start' => '2026-08-01',
        'position' => 4,
        'matrix_level' => 1,
        'points' => 9,
        'point_value_paise' => 10_000,
        'gross_paise' => 90_000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'repurchase_deduction_paise' => 0,
        'net_paise' => 81_000,
        'status' => 'repurchase_wallet_blocked',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->get(route('income.fortune-bonus'))
        ->assertOk()
        ->assertSee('Repurchase wallet not cleared at month end — not paid', false)
        // Neither the net in the credited column nor the arithmetic behind it.
        ->assertDontSee('₹810')
        ->assertDontSee('9 × ₹100.00', false);
});

// ── Rank progress: the days this month that were not counted ──

it('tells the distributor how many days this month were not counted toward rank', function (): void {
    Carbon::setTestNow('2026-08-20 12:00:00');
    Feature::for(null)->activate(RankBonusFeature::class);
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    // Cycle due 10 Aug, met late on 14 Aug → 11, 12 and 13 August forfeited.
    DB::table('repurchase_cycles')->insert([
        'distributor_id' => $distributorId,
        'cycle_start_date' => '2026-07-24',
        'due_date' => '2026-08-10',
        'required_bv_paise' => 60_000,
        'completed_bv_paise' => 60_000,
        'status' => 'completed',
        'fulfilled_on' => '2026-08-14',
        'resolved_at' => '2026-08-11 00:05:00',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->get(route('income.rank-bonus'))
        ->assertOk()
        ->assertSee('Left Genos BV this month')
        ->assertSee('3 days this month not counted (repurchase condition not met)');
});

it('shows no not-counted note when the month has no forfeited days', function (): void {
    Carbon::setTestNow('2026-08-20 12:00:00');
    Feature::for(null)->activate(RankBonusFeature::class);
    Feature::for(null)->activate(RepurchaseEngineFeature::class);

    ['user' => $user] = incomeDistributor();

    $this->actingAs($user)
        ->get(route('income.rank-bonus'))
        ->assertOk()
        ->assertSee('Left Genos BV this month')
        ->assertDontSee('not counted (repurchase condition not met)');
});

it('shows the stored weaker side and a Left/Right power label on the genos bv page, never a recompute (F61/F62)', function (): void {
    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    // The day the QA run caught: the Left leg reads 0 BV for the day, yet the
    // cut-off stored Left as the power side, because Left carried 50,000 BV
    // in from the day before. Recomputing from the two leg figures calls Left
    // the weaker side; the stored result says the weaker side is Right.
    DB::table('gsb_cutoff_results')->insert([
        'distributor_id' => $distributorId,
        'cutoff_date' => '2026-09-06',
        'left_bv_paise' => 0,
        'right_bv_paise' => 200_000,
        'weaker_bv_paise' => 150_000,
        'slab' => 3,
        'power_cf_before_paise' => 5_000_000,
        'power_side_before' => 'L',
        'power_cf_after_paise' => 1_200_000,
        'power_side_after' => 'L',
        'slab1_weaker_cf_before_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'status' => 'credited',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->get(route('income.genos-bv'))->assertOk();

    // Weaker side cell: the side the engine stored — Right, the one that is
    // not the power side — plus the BV it was actually matched on, 1,500,
    // which is neither leg figure. A view that recomputed from the legs would
    // print Left here.
    expect($response->getContent())->toMatch(
        '/Right\s*<span class="block text-xs text-gray-500 font-mono">1,500 BV<\/span>/',
    );
    // "Power CF after" carries its Left/Right label (house rule).
    $response->assertSee('Left group');
});

it('gives the personal-BV top-up its own genos ledger line instead of "No Genos BV added this day" (F62)', function (): void {
    ['user' => $user, 'distributorId' => $distributorId] = incomeDistributor();
    $this->actingAs($user);

    disableTestForeignKeys();
    try {
        DB::table('bv_ledger_entries')->insert([
            'distributor_id' => $distributorId,
            'order_id' => 999_991,
            'bv_paise' => 60_000,
            'type' => 'accrual',
            'effective_at' => now()->format('Y-m-d H:i:s.v'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A day with no Genos purchase at all, but the cut-off matched a slab
        // because it topped the weaker group up with the distributor's own
        // purchase BV.
        DB::table('gsb_personal_bv_topups')->insert([
            'distributor_id' => $distributorId,
            'order_id' => 999_991,
            'bv_paise' => 60_000,
            'side' => 'R',
            'date' => today()->toDateString(),
            'created_at' => now(),
        ]);
    } finally {
        enableTestForeignKeys();
    }

    DB::table('gsb_cutoff_results')->insert([
        'distributor_id' => $distributorId,
        'cutoff_date' => today()->toDateString(),
        'left_bv_paise' => 0,
        'right_bv_paise' => 60_000,
        'weaker_bv_paise' => 60_000,
        'slab' => 1,
        'power_cf_before_paise' => 0,
        'power_side_before' => 'L',
        'power_cf_after_paise' => 0,
        'power_side_after' => 'L',
        'slab1_weaker_cf_before_paise' => 0,
        'slab1_weaker_cf_after_paise' => 0,
        'status' => 'credited',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->get(route('income.genos-ledger'))
        ->assertOk()
        ->assertSee('Your own purchase BV added to your weaker group')
        ->assertSee('applied at the cut-off to your Right group')
        ->assertSee('+600')
        ->assertSee('power (Left)')
        ->assertDontSee('No Genos BV added this day.');
});

it('dates every mentorship bonus row on the distributor page (F62)', function (): void {
    ['user' => $user, 'distributorId' => $sponsorId] = incomeDistributor();
    ['distributorId' => $sponseeId] = incomeDistributor();
    $this->actingAs($user);

    Feature::for(null)->activate(MentorshipBonusFeature::class);

    DB::table('mentorship_bonus_results')->insert([
        'sponsor_id' => $sponsorId,
        'sponsee_id' => $sponseeId,
        'cutoff_date' => '2026-09-06',
        'sponsee_gsb_paise' => 1_000_00,
        'slab' => 2,
        'msb_points' => 4,
        'msb_point_value_paise' => 25_000,
        'mb_gross_paise' => 100_000,
        'mb_net_paise' => 100_000,
        'status' => 'credited',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->get(route('income.mentorship'))
        ->assertOk()
        ->assertSee('06 Sep 2026');
});

it('dates the wallet ledger by when the money was earned, and names its bonus month and payout batch (F63)', function (): void {
    ['user' => $user, 'distributorId' => $id] = incomeDistributor();

    $batchId = DB::table('payout_batches')->insertGetId([
        'batch_type' => 'weekly',
        'batch_date' => '2026-09-08',
        'earnings_through' => '2026-09-07',
        'status' => 'completed',
        'total_gross_paise' => 25_600,
        'total_deductions_paise' => 0,
        'total_net_paise' => 25_600,
        'distributor_count' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('wallet_ledger_entries')->insert([
        // Earned at the 06 Sep 23:59 cut-off, written after midnight on 07 Sep:
        // GSB History said 06 Sep while the wallet said 07 Sep.
        [
            'distributor_id' => $id,
            'type' => 'gsb_credit',
            'amount_paise' => 25_600,
            'earned_on' => '2026-09-06',
            'bonus_month' => null,
            'memo' => null,
            'swept_by_payout_batch_id' => null,
            'created_at' => '2026-09-07 00:20:00',
        ],
        // A monthly bonus carries a bonus month instead of a single earn date,
        // and this one has already been swept to the bank.
        [
            'distributor_id' => $id,
            'type' => 'gbb_credit',
            'amount_paise' => 100_000,
            'earned_on' => null,
            'bonus_month' => '2026-08-01',
            'memo' => null,
            'swept_by_payout_batch_id' => $batchId,
            'created_at' => '2026-09-01 04:10:00',
        ],
    ]);

    $response = $this->actingAs($user)->get(route('income.wallet'))->assertOk();

    $response->assertSee('06 Sep 2026')          // earned date, not the write date
        ->assertSee('credited 07 Sep 2026')      // the write date, kept as secondary
        ->assertSee('Aug 2026')                  // bonus month column
        ->assertSee('Weekly · 08 Sep 2026');     // payout batch column

    // …and the export says exactly the same things.
    $csv = $this->actingAs($user)->get(route('income.wallet.export'))->assertOk()->streamedContent();

    expect($csv)->toContain('Bonus Month')
        ->and($csv)->toContain('Paid In Batch')
        ->and($csv)->toContain('2026-09-06,2026-09-07')
        ->and($csv)->toContain('Weekly · 08 Sep 2026');
});
