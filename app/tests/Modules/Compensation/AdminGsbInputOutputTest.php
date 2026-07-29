<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\GsbDailyPool;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function ioAdmin(): User
{
    $user = User::create([
        'full_name' => 'IO Report Admin',
        'email' => 'io-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

function ioPool(string $date, int $valuePaise = 22_000): GsbDailyPool
{
    return GsbDailyPool::create([
        'cutoff_date' => $date,
        'company_bv_paise' => 100_000_000,
        'pool_rate_bp' => 4500,
        'pool_paise' => 45_000_000,
        'fixed_payout_paise' => 600_000,
        'variable_total_score' => 32,
        'variable_score_value_cap_paise' => 25_000,
        'variable_score_value_paise' => $valuePaise,
        'variable_payout_paise' => 32 * $valuePaise,
        'leftover_paise' => 45_000_000 - 600_000 - 32 * $valuePaise,
    ]);
}

function ioCutoff(int $distributorId, int $slab, int $score, int $valuePaise, string $date): void
{
    GsbCutoffResult::create([
        'distributor_id' => $distributorId,
        'cutoff_date' => $date,
        'left_bv_paise' => 2_000_000,
        'right_bv_paise' => 1_600_000,
        'weaker_bv_paise' => 1_600_000,
        'slab' => $slab,
        'score' => $score,
        'score_value_paise' => $valuePaise,
        'gross_gsb_paise' => $score * $valuePaise,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_gsb_paise' => $score * $valuePaise,
        'status' => GsbCutoffResult::STATUS_CREDITED,
    ]);
}

it('shows a day block with fixed and variable sections, totals and leftover', function () {
    $date = today()->toDateString();
    ioPool($date);
    ioCutoff(1, 1, 8, 25_000, $date);   // fixed ₹2,000
    ioCutoff(2, 1, 8, 25_000, $date);   // fixed ₹2,000
    ioCutoff(3, 3, 32, 22_000, $date);  // variable ₹7,040 at ₹220/score

    $this->actingAs(ioAdmin())
        ->get(route('admin.compensation.gsb-input-output.index'))
        ->assertOk()
        ->assertSee('Day total BV')
        ->assertSee('Fixed')
        ->assertSee('Variable')
        ->assertSee('7,040.00')              // slab-3 income at the pro-rated value
        ->assertSee('4,000.00')              // fixed section total (2 × ₹2,000)
        ->assertSee('Grand total income')
        ->assertSee('leftover');
});

it('filters by day and week number anchored to the first pooled day', function () {
    $day1 = today()->subDays(9)->toDateString();  // anchor → day 1, week 1
    $day8 = today()->subDays(2)->toDateString();  // day 8 → week 2
    ioPool($day1, 21_000);
    ioPool($day8, 23_000);

    // Day 1 only.
    $this->actingAs(ioAdmin())
        ->get(route('admin.compensation.gsb-input-output.index', ['day' => 1]))
        ->assertOk()
        ->assertSee('₹210.00')
        ->assertDontSee('₹230.00');

    // Week 2 (days 8–14) only.
    $this->actingAs(ioAdmin())
        ->get(route('admin.compensation.gsb-input-output.index', ['week' => 2]))
        ->assertOk()
        ->assertSee('₹230.00')
        ->assertDontSee('₹210.00');
});

it('exports the per-day CSV with sections, day totals and the leftover', function () {
    $date = today()->toDateString();
    ioPool($date);
    ioCutoff(1, 1, 8, 25_000, $date);
    ioCutoff(2, 3, 32, 22_000, $date);

    $res = $this->actingAs(ioAdmin())
        ->get(route('admin.compensation.gsb-input-output.export'));

    $res->assertOk();
    $csv = $res->getContent();
    expect($csv)->toContain('Day,Week,Date,Day Total BV (Rs),GSB Pool (Rs),Slab,Section,Achievers,Total Score,Score Value (Rs),Income (Rs),Variance (Rs)');
    expect($csv)->toContain('"Fixed"');
    expect($csv)->toContain('"Variable"');
    expect($csv)->toContain('220.00');       // pro-rated score value
    expect($csv)->toContain('-30.00');       // variance vs the ₹250 cap
    expect($csv)->toContain('"DAY TOTAL"');
    expect($csv)->toContain('leftover');
});

it('shows the empty state before any pooled day exists', function () {
    $this->actingAs(ioAdmin())
        ->get(route('admin.compensation.gsb-input-output.index'))
        ->assertOk()
        ->assertSee('No pooled cut-off days yet');
});
