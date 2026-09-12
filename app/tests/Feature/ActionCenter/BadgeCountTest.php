<?php

declare(strict_types=1);

/**
 * Plan §7, §9 (A5) — the sidebar badge counts only the viewer's own critical
 * items, and zero renders no badge at all, not a grey zero (plan §10.5).
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

it('shows the badge with the viewer\'s own critical count', function (): void {
    // A paid order with no invoice is critical AND statutory — it is exactly
    // the kind of thing the badge exists to surface (plan §4).
    Helpers::paidOrder(now()->subDays(2));

    $operations = User::factory()->create(['status' => 'active']);
    $operations->assignRole('admin-operations');

    $response = $this->actingAs($operations)->get(route('admin.dashboard'));

    $response->assertOk();
    $response->assertSee('admin-nav-badge', false);
});

it('renders no badge for a viewer with nothing outstanding', function (): void {
    $finance = User::factory()->create(['status' => 'active']);
    $finance->assignRole('admin-finance');

    // admin-finance holds `finance.record`/`finance.approve` but nothing here
    // creates a money-side critical item, so the badge must not render.
    $response = $this->actingAs($finance)->get(route('admin.dashboard'));

    $response->assertOk();

    $html = $response->getContent();
    $navStart = strpos((string) $html, 'admin/action-center');

    expect($navStart)->not->toBeFalse();

    $navSnippet = substr((string) $html, (int) $navStart, 400);
    expect($navSnippet)->not->toContain('admin-nav-badge');
});

it('never counts another viewer\'s criticals in the badge', function (): void {
    Helpers::paidOrder(now()->subDays(2)); // orders.invoice_missing — commerce.order.manage

    $operations = User::factory()->create(['status' => 'active']);
    $operations->assignRole('admin-operations');

    $compliance = User::factory()->create(['status' => 'active']);
    $compliance->assignRole('admin-compliance');

    $opsBadge = $this->actingAs($operations)->get(route('admin.dashboard'));
    $opsBadge->assertOk()->assertSee('admin-nav-badge', false);

    $complianceHtml = (string) $this->actingAs($compliance)->get(route('admin.dashboard'))->getContent();
    $navStart = strpos($complianceHtml, 'admin/action-center');
    expect($navStart)->not->toBeFalse();
    expect(substr($complianceHtml, (int) $navStart, 400))->not->toContain('admin-nav-badge');
});
