<?php

declare(strict_types=1);

/**
 * Plan §6 — the summary is cached for 60 seconds per viewer; `items()` never
 * is. The cache key is per user because the provider set itself is
 * permission-dependent.
 */

use App\Modules\ActionCenter\Services\ActionCenterService;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->service = app(ActionCenterService::class);

    $this->operations = User::factory()->create(['status' => 'active']);
    $this->operations->assignRole('admin-operations');
});

it('summarises a group with its count, derived severity and oldest age', function (): void {
    Helpers::paidOrder(now()->subDays(4));
    Helpers::paidOrder(now()->subDays(2));

    $summary = $this->service->summary($this->operations);

    expect($summary->keys()->all())->toBe([ActionGroup::ORDERS]);

    $row = $summary[ActionGroup::ORDERS][0];

    expect($row['key'])->toBe('orders.paid_not_packed')
        ->and($row['count'])->toBe(2)
        // Both are past their due time, so the type's warning ceiling is promoted.
        ->and($row['severity'])->toBe(Severity::CRITICAL)
        ->and($row['oldest_age_hours'])->toBeGreaterThanOrEqual(95);
});

it('serves the summary from cache for 60 seconds and refreshes after', function (): void {
    Helpers::paidOrder(now()->subDays(4));

    expect($this->service->summary($this->operations)[ActionGroup::ORDERS][0]['count'])->toBe(1);
    expect(Cache::has(ActionCenterService::cacheKey($this->operations)))->toBeTrue();

    Helpers::paidOrder(now()->subDays(3));

    // Still the cached figure.
    expect($this->service->summary($this->operations)[ActionGroup::ORDERS][0]['count'])->toBe(1);

    $this->travel(ActionCenterService::CACHE_TTL_SECONDS + 5)->seconds();

    expect($this->service->summary($this->operations)[ActionGroup::ORDERS][0]['count'])->toBe(2);
});

it('caches per user and drops the viewer cache on a snooze', function (): void {
    $order = Helpers::paidOrder(now()->subDays(4));

    $this->service->summary($this->operations);
    expect(Cache::has(ActionCenterService::cacheKey($this->operations)))->toBeTrue();

    $this->service->snooze($this->operations, 'orders.paid_not_packed', 'order', $order->id, 3, 'Deferred pending stock.');

    expect(Cache::has(ActionCenterService::cacheKey($this->operations)))->toBeFalse()
        ->and($this->service->summary($this->operations)->isEmpty())->toBeTrue();
});

it('shows nothing to a viewer who holds no provider permission', function (): void {
    Helpers::paidOrder(now()->subDays(4));

    $finance = User::factory()->create(['status' => 'active']);
    $finance->assignRole('admin-finance');

    expect($this->service->summary($finance)->isEmpty())->toBeTrue()
        ->and($this->service->criticalCount($finance))->toBe(0)
        ->and($this->service->criticalCount($this->operations))->toBe(1);
});
