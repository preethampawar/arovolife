<?php

declare(strict_types=1);

use App\Modules\Compensation\Events\CompensationPlanChanged;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function planAdmin(string $role): User
{
    $user = User::create([
        'full_name' => 'Plan '.$role,
        'email' => 'plan-'.uniqid().'@test.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

it('renders the plan-settings page for an admin with the four editors', function () {
    $this->actingAs(planAdmin('admin'))
        ->get(route('admin.compensation.plan-settings.index'))
        ->assertOk()
        ->assertSee('GSB slabs')
        ->assertSee('Rank tiers')
        ->assertSee('Fortune Bonus — matrix levels')
        ->assertSee('Fortune Bonus — eligibility tiers');
});

it('persists a GSB slab edit, writes an audit log, and dispatches the domain event', function () {
    Event::fake([CompensationPlanChanged::class]);

    $this->actingAs(planAdmin('admin-finance'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.compensation.plan-settings.gsb-slab.update', 2), [
            'title' => 'Dealer',
            'title_min_bv_paise' => 500_000,
            'matched_bv_paise' => 3_600_000,
            'score' => 12,                 // 12 × ₹360 = ₹4,320
            'agp_per_occurrence' => 5,
            'carry_forward_lifetime' => 0,
            'is_active' => 1,
        ])
        ->assertRedirect(route('admin.compensation.plan-settings.index'));

    // Bonus recomputed from score × rate (₹360 → 36,000 paise).
    $row = DB::table('gsb_slabs')->where('slab', 2)->first();
    expect((int) $row->score)->toBe(12);
    expect((int) $row->bonus_paise)->toBe(12 * 36_000);

    expect(AuditLog::where('action', 'compensation.plan.gsb_slab.updated')->exists())->toBeTrue();
    Event::assertDispatched(CompensationPlanChanged::class, fn ($e) => $e->area === 'gsb_slab' && $e->key === '2');
});

it('forbids a non-finance admin from editing the plan', function () {
    $this->actingAs(planAdmin('admin-compliance'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.compensation.plan-settings.fortune-level.update', 0), [
            'bonus_paise' => 999,
            'is_active' => 1,
        ])
        ->assertForbidden();
});

it('updates a comp.* scalar via the generic settings endpoint', function () {
    $this->actingAs(planAdmin('admin'))
        ->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('admin.settings.update', 'comp.tds.rate_bp'), ['value' => '600'])
        ->assertRedirect();

    expect(DB::table('settings')->where('key', 'comp.tds.rate_bp')->value('value'))->toBe('600');
    expect(AuditLog::where('action', 'admin.settings.changed')->exists())->toBeTrue();
});
