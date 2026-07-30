<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Compensation\Models\MsbDailyPool;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function msbIoAdmin(string $role = 'admin'): User
{
    $user = User::create([
        'full_name' => 'MSB IO Admin',
        'email' => 'msb-io-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

/** KP's sheet: a 1,00,000 BV day whose ₹3,000 pool spreads over $totalPoints. */
function msbIoPool(string $date, int $totalPoints, int $valuePaise): MsbDailyPool
{
    return MsbDailyPool::create([
        'cutoff_date' => $date,
        'company_bv_paise' => 10_000_000,
        'pool_rate_bp' => 300,
        'pool_paise' => 300_000,
        'total_points' => $totalPoints,
        'point_value_paise' => $valuePaise,
        'payout_paise' => $totalPoints * $valuePaise,
        'leftover_paise' => 300_000 - $totalPoints * $valuePaise,
    ]);
}

/** One sponsor's credit for the day: $points at $valuePaise per point. */
function msbIoCredit(string $adn, string $name, int $points, int $valuePaise, string $date): Distributor
{
    $sponsor = Distributor::factory()->create(['adn' => $adn]);
    User::whereKey($sponsor->user_id)->update(['full_name' => $name]);

    MentorshipBonusResult::create([
        'sponsor_id' => $sponsor->id,
        'sponsee_id' => Distributor::factory()->create()->id,
        'cutoff_date' => $date,
        'sponsee_gsb_paise' => 200_000,
        'slab' => 1,
        'msb_points' => $points,
        'msb_point_value_paise' => $valuePaise,
        'mb_gross_paise' => $points * $valuePaise,
        'mb_admin_charge_paise' => 0,
        'mb_tds_paise' => 0,
        'status' => MentorshipBonusResult::STATUS_CREDITED,
    ]);

    return $sponsor;
}

it("renders KP's five-earner day: 75 points at ₹40 totalling ₹3,000", function () {
    $date = today()->toDateString();
    msbIoPool($date, 75, 4_000);

    foreach ([['A', 21], ['B', 18], ['C', 15], ['D', 12], ['E', 9]] as $i => [$name, $points]) {
        msbIoCredit('20000000'.$i, "Sponsor {$name}", $points, 4_000, $date);
    }

    $res = $this->actingAs(msbIoAdmin())
        ->get(route('admin.compensation.msb-input-output.index'))
        ->assertOk();

    // Day header: total received BV and the 3% pool.
    $res->assertSee(\App\Modules\Commerce\Support\Bv::format(10_000_000));
    $res->assertSee('3,000.00');

    // Every earner's points and the one point value.
    $res->assertSee('21 pts');
    $res->assertSee('9 pts');
    $res->assertSee('₹40.00');

    // Individual incomes and the day totals.
    $res->assertSee('840.00');   // 21 × 40
    $res->assertSee('360.00');   // 9 × 40
    $res->assertSee('Total MSB score points');
    $res->assertSee('75');
});

it('sums a sponsor credited by several sponsees into one line', function () {
    $date = today()->toDateString();
    msbIoPool($date, 39, 4_000);

    $sponsor = msbIoCredit('200000010', 'Busy Sponsor', 21, 4_000, $date);
    MentorshipBonusResult::create([
        'sponsor_id' => $sponsor->id,
        'sponsee_id' => Distributor::factory()->create()->id,
        'cutoff_date' => $date,
        'sponsee_gsb_paise' => 200_000,
        'slab' => 2,
        'msb_points' => 18,
        'msb_point_value_paise' => 4_000,
        'mb_gross_paise' => 72_000,
        'mb_admin_charge_paise' => 0,
        'mb_tds_paise' => 0,
        'status' => MentorshipBonusResult::STATUS_CREDITED,
    ]);

    $res = $this->actingAs(msbIoAdmin())
        ->get(route('admin.compensation.msb-input-output.index'))
        ->assertOk();

    $res->assertSee('39 pts');          // 21 + 18 on one line
    $res->assertSee('1,560.00');        // 39 × ₹40
});

it('shows a day whose pool went unspent, with a note explaining the ₹0 value', function () {
    msbIoPool(today()->toDateString(), 0, 0);

    $this->actingAs(msbIoAdmin())
        ->get(route('admin.compensation.msb-input-output.index'))
        ->assertOk()
        ->assertSee('No MSB score points were accrued on this day')
        ->assertSee('No Mentorship Bonus earners this day.');
});

it('filters by day number, week number and date range', function () {
    $today = today();
    msbIoPool($today->copy()->subDays(9)->toDateString(), 60, 5_000);   // day 1, week 1
    msbIoPool($today->toDateString(), 75, 4_000);                       // day 10, week 2

    $admin = msbIoAdmin();

    // Day 1 is the earliest pooled day.
    $this->actingAs($admin)
        ->get(route('admin.compensation.msb-input-output.index', ['day' => 1]))
        ->assertOk()
        ->assertSee($today->copy()->subDays(9)->format('d/m/Y'))
        ->assertDontSee($today->format('d/m/Y'));

    // Week 2 covers days 8–14.
    $this->actingAs($admin)
        ->get(route('admin.compensation.msb-input-output.index', ['week' => 2]))
        ->assertOk()
        ->assertSee($today->format('d/m/Y'))
        ->assertDontSee($today->copy()->subDays(9)->format('d/m/Y'));

    // Explicit date range.
    $this->actingAs($admin)
        ->get(route('admin.compensation.msb-input-output.index', ['from' => $today->toDateString()]))
        ->assertOk()
        ->assertSee($today->format('d/m/Y'))
        ->assertDontSee($today->copy()->subDays(9)->format('d/m/Y'));
});

it('exports a CSV with per-earner rows and a day total', function () {
    $date = today()->toDateString();
    msbIoPool($date, 60, 5_000);
    msbIoCredit('200000020', 'Csv Sponsor', 21, 5_000, $date);

    $res = $this->actingAs(msbIoAdmin())
        ->get(route('admin.compensation.msb-input-output.export'))
        ->assertOk();

    $csv = $res->getContent();

    expect($csv)->toContain('Day Total Received BV');
    expect($csv)->toContain('"200000020"');
    expect($csv)->toContain('1050.00');       // 21 × ₹50
    expect($csv)->toContain('"DAY TOTAL"');
});

it('is reachable by the business admin roles but not by an unprivileged user', function () {
    msbIoPool(today()->toDateString(), 60, 5_000);

    $this->actingAs(msbIoAdmin('admin-finance'))
        ->get(route('admin.compensation.msb-input-output.index'))
        ->assertOk();

    // A signed-in user holding no console role must not reach the report.
    $plain = User::create([
        'full_name' => 'No Role',
        'email' => 'no-role-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($plain)
        ->get(route('admin.compensation.msb-input-output.index'))
        ->assertForbidden();
});
