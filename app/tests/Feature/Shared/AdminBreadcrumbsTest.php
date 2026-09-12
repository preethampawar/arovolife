<?php

declare(strict_types=1);

/**
 * Breadcrumbs (S4).
 *
 * The trail is derived from the route name against the same map that renders
 * the sidebar — no view declares its own ancestry. These tests pin both halves:
 * the pure resolution, and the fact that the layout actually renders it.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\AdminNavigation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function breadcrumbDeveloper(): User
{
    Role::firstOrCreate(['name' => 'developer', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('developer');

    return $user;
}

it('resolves nothing for the dashboard, which is the trail root itself', function (): void {
    expect(AdminNavigation::resolve('admin.dashboard', breadcrumbDeveloper()))->toBeNull();
});

it('resolves nothing for a route outside the console', function (): void {
    expect(AdminNavigation::resolve('distributor.dashboard', breadcrumbDeveloper()))->toBeNull();
    expect(AdminNavigation::resolve(null, breadcrumbDeveloper()))->toBeNull();
});

it('resolves a section index to its group and item', function (): void {
    $trail = AdminNavigation::resolve('admin.inventory.warehouses.index', breadcrumbDeveloper());

    expect($trail)->not->toBeNull()
        ->and($trail['group']['label'])->toBe('Inventory')
        ->and($trail['item']['label'])->toBe('Warehouses')
        ->and($trail['child'])->toBeNull();
});

it('resolves a detail route to the item that owns it', function (): void {
    $trail = AdminNavigation::resolve('admin.distributors.show', breadcrumbDeveloper());

    expect($trail['group']['label'])->toBe('Network')
        ->and($trail['item']['label'])->toBe('Distributors')
        ->and($trail['child'])->toBeNull();
});

it('resolves a declared child to a three-level trail', function (): void {
    $trail = AdminNavigation::resolve('admin.arete-centres.applications.index', breadcrumbDeveloper());

    expect($trail['group']['label'])->toBe('Network')
        ->and($trail['item']['label'])->toBe('Arete Centres')
        ->and($trail['child']['label'])->toBe('Applications');
});

it('falls back to the Compensation item for an unmapped compensation report', function (): void {
    $trail = AdminNavigation::resolve('admin.compensation.reports.group-sales-bonus', breadcrumbDeveloper());

    expect($trail['group']['label'])->toBe('Compensation')
        ->and($trail['item']['label'])->toBe('Compensation')
        ->and($trail['child'])->toBeNull();
});

it('prefers the longest matching prefix when a child sits under its parent', function (): void {
    $trail = AdminNavigation::resolve('admin.compensation.weekly-payouts.show', breadcrumbDeveloper());

    expect($trail['item']['label'])->toBe('Compensation')
        ->and($trail['child']['label'])->toBe('Weekly Payouts');
});

it('renders no breadcrumb bar on the dashboard', function (): void {
    $this->actingAs(breadcrumbDeveloper())
        ->get('/admin')
        ->assertOk()
        ->assertDontSee('aria-label="Breadcrumb"', false);
});

it('renders the trail on a section index, rooted at a link to the dashboard', function (): void {
    $response = $this->actingAs(breadcrumbDeveloper())
        ->get('/admin/inventory/warehouses')
        ->assertOk()
        ->assertSee('aria-label="Breadcrumb"', false)
        ->assertSee('aria-current="page"', false)
        ->assertSeeInOrder(['Admin', 'Inventory', 'Warehouses'], false);

    expect($response->getContent())->toContain('href="'.route('admin.dashboard').'"');
});

it('uses the view heading as the leaf crumb', function (): void {
    $this->actingAs(breadcrumbDeveloper())
        ->get('/admin/compensation')
        ->assertOk()
        ->assertSeeInOrder(['aria-label="Breadcrumb"', 'Compensation', 'aria-current="page"', 'Compensation Overview'], false);
});

it('renders a deep admin page without erroring', function (): void {
    $this->actingAs(breadcrumbDeveloper())->get('/admin/distributors')->assertOk();
    $this->actingAs(breadcrumbDeveloper())->get('/admin/compensation')->assertOk();
    $this->actingAs(breadcrumbDeveloper())->get('/admin/inventory/suppliers')->assertOk();
});
