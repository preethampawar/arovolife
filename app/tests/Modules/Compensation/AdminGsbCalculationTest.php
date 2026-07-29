<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function reportAdmin(): User
{
    $user = User::create([
        'full_name' => 'Report Admin',
        'email' => 'report-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

function reportDistributor(string $adn, string $name): int
{
    $user = User::create([
        'full_name' => $name,
        'email' => 'rep-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
    ]);

    $id = DB::table('distributors')->insertGetId([
        'user_id' => $user->id,
        'adn' => $adn,
        'pan_hash' => random_bytes(32),
        'pan_last4' => '1234',
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

    return $id;
}

function makeCutoff(int $distributorId, int $slab, ?int $score, int $grossPaise, string $date): void
{
    GsbCutoffResult::create([
        'distributor_id' => $distributorId,
        'cutoff_date' => $date,
        'left_bv_paise' => 2_000_000,
        'right_bv_paise' => 1_600_000,
        'weaker_bv_paise' => 1_600_000,
        'slab' => $slab,
        'score' => $score,
        'gross_gsb_paise' => $grossPaise,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'net_gsb_paise' => $grossPaise,
        'status' => GsbCutoffResult::STATUS_CREDITED,
    ]);
}

it('shows score, income and a grand total over the full filtered set', function () {
    $a = reportDistributor('ADNAAA', 'Alice');
    $b = reportDistributor('ADNBBB', 'Bob');
    makeCutoff($a, 1, 8, 200_000, today()->toDateString());
    makeCutoff($b, 2, 16, 400_000, today()->toDateString());

    $this->actingAs(reportAdmin())
        ->get(route('admin.compensation.gsb-calculation.index'))
        ->assertOk()
        ->assertSee('ADNAAA')
        ->assertSee('ADNBBB')
        ->assertSee('Grand total (all filtered rows)')
        ->assertSee('24')                 // total score 8 + 16
        ->assertSee('6,000.00');          // total income ₹2,000 + ₹4,000
});

it('filters by ADN search', function () {
    $a = reportDistributor('ADNAAA', 'Alice');
    $b = reportDistributor('ADNBBB', 'Bob');
    makeCutoff($a, 1, 8, 200_000, today()->toDateString());
    makeCutoff($b, 2, 16, 400_000, today()->toDateString());

    $this->actingAs(reportAdmin())
        ->get(route('admin.compensation.gsb-calculation.index', ['q' => 'AAA']))
        ->assertOk()
        ->assertSee('ADNAAA')
        ->assertDontSee('ADNBBB');
});

it('filters by slab number', function () {
    $a = reportDistributor('ADNAAA', 'Alice');
    $b = reportDistributor('ADNBBB', 'Bob');
    makeCutoff($a, 1, 8, 200_000, today()->toDateString());
    makeCutoff($b, 2, 16, 400_000, today()->toDateString());

    $this->actingAs(reportAdmin())
        ->get(route('admin.compensation.gsb-calculation.index', ['slab' => 2]))
        ->assertOk()
        ->assertSee('ADNBBB')
        ->assertDontSee('ADNAAA');
});

it('filters by date range', function () {
    $a = reportDistributor('ADNAAA', 'Alice');
    makeCutoff($a, 1, 8, 200_000, today()->subDays(10)->toDateString());
    makeCutoff($a, 2, 16, 400_000, today()->toDateString());

    $this->actingAs(reportAdmin())
        ->get(route('admin.compensation.gsb-calculation.index', [
            'from' => today()->subDays(2)->toDateString(),
            'to' => today()->toDateString(),
        ]))
        ->assertOk()
        // Only today's slab-2 row is in range: grand total score = 16.
        ->assertSee('16');
});

it('falls back to the slab score when the row snapshot is null', function () {
    $a = reportDistributor('ADNAAA', 'Alice');
    // Legacy row: slab set but score snapshot missing → report reads gsb_slabs.score (32).
    makeCutoff($a, 3, null, 800_000, today()->toDateString());

    $this->actingAs(reportAdmin())
        ->get(route('admin.compensation.gsb-calculation.index'))
        ->assertOk()
        ->assertSee('32');
});

it('exports a CSV with a grand-total row', function () {
    $a = reportDistributor('ADNAAA', 'Alice');
    makeCutoff($a, 1, 8, 200_000, today()->toDateString());

    $res = $this->actingAs(reportAdmin())
        ->get(route('admin.compensation.gsb-calculation.export'));

    $res->assertOk();
    $csv = $res->getContent();
    expect($csv)->toContain('SNo,ADN,Name,Title,Date,Slab,Score,Score Value (Rs),Income (Rs),Status');
    expect($csv)->toContain('"TOTAL"');
    expect($csv)->toContain('2000.00');   // income total
});
