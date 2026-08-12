<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
    // GBB / Rank / Fortune / ADC / Lifetime Awards all default to off; their
    // calculation reports are always visible, the engine run pages are not.
    $this->actingAs(compNavAdmin())
        ->get(route('admin.compensation.gsb-calculation.index'))
        ->assertOk()
        ->assertSee(route('admin.compensation.gbb-calculation.index'), false)
        // Not the URL: /compensation/gbb is a substring of /compensation/gbb-calculation.
        ->assertDontSee('Growth Booster Bonus')
        ->assertDontSee('Lifetime Awards');
});
