<?php

declare(strict_types=1);

use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::for(null)->activate(MentorshipBonusFeature::class);
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function msbReportAdmin(): User
{
    $user = User::create([
        'full_name' => 'Msb Report Admin',
        'email' => 'msb-report-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

function msbReportDistributor(string $adn, string $name): int
{
    $user = User::create([
        'full_name' => $name,
        'email' => 'msb-rep-'.uniqid().'@test.com',
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

function makeMbRow(int $sponsorId, int $sponseeId, ?int $slab, ?int $points, ?int $valuePaise, int $grossPaise, string $date): void
{
    MentorshipBonusResult::create([
        'sponsor_id' => $sponsorId,
        'sponsee_id' => $sponseeId,
        'cutoff_date' => $date,
        'sponsee_gsb_paise' => 200_000,
        'slab' => $slab,
        'msb_points' => $points,
        'msb_point_value_paise' => $valuePaise,
        'mb_gross_paise' => $grossPaise,
        'mb_admin_charge_paise' => 0,
        'mb_tds_paise' => 0,
        'status' => MentorshipBonusResult::STATUS_CREDITED,
    ]);
}

it('shows points, value, income and a grand total over the full filtered set', function () {
    $sponsorA = msbReportDistributor('MSBAAA', 'Alice');
    $sponseeA = msbReportDistributor('MSBSPA', 'Anu');
    $sponsorB = msbReportDistributor('MSBBBB', 'Bob');
    $sponseeB = msbReportDistributor('MSBSPB', 'Balu');
    makeMbRow($sponsorA, $sponseeA, 1, 21, 25_000, 525_000, today()->toDateString());
    makeMbRow($sponsorB, $sponseeB, 2, 18, 25_000, 450_000, today()->toDateString());

    $this->actingAs(msbReportAdmin())
        ->get(route('admin.compensation.msb-calculation.index'))
        ->assertOk()
        ->assertSee('MSBAAA')
        ->assertSee('MSBSPA')
        ->assertSee('MSBBBB')
        ->assertSee('Grand total (all filtered rows)')
        ->assertSee('39')                 // total points 21 + 18
        ->assertSee('9,750.00');          // total income ₹5,250 + ₹4,500
});

it('filters by sponsor or sponsee ADN search', function () {
    $sponsorA = msbReportDistributor('MSBAAA', 'Alice');
    $sponseeA = msbReportDistributor('MSBSPA', 'Anu');
    $sponsorB = msbReportDistributor('MSBBBB', 'Bob');
    $sponseeB = msbReportDistributor('MSBSPB', 'Balu');
    makeMbRow($sponsorA, $sponseeA, 1, 21, 25_000, 525_000, today()->toDateString());
    makeMbRow($sponsorB, $sponseeB, 2, 18, 25_000, 450_000, today()->toDateString());

    // Sponsor match.
    $this->actingAs(msbReportAdmin())
        ->get(route('admin.compensation.msb-calculation.index', ['q' => 'MSBAAA']))
        ->assertOk()
        ->assertSee('MSBAAA')
        ->assertDontSee('MSBBBB');

    // Sponsee match surfaces its sponsor's row.
    $this->actingAs(msbReportAdmin())
        ->get(route('admin.compensation.msb-calculation.index', ['q' => 'MSBSPB']))
        ->assertOk()
        ->assertSee('MSBBBB')
        ->assertDontSee('MSBAAA');
});

it('filters by slab number', function () {
    $sponsorA = msbReportDistributor('MSBAAA', 'Alice');
    $sponseeA = msbReportDistributor('MSBSPA', 'Anu');
    $sponsorB = msbReportDistributor('MSBBBB', 'Bob');
    $sponseeB = msbReportDistributor('MSBSPB', 'Balu');
    makeMbRow($sponsorA, $sponseeA, 1, 21, 25_000, 525_000, today()->toDateString());
    makeMbRow($sponsorB, $sponseeB, 2, 18, 25_000, 450_000, today()->toDateString());

    $this->actingAs(msbReportAdmin())
        ->get(route('admin.compensation.msb-calculation.index', ['slab' => 2]))
        ->assertOk()
        ->assertSee('MSBBBB')
        ->assertDontSee('MSBAAA');
});

it('filters by date range', function () {
    $sponsor = msbReportDistributor('MSBAAA', 'Alice');
    $sponsee = msbReportDistributor('MSBSPA', 'Anu');
    makeMbRow($sponsor, $sponsee, 1, 21, 25_000, 525_000, today()->subDays(10)->toDateString());
    makeMbRow($sponsor, $sponsee, 2, 18, 25_000, 450_000, today()->toDateString());

    $this->actingAs(msbReportAdmin())
        ->get(route('admin.compensation.msb-calculation.index', [
            'from' => today()->subDays(2)->toDateString(),
            'to' => today()->toDateString(),
        ]))
        ->assertOk()
        // Only today's slab-2 row is in range: grand total points = 18.
        ->assertSee('18');
});

it('renders legacy ladder rows without points and keeps their income in the total', function () {
    $sponsor = msbReportDistributor('MSBAAA', 'Alice');
    $sponsee = msbReportDistributor('MSBSPA', 'Anu');
    // Legacy ladder row: no slab/points/value snapshots, income ₹100.
    makeMbRow($sponsor, $sponsee, null, null, null, 10_000, today()->toDateString());
    // Points-engine row: 21 points, ₹5,250.
    makeMbRow($sponsor, msbReportDistributor('MSBSPB', 'Balu'), 1, 21, 25_000, 525_000, today()->toDateString());

    $this->actingAs(msbReportAdmin())
        ->get(route('admin.compensation.msb-calculation.index'))
        ->assertOk()
        ->assertSee('—')
        ->assertSee('21')                 // points total excludes the legacy null
        ->assertSee('5,350.00');          // income total ₹5,250 + ₹100
});

it('exports a CSV with a grand-total row', function () {
    $sponsor = msbReportDistributor('MSBAAA', 'Alice');
    $sponsee = msbReportDistributor('MSBSPA', 'Anu');
    makeMbRow($sponsor, $sponsee, 1, 21, 25_000, 525_000, today()->toDateString());

    $res = $this->actingAs(msbReportAdmin())
        ->get(route('admin.compensation.msb-calculation.export'));

    $res->assertOk();
    $csv = $res->getContent();
    expect($csv)->toContain('SNo,Sponsor ADN,Sponsor Name,Title,Date,Sponsee ADN,Sponsee Name,MSB Points,Value (Rs),Income (Rs),Status');
    expect($csv)->toContain('"TOTAL"');
    expect($csv)->toContain('5250.00');   // income total
});

it('hides the MSB reports while the feature is off', function (): void {
    Feature::for(null)->deactivate(MentorshipBonusFeature::class);

    $admin = msbReportAdmin();

    $this->actingAs($admin)->get(route('admin.compensation.msb-calculation.index'))->assertNotFound();
    $this->actingAs($admin)->get(route('admin.compensation.msb-input-output.index'))->assertNotFound();
});
