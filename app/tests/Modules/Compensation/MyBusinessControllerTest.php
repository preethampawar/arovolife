<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TeamStatsService;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, distributorId: int}
 */
function myBusinessDistributor(): array
{
    $user = User::create([
        'full_name' => 'Business Test',
        'email' => 'business-'.uniqid().'@test.com',
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

/**
 * Place an existing distributor on one side of the root and register the
 * closure rows TeamStatsService counts.
 */
function myBusinessPlaceUnder(int $rootId, int $childId, string $side): void
{
    DB::table('distributors')->where('id', $childId)
        ->update(['placement_parent_id' => $rootId, 'placement_side' => $side]);
    DB::table('genealogy_closure')->insert([
        ['ancestor_id' => $childId, 'descendant_id' => $childId, 'depth' => 0],
        ['ancestor_id' => $rootId, 'descendant_id' => $childId, 'depth' => 1],
    ]);
}

function myBusinessGivePersonalBv(int $distributorId, int $bvPaise, int $orderId): void
{
    disableTestForeignKeys();
    try {
        DB::table('bv_ledger_entries')->insert([
            'distributor_id' => $distributorId,
            'order_id' => $orderId,
            'bv_paise' => $bvPaise,
            'type' => 'accrual',
            'effective_at' => now()->format('Y-m-d H:i:s.v'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } finally {
        enableTestForeignKeys();
    }
}

it('redirects unauthenticated visitors from my business', function (): void {
    $this->get(route('my-business'))->assertRedirect(route('login'));
});

it('returns 403 on my business for a user with no distributor record', function (): void {
    $user = User::create([
        'full_name' => 'No Dist',
        'email' => 'nodist-mb-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);
    $this->actingAs($user);

    $this->get(route('my-business'))->assertForbidden();
});

it('renders all four my business groups with the flag-gated menu tiles hidden', function (): void {
    ['user' => $user] = myBusinessDistributor();
    $this->actingAs($user);

    $this->get(route('my-business'))
        ->assertOk()
        ->assertSee('My Business')
        // Group 1 — menu tiles, unflagged pages only.
        ->assertSee('Dashboard &amp; Income', false)
        ->assertSee('Genos BV')
        ->assertSee('Genos Ledger')
        ->assertSee('GSB History')
        ->assertSee('Wallet &amp; Payouts', false)
        ->assertDontSee('Mentorship')
        ->assertDontSee('Fortune Bonus')
        // Lifetime Awards & Rewards has no page until Phase 5 — disabled tile, not a link.
        ->assertSee('Awards &amp; Rewards', false)
        ->assertSee('Coming soon')
        // Note block — the partner's canonical carry over / carry forward definitions.
        ->assertSee('Business that occurs before matching is called carry over.')
        ->assertSee('The remaining BVs after matching are called carry forward.')
        // Group 2
        ->assertSee('Personal BV (lifetime)')
        ->assertSee('No title yet')
        ->assertSee('Transferred after 3% admin charge + 5% TDS + repurchase deduction.')
        // Group 3 — Left before Right
        ->assertSee('Left carry forward')
        ->assertSee('Carried-over Left Genos BV')
        ->assertSee('Carried-over Right Genos BV')
        ->assertSee('Right carry forward')
        // Group 4
        ->assertSee('Left Genos total team')
        ->assertSee('Today Left Genos BV')
        ->assertSee('Today Right Genos BV')
        ->assertSee('Right Genos total team')
        // Blade directives must never leak as literal page text.
        ->assertDontSee('@if', false)
        ->assertDontSee('@endif', false);
});

it('shows flag-gated menu tiles when their features are on', function (): void {
    Feature::for(null)->activate(MentorshipBonusFeature::class);
    Feature::for(null)->activate(FortuneBonusFeature::class);

    ['user' => $user] = myBusinessDistributor();
    $this->actingAs($user);

    $this->get(route('my-business'))
        ->assertOk()
        ->assertSee('Mentorship')
        ->assertSee('Fortune Bonus');
});

it('shows the carry-forward decomposition, todays genos bv and team counts', function (): void {
    ['user' => $user, 'distributorId' => $rootId] = myBusinessDistributor();
    ['distributorId' => $leftChildId] = myBusinessDistributor();
    ['distributorId' => $rightChildId] = myBusinessDistributor();
    $this->actingAs($user);

    myBusinessPlaceUnder($rootId, $leftChildId, 'L');
    myBusinessPlaceUnder($rootId, $rightChildId, 'R');

    myBusinessGivePersonalBv($rootId, 60_000, 888_001); // 600 BV — eligible

    DB::table('gsb_carryforward')->insert([
        'distributor_id' => $rootId,
        'power_side_bv_paise' => 600_000, // 6,000 BV carried on the Left
        'power_side' => 'L',
        'slab1_weaker_bv_paise' => 60_000, // 600 BV in the side-less slab-1 bucket
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Both sides stay below the 15,000 BV first slab, so no personal-BV top-up
    // is previewed and every figure below is deterministic.
    DB::table('group_bv_daily')->insert([
        'distributor_id' => $rootId,
        'date' => Carbon::today('Asia/Kolkata')->toDateString(),
        'left_bv_paise' => 600_000, // 6,000 BV today
        'right_bv_paise' => 300_000, // 3,000 BV today
    ]);

    $this->get(route('my-business'))
        ->assertOk()
        // Left: 6,000 today + 6,000 carried over = 12,000 effective, Left is power.
        ->assertSee('12,000')
        ->assertSee('6,000 today + 6,000 carried over')
        ->assertSee('Power side')
        // Right is weaker, so it holds the side-less slab-1 accumulator.
        ->assertSee('3,000 today + 0 carried over')
        ->assertSee('Weaker side')
        ->assertSee('+ 600 BV slab-1 weaker carry over')
        // No slab has ever matched, so carry forward (post-match remainder) is 0.
        ->assertSee('No slab matched yet')
        ->assertSee('as of last page load')
        ->assertDontSee('@if', false)
        ->assertDontSee('@endif', false);

    // Team counts come from TeamStatsService — one placement on each side.
    $counts = app(TeamStatsService::class)
        ->counts(Distributor::findOrFail($rootId));

    expect($counts['left_team'])->toBe(1)
        ->and($counts['right_team'])->toBe(1);
});

it('shows carry forward as the remainder of the last slab match only', function (): void {
    ['user' => $user, 'distributorId' => $rootId] = myBusinessDistributor();
    $this->actingAs($user);

    myBusinessGivePersonalBv($rootId, 60_000, 888_002); // 600 BV — eligible

    // Yesterday slab 1 matched: the Right (weaker) side reset to 0 and 6,000 BV
    // remained on the Left power side — that remainder is the carry forward.
    GsbCutoffResult::create([
        'distributor_id' => $rootId,
        'cutoff_date' => Carbon::yesterday('Asia/Kolkata')->toDateString(),
        'left_bv_paise' => 2_100_000,
        'right_bv_paise' => 1_500_000,
        'weaker_bv_paise' => 1_500_000,
        'slab' => 1,
        'score' => 8,
        'score_value_paise' => 25_000,
        'gross_gsb_paise' => 200_000,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_gsb_paise' => 200_000,
        'power_cf_after_paise' => 600_000,
        'power_side_after' => 'L',
        'status' => GsbCutoffResult::STATUS_CREDITED,
    ]);

    $expectedDate = Carbon::yesterday('Asia/Kolkata')->format('d M Y');

    $this->get(route('my-business'))
        ->assertOk()
        // Left kept the 6,000 BV remainder; Right was reset by the match.
        ->assertSee('Remaining after your last slab match ('.$expectedDate.')', false)
        ->assertSee('Reset at your last slab match ('.$expectedDate.')', false)
        ->assertSee('6,000')
        ->assertDontSee('No slab matched yet');
});

it('shows zero genos figures on my business below the personal bv minimum', function (): void {
    ['user' => $user, 'distributorId' => $rootId] = myBusinessDistributor();
    $this->actingAs($user);

    // Downline BV and carry-forward exist, but no personal BV was purchased.
    DB::table('gsb_carryforward')->insert([
        'distributor_id' => $rootId,
        'power_side_bv_paise' => 600_000,
        'power_side' => 'L',
        'slab1_weaker_bv_paise' => 60_000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('group_bv_daily')->insert([
        'distributor_id' => $rootId,
        'date' => Carbon::today('Asia/Kolkata')->toDateString(),
        'left_bv_paise' => 600_000,
        'right_bv_paise' => 300_000,
    ]);

    $this->get(route('my-business'))
        ->assertOk()
        ->assertSee('requires 600 BV of personal purchases')
        // The seeded carry-forward and today's BV are both withheld…
        ->assertDontSee('6,000')
        ->assertDontSee('3,000 today')
        // …and every Genos card reads 0 instead.
        ->assertSee('0 today + 0 carried over')
        ->assertDontSee('as of last page load');
});

it('shows a Tuesday as the next payout date on my business', function (): void {
    ['user' => $user] = myBusinessDistributor();
    $this->actingAs($user);

    $today = now()->timezone('Asia/Kolkata');
    $daysUntilTuesday = (2 - $today->dayOfWeek + 7) % 7;
    $expected = $daysUntilTuesday === 0 ? $today->copy() : $today->copy()->addDays($daysUntilTuesday);

    expect($expected->dayOfWeek)->toBe(Carbon::TUESDAY);

    $this->get(route('my-business'))
        ->assertOk()
        ->assertSee('Next payout — Tuesday, '.$expected->format('d M Y'), false);
});
