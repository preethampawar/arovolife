<?php

declare(strict_types=1);

/**
 * AdminNavigation — the sidebar list, now readable outside Blade.
 *
 * This is the safety net for the grouping slice that follows: it pins the
 * exact item set, its order and its labels *before* anything reorders them,
 * so a conditional that silently disappears in the move fails here rather
 * than in production.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AnnouncementsFeature;
use App\Modules\Shared\Features\DistributorRequestsFeature;
use App\Modules\Shared\Features\MessagingFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Support\AdminNavigation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** A developer sees everything: `Gate::before` bypasses every permission. */
function adminNavDeveloper(): User
{
    Role::firstOrCreate(['name' => 'developer', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole('developer');

    return $user;
}

/** Every flag the sidebar consults, on, so the list is deterministic. */
function adminNavActivateFlags(): void
{
    Feature::for(null)->activate(AnnouncementsFeature::class);
    Feature::for(null)->activate(MessagingFeature::class);
    Feature::for(null)->activate(DistributorRequestsFeature::class);
    Feature::for(null)->activate(PurchaseOffersFeature::class);
}

/**
 * @param  array<string, int>  $badges
 * @return list<array<string, mixed>>
 */
function adminNavItems(?User $user, array $badges = []): array
{
    return collect(AdminNavigation::groups($user, $badges))
        ->flatMap(fn (array $group): array => $group['items'])
        ->all();
}

it('returns the nine groups in the documented IA order', function () {
    adminNavActivateFlags();

    $groups = AdminNavigation::groups(adminNavDeveloper(), ['engine-failures' => 1]);

    expect(array_column($groups, 'key'))->toBe([
        'overview',
        'network',
        'commerce',
        'inventory',
        'compensation',
        'catalog',
        'support',
        'insights',
        'system',
    ]);

    expect(array_column($groups, 'label'))->toBe([
        null,
        'Network',
        'Commerce',
        'Inventory',
        'Compensation',
        'Catalog & Content',
        'Support & Compliance',
        'Insights',
        'System',
    ]);

    expect(array_column($groups, 'icon'))->toBe([
        'layout-dashboard',
        'users',
        'shopping-cart',
        'boxes',
        'banknote',
        'package',
        'life-buoy',
        'chart-line',
        'settings',
    ]);
});

it('renders Overview flat, with no label', function () {
    $groups = AdminNavigation::groups(adminNavDeveloper());

    expect($groups[0]['key'])->toBe('overview');
    expect($groups[0]['label'])->toBeNull();
});

it('keys every group with a stable lowercase slug', function () {
    adminNavActivateFlags();

    foreach (AdminNavigation::groups(adminNavDeveloper(), ['engine-failures' => 1]) as $group) {
        expect($group['key'])->toMatch('/^[a-z][a-z0-9-]*$/');
    }
});

it('never returns an empty group', function () {
    adminNavActivateFlags();

    foreach ([adminNavDeveloper(), null] as $viewer) {
        foreach (AdminNavigation::groups($viewer) as $group) {
            expect($group['items'])->not->toBeEmpty("group {$group['key']} rendered empty");
        }
    }
});

it('places every item in exactly one group', function () {
    adminNavActivateFlags();

    $seen = [];
    foreach (AdminNavigation::groups(adminNavDeveloper(), ['engine-failures' => 1]) as $group) {
        foreach ($group['items'] as $item) {
            $seen[$item['label']][] = $group['key'];
        }
    }

    foreach ($seen as $label => $groupKeys) {
        expect($groupKeys)->toHaveCount(1, "{$label} appears in ".implode(', ', $groupKeys));
    }

    // Every item the flat S1 list carried is still here, none dropped.
    expect(count($seen))->toBe(count(adminNavItems(adminNavDeveloper(), ['engine-failures' => 1])));
});

it('groups the items as the IA table says', function () {
    adminNavActivateFlags();

    $map = [];
    foreach (AdminNavigation::groups(adminNavDeveloper(), ['engine-failures' => 1]) as $group) {
        $map[$group['key']] = array_column($group['items'], 'label');
    }

    expect($map)->toBe([
        'overview' => ['Dashboard', 'Action Center'],
        'network' => ['Distributors', 'Genealogy tree', 'KYC review', 'Line changes', 'Distributor requests', 'Dormancy (§21)', 'Arete Centres'],
        'commerce' => ['Orders', 'Payments', 'Coupons', 'Offers', 'BV Ledger'],
        'inventory' => ['Stock', 'Reports', 'Warehouses', 'Suppliers', 'Purchase Orders', 'Goods Receipts (GRN)', 'Transfers', 'Adjustments'],
        'compensation' => ['Compensation', 'Engine failures'],
        'catalog' => ['Products', 'Categories', 'Banners', 'Content Pages', 'Announcements'],
        'support' => ['Contact Inbox', 'Grievances', 'Reported messages', 'Compliance Docs'],
        'insights' => ['Analytics', 'Audit Log'],
        'system' => ['Staff users', 'Settings', 'Feature flags', 'Help & Reference'],
    ]);
});

it('shows a scoped role fewer groups, and no empty one', function () {
    adminNavActivateFlags();

    $compliance = User::factory()->create();
    $compliance->assignRole('admin-compliance');

    $developerGroups = AdminNavigation::groups(adminNavDeveloper());
    $complianceGroups = AdminNavigation::groups($compliance);

    expect(count($complianceGroups))->toBeLessThan(count($developerGroups));

    foreach ($complianceGroups as $group) {
        expect($group['items'])->not->toBeEmpty("group {$group['key']} rendered empty for admin-compliance");
    }

    // admin-compliance holds neither inventory.view nor inventory.manage, so
    // the whole Inventory section disappears rather than leaving a bare header.
    expect(array_column($complianceGroups, 'key'))->not->toContain('inventory');
    expect(array_column($complianceGroups, 'key'))->toContain('support');
});

it('gives every item a non-empty route, label and icon', function () {
    adminNavActivateFlags();

    foreach (adminNavItems(adminNavDeveloper(), ['engine-failures' => 3]) as $item) {
        expect($item['route'] ?? '')->toBeString()->not->toBe('');
        expect($item['label'] ?? '')->toBeString()->not->toBe('');
        expect($item['icon'] ?? '')->toBeString()->not->toBe('');
    }
});

it('points every item at a route that exists and resolves', function () {
    adminNavActivateFlags();

    foreach (adminNavItems(adminNavDeveloper(), ['engine-failures' => 3]) as $item) {
        expect(Route::has($item['route']))->toBeTrue("route {$item['route']} is not registered");
        expect(route($item['route'], $item['params'] ?? []))->toBeString();
    }
});

it('never lists the same route twice', function () {
    adminNavActivateFlags();

    $routes = array_column(adminNavItems(adminNavDeveloper(), ['engine-failures' => 3]), 'route');

    expect($routes)->toHaveCount(count(array_unique($routes)));
});

it('renders exactly this ordered list for a developer', function () {
    adminNavActivateFlags();

    $labels = array_column(adminNavItems(adminNavDeveloper()), 'label');

    expect($labels)->toBe([
        'Dashboard',
        'Action Center',
        'Distributors',
        'Genealogy tree',
        'KYC review',
        'Line changes',
        'Distributor requests',
        'Dormancy (§21)',
        'Arete Centres',
        'Orders',
        'Payments',
        'Coupons',
        'Offers',
        'BV Ledger',
        'Stock',
        'Reports',
        'Warehouses',
        'Suppliers',
        'Purchase Orders',
        'Goods Receipts (GRN)',
        'Transfers',
        'Adjustments',
        'Compensation',
        'Products',
        'Categories',
        'Banners',
        'Content Pages',
        'Announcements',
        'Contact Inbox',
        'Grievances',
        'Reported messages',
        'Compliance Docs',
        'Analytics',
        'Audit Log',
        'Staff users',
        'Settings',
        'Feature flags',
        'Help & Reference',
    ]);
});

it('shows Engine failures only while a run is broken', function () {
    adminNavActivateFlags();
    $developer = adminNavDeveloper();

    expect(array_column(adminNavItems($developer), 'label'))->not->toContain('Engine failures');

    $withFailures = adminNavItems($developer, ['engine-failures' => 2]);
    expect(array_column($withFailures, 'label'))->toContain('Engine failures');

    $item = collect($withFailures)->firstWhere('label', 'Engine failures');
    expect($item['badge'])->toBe(2);
    expect($item['params'])->toBe(['status' => 'failed']);
});

it('surfaces each passed badge value on its own item', function () {
    adminNavActivateFlags();

    $items = collect(adminNavItems(adminNavDeveloper(), [
        'action-center' => 11,
        'contact' => 12,
        'grievances' => 13,
        'message-reports' => 14,
        'distributor-requests' => 15,
        'inventory-reports' => 16,
        'payments' => 17,
        'adc-applications' => 18,
    ]))->keyBy('label');

    expect($items['Action Center']['badge'])->toBe(11);
    expect($items['Contact Inbox']['badge'])->toBe(12);
    expect($items['Grievances']['badge'])->toBe(13);
    expect($items['Reported messages']['badge'])->toBe(14);
    expect($items['Distributor requests']['badge'])->toBe(15);
    expect($items['Reports']['badge'])->toBe(16);
    expect($items['Payments']['badge'])->toBe(17);
    expect($items['Arete Centres']['badge'])->toBe(18);
});

it('computes no badge of its own when none is passed', function () {
    adminNavActivateFlags();

    foreach (adminNavItems(adminNavDeveloper()) as $item) {
        expect($item['badge'] ?? 0)->toBe(0);
    }
});

it('survives a null user and shows only the ungated items', function () {
    $labels = array_column(adminNavItems(null), 'label');

    expect($labels)->toContain('Dashboard');
    expect($labels)->not->toContain('Staff users');
    expect($labels)->not->toContain('KYC review');
    expect($labels)->not->toContain('Action Center');
    expect($labels)->not->toContain('Audit Log');
});
