<?php

declare(strict_types=1);

/**
 * The nav/route invariant (plan §6.4).
 *
 * > For each scoped admin role, every nav item the sidebar renders for that
 * > role must return 200 — never 403 — when that role requests it.
 *
 * Nav visibility and route authorisation drifted apart once already: the
 * *Content Pages* item rendered unconditionally while its route carried
 * `can:content.publish`, which R-17 withholds from `admin-finance`. This test
 * walks the real `AdminNavigation::groups($user)` output for each role and
 * opens every link, so the next such drift fails here, named by route and
 * role, instead of shipping as a 403 a human has to stumble into.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AnnouncementsFeature;
use App\Modules\Shared\Features\DistributorRequestsFeature;
use App\Modules\Shared\Features\MessagingFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Support\AdminNavigation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Every flag the sidebar consults, on — so the matrix covers the
 * feature-flagged items too and does not depend on flag defaults.
 */
function adminNavAccessActivateFlags(): void
{
    Feature::for(null)->activate(AnnouncementsFeature::class);
    Feature::for(null)->activate(MessagingFeature::class);
    Feature::for(null)->activate(DistributorRequestsFeature::class);
    Feature::for(null)->activate(PurchaseOffersFeature::class);
}

function adminNavAccessUser(string $role): User
{
    Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('shows a scoped admin role only links that role can open', function (string $role) {
    adminNavAccessActivateFlags();

    $user = adminNavAccessUser($role);

    $items = collect(AdminNavigation::groups($user))
        ->flatMap(fn (array $group): array => $group['items'])
        ->all();

    expect($items)->not->toBeEmpty();

    $offenders = [];

    foreach ($items as $item) {
        $url = route($item['route'], $item['params'] ?? []);

        $response = $this->actingAs($user)->get($url);
        $status = $response->getStatusCode();

        // 200 is the contract. The one tolerated exception is a redirect that
        // is *not* the login wall: `admin.tree.show` bounces back to the
        // dashboard on a database with no company-root distributor, which is
        // an empty-fixture artefact, not an authorisation refusal. A 403 —
        // the Content Pages class of bug — is never tolerated.
        $ok = $status === 200
            || ($status === 302 && ! str_contains((string) $response->headers->get('Location'), '/login'));

        if (! $ok) {
            $offenders[] = "{$role} is shown \"{$item['label']}\" ({$item['route']}) but GET {$url} returned {$status}";
        }
    }

    expect($offenders)->toBe([], implode("\n", $offenders));
})->with(['admin-operations', 'admin-finance', 'admin-compliance']);

it('hides the content.publish items from admin-finance', function () {
    adminNavAccessActivateFlags();

    $labels = collect(AdminNavigation::groups(adminNavAccessUser('admin-finance')))
        ->flatMap(fn (array $group): array => $group['items'])
        ->pluck('label')
        ->all();

    expect($labels)->not->toContain('Content Pages');
    expect($labels)->not->toContain('Announcements');

    $opsLabels = collect(AdminNavigation::groups(adminNavAccessUser('admin-operations')))
        ->flatMap(fn (array $group): array => $group['items'])
        ->pluck('label')
        ->all();

    expect($opsLabels)->toContain('Content Pages');
    expect($opsLabels)->toContain('Announcements');
});
