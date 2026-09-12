<?php

declare(strict_types=1);

/**
 * Plan §7, §9 (A5) — the two read screens. Every admin-family role can open
 * them (gated by `action.center.view`); what each one SEES on the index and
 * type page is scoped per provider permission, never per role (plan §10.2).
 */

use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('returns 200 on the index page for every admin-family role', function (string $role): void {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    $this->actingAs($user)->get(route('admin.action-center.index'))->assertOk();
})->with(['admin-operations', 'admin-finance', 'admin-compliance']);

it('returns 200 on a type page for a role holding that provider\'s permission', function (): void {
    Helpers::paidOrder(now()->subDays(3));

    $operations = User::factory()->create(['status' => 'active']);
    $operations->assignRole('admin-operations');

    $this->actingAs($operations)->get(route('admin.action-center.show', 'orders.paid_not_packed'))->assertOk();
});

it('never shows a role rows for a provider whose permission it does not hold', function (): void {
    $order = Helpers::paidOrder(now()->subDays(3));

    $operations = User::factory()->create(['status' => 'active']);
    $operations->assignRole('admin-operations');

    $finance = User::factory()->create(['status' => 'active']);
    $finance->assignRole('admin-finance');

    // `orders.paid_not_packed` sits behind `commerce.order.manage`, which
    // admin-finance does not hold.
    $opsIndex = $this->actingAs($operations)->get(route('admin.action-center.index'));
    $opsIndex->assertOk()->assertSee('Paid orders not packed');

    $financeIndex = $this->actingAs($finance)->get(route('admin.action-center.index'));
    $financeIndex->assertOk()->assertDontSee('Paid orders not packed');

    // The type page itself refuses the role that cannot act on it — the same
    // "unknown key" response an unpermitted viewer gets everywhere else.
    $this->actingAs($finance)->get(route('admin.action-center.show', 'orders.paid_not_packed'))->assertNotFound();

    $this->assertNotNull($order);
});

it('blocks a viewer with no action.center.view permission at all', function (): void {
    $user = User::factory()->create(['status' => 'active']);

    $this->actingAs($user)->get(route('admin.action-center.index'))->assertForbidden();
});
