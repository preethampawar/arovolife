<?php

declare(strict_types=1);

/**
 * Plan §7, §9 (A5) — the sidebar badge counts only the viewer's own critical
 * items, and zero renders no badge at all, not a grey zero (plan §10.5).
 */

use App\Modules\ActionCenter\Services\ActionCenterService;
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

it('counts for a viewer only the criticals their own permissions reach', function (): void {
    // Something outstanding that admin-finance must NOT be counted: a paid,
    // uninvoiced order is `orders.invoice_missing`, gated on
    // `commerce.order.manage`, which finance does not hold.
    Helpers::paidOrder(now()->subDays(2));

    $finance = User::factory()->create(['status' => 'active']);
    $finance->assignRole('admin-finance');

    $response = $this->actingAs($finance)->get(route('admin.dashboard'));
    $response->assertOk();

    // This test used to be called "renders no badge for a viewer with nothing
    // outstanding" and asserted that the 400 characters following the first
    // `admin/action-center` link contained no `admin-nav-badge`. Both halves
    // were wrong and the test passed anyway:
    //
    //   * that anchor resolved inside the dashboard BODY, not the sidebar —
    //     the sidebar's Action Center item has been developer-only since
    //     2026-09-12 — and nav badges only ever render in the sidebar, which
    //     precedes the body. The assertion could not fail however wrong the
    //     count was.
    //   * "nothing outstanding" was never true. On an empty database
    //     `EngineHealthService` reports every scheduled period as missing, so
    //     admin-finance carries a double-figure `platform.engine_runs_failed`
    //     count through `finance.record`.
    //
    // Moving the dashboard to lazily-fetched panels removed the body link and
    // surfaced this. What the docblock above actually cares about — the count
    // is scoped to the viewer's own permissions — is asserted directly.
    $keys = app(ActionCenterService::class)->summary($finance)
        ->flatten(1)
        ->pluck('key')
        ->all();

    expect($keys)->not->toContain('orders.invoice_missing')
        ->and($keys)->not->toContain('grievance.sla_due_or_breached')
        ->and($keys)->not->toContain('kyc.pending_review');
});

it('never counts another viewer\'s criticals in the badge', function (): void {
    Helpers::paidOrder(now()->subDays(2)); // orders.invoice_missing — commerce.order.manage

    $operations = User::factory()->create(['status' => 'active']);
    $operations->assignRole('admin-operations');

    $compliance = User::factory()->create(['status' => 'active']);
    $compliance->assignRole('admin-compliance');

    $opsBadge = $this->actingAs($operations)->get(route('admin.dashboard'));
    $opsBadge->assertOk()->assertSee('admin-nav-badge', false);

    $this->actingAs($compliance)->get(route('admin.dashboard'))->assertOk();

    // The point of the test, asserted on the thing that decides it. The paid
    // uninvoiced order is `orders.invoice_missing`, which is gated on
    // `commerce.order.manage` — operations holds it, compliance does not, so
    // it must appear in one viewer's summary and not the other's. The previous
    // spelling compared rendered HTML around an anchor that no longer exists
    // (see the note in the test above).
    $keyFor = fn (User $user): array => app(ActionCenterService::class)
        ->summary($user)
        ->flatten(1)
        ->pluck('key')
        ->all();

    expect($keyFor($operations))->toContain('orders.invoice_missing');
    expect($keyFor($compliance))->not->toContain('orders.invoice_missing');
});
