<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\LifetimeAwardsFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function compNavAdmin(): User
{
    $user = User::create([
        'full_name' => 'Comp Nav Admin',
        'email' => 'comp-nav-'.uniqid().'@test.com',
        'phone_e164' => '+91'.str_pad((string) random_int(7000000000, 9999999999), 10, '0'),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

it('renders the compensation sub-nav inside a report page so there is a way back', function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    Feature::for(null)->activate(MentorshipBonusFeature::class);

    $this->actingAs(compNavAdmin())
        ->get(route('admin.compensation.gsb-input-output.index'))
        ->assertOk()
        ->assertSee('Compensation sections', false)
        ->assertSee('Daily engine')
        ->assertSee('Monthly bonuses')
        ->assertSee('Plan &amp; controls', false)
        // The way back to the Overview, plus a sibling report.
        ->assertSee(route('admin.compensation.overview'), false)
        ->assertSee(route('admin.compensation.msb-input-output.index'), false)
        // Plan & controls links, including the Engine Runs page.
        ->assertSee(route('admin.compensation.engine-runs.index'), false);
});

it('renders the sub-nav on the compensation overview too', function (): void {
    $this->actingAs(compNavAdmin())
        ->get(route('admin.compensation.overview'))
        ->assertOk()
        ->assertSee('Compensation sections', false)
        ->assertSee(route('admin.compensation.weekly-payouts.index'), false);
});

it('does not render the compensation sub-nav on unrelated admin pages', function (): void {
    $this->actingAs(compNavAdmin())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertDontSee('Compensation sections', false);
});

it('hides feature-gated bonus engines while their flag is off', function (): void {
    // Every bonus flag defaults to off: the GSB/MSB daily-engine links, the
    // monthly engine run pages AND their calculation reports disappear — a
    // disabled feature leaves no trace. Only the Overview stays reachable.
    $this->actingAs(compNavAdmin())
        ->get(route('admin.compensation.overview'))
        ->assertOk()
        ->assertDontSee(route('admin.compensation.gsb-calculation.index'), false)
        ->assertDontSee(route('admin.compensation.daily-cutoffs.index'), false)
        ->assertDontSee(route('admin.compensation.msb-calculation.index'), false)
        ->assertDontSee(route('admin.compensation.manual-controls.index'), false)
        ->assertDontSee(route('admin.compensation.gbb-calculation.index'), false)
        ->assertDontSee(route('admin.compensation.rb-calculation.index'), false)
        ->assertDontSee(route('admin.compensation.fb-calculation.index'), false)
        ->assertDontSee(route('admin.compensation.adc-calculation.index'), false)
        ->assertDontSee(route('admin.compensation.aw-rw-calculation.index'), false)
        ->assertDontSee('Growth Booster Bonus')
        ->assertDontSee('Fortune Bonus')
        ->assertDontSee('Lifetime Awards')
        ->assertDontSee('Awards &amp; Rewards', false);
});

it('404s the Awards & Rewards report while the lifetime-awards flag is off', function (): void {
    $this->actingAs(compNavAdmin())
        ->get(route('admin.compensation.aw-rw-calculation.index'))
        ->assertNotFound();

    $this->actingAs(compNavAdmin())
        ->get(route('admin.compensation.aw-rw-calculation.export'))
        ->assertNotFound();
});

it('shows the gated daily-engine links and calculation reports when their flags are on', function (): void {
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    Feature::for(null)->activate(MentorshipBonusFeature::class);
    Feature::for(null)->activate(GrowthBoosterBonusFeature::class);
    Feature::for(null)->activate(RankBonusFeature::class);
    Feature::for(null)->activate(FortuneBonusFeature::class);
    Feature::for(null)->activate(AreteDevelopmentCenterBonusFeature::class);
    Feature::for(null)->activate(LifetimeAwardsFeature::class);

    $this->actingAs(compNavAdmin())
        ->get(route('admin.compensation.gsb-calculation.index'))
        ->assertOk()
        ->assertSee(route('admin.compensation.daily-cutoffs.index'), false)
        ->assertSee(route('admin.compensation.msb-calculation.index'), false)
        ->assertSee(route('admin.compensation.manual-controls.index'), false)
        ->assertSee(route('admin.compensation.gbb-calculation.index'), false)
        ->assertSee(route('admin.compensation.rb-calculation.index'), false)
        ->assertSee(route('admin.compensation.fb-calculation.index'), false)
        ->assertSee(route('admin.compensation.adc-calculation.index'), false)
        ->assertSee(route('admin.compensation.aw-rw-calculation.index'), false);
});
