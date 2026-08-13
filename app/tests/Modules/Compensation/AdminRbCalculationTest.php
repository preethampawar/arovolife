<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function rbCalcReportAdmin(): User
{
    $user = User::create([
        'full_name' => 'Rb Report Admin',
        'email' => 'rb-report-admin-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

it('hides the RB calculation report while the feature is off', function (): void {
    Feature::for(null)->deactivate(RankBonusFeature::class);

    $admin = rbCalcReportAdmin();

    $this->actingAs($admin)
        ->get(route('admin.compensation.rb-calculation.index'))
        ->assertNotFound();

    $this->actingAs($admin)
        ->get(route('admin.compensation.rb-calculation.export'))
        ->assertNotFound();
});

it('shows the RB calculation report while the feature is on', function (): void {
    Feature::for(null)->activate(RankBonusFeature::class);

    $this->actingAs(rbCalcReportAdmin())
        ->get(route('admin.compensation.rb-calculation.index'))
        ->assertOk();
});

it('hides the Arete Center column on page and CSV while the ADC flag is off', function (): void {
    Feature::for(null)->activate(RankBonusFeature::class);

    // ADC flag defaults to off — no Arete Center column anywhere.
    $this->actingAs(rbCalcReportAdmin())
        ->get(route('admin.compensation.rb-calculation.index'))
        ->assertOk()
        ->assertDontSee('Arete Center');
    $this->actingAs(rbCalcReportAdmin())
        ->get(route('admin.compensation.rb-calculation.export'))
        ->assertOk()
        ->assertDontSee('Arete Center');

    // ADC flag on — the column returns (asserted on the CSV, whose header
    // renders even with zero result rows; the HTML tables don't).
    Feature::for(null)->activate(AreteDevelopmentCenterBonusFeature::class);

    $this->actingAs(rbCalcReportAdmin())
        ->get(route('admin.compensation.rb-calculation.export'))
        ->assertOk()
        ->assertSee('Arete Center');
});
