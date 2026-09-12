<?php

declare(strict_types=1);

/**
 * Plan §10.2 — the Action Center is permission-scoped, not role-scoped. A
 * viewer sees exactly the queues they hold the permission to clear.
 */

use App\Modules\ActionCenter\Contracts\ActionProvider;
use App\Modules\ActionCenter\Exceptions\UnknownActionType;
use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Providers\Orders\PaidNotPackedProvider;
use App\Modules\ActionCenter\Services\ActionCenterRegistry;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** A provider whose feature flag is off: hidden, never queried (contract rule 4). */
final class DisabledTestProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'test.disabled';
    }

    public function group(): string
    {
        return ActionGroup::PLATFORM;
    }

    public function label(): string
    {
        return 'Disabled';
    }

    public function description(): string
    {
        return 'Never shown.';
    }

    public function permission(): string
    {
        return 'commerce.order.manage';
    }

    public function severity(): string
    {
        return Severity::INFO;
    }

    public function subjectType(): string
    {
        return 'test';
    }

    public function enabled(): bool
    {
        return false;
    }

    public function count(): int
    {
        throw new RuntimeException('A disabled provider must never be queried.');
    }

    public function items(int $limit = 50): Collection
    {
        throw new RuntimeException('A disabled provider must never be queried.');
    }
}

it('shows a provider only to a viewer holding its permission', function (): void {
    $registry = app(ActionCenterRegistry::class);

    $operations = User::factory()->create(['status' => 'active']);
    $operations->assignRole('admin-operations');

    $finance = User::factory()->create(['status' => 'active']);
    $finance->assignRole('admin-finance');

    // commerce.order.manage sits with admin-operations only.
    expect($registry->for($operations)->map(fn (ActionProvider $p): string => $p->key())->all())->toContain('orders.paid_not_packed')
        ->and($registry->for($finance)->map(fn (ActionProvider $p): string => $p->key())->all())->not->toContain('orders.paid_not_packed');
});

it('registers the reference provider in the container as a tagged provider', function (): void {
    $registry = app(ActionCenterRegistry::class);

    expect($registry->all())->not->toBeEmpty()
        ->and($registry->find('orders.paid_not_packed'))->toBeInstanceOf(PaidNotPackedProvider::class);
});

it('refuses a key the viewer may not act on, and an unknown key', function (): void {
    $registry = app(ActionCenterRegistry::class);

    $finance = User::factory()->create(['status' => 'active']);
    $finance->assignRole('admin-finance');

    expect(fn () => $registry->findFor($finance, 'orders.paid_not_packed'))
        ->toThrow(UnknownActionType::class);

    expect(fn () => $registry->find('nope.not_a_type'))
        ->toThrow(UnknownActionType::class);
});

it('hides a provider whose feature flag is off without querying it', function (): void {
    $registry = new ActionCenterRegistry([new DisabledTestProvider, app(PaidNotPackedProvider::class)]);

    $operations = User::factory()->create(['status' => 'active']);
    $operations->assignRole('admin-operations');

    expect($registry->for($operations)->map(fn (ActionProvider $p): string => $p->key())->all())->toBe(['orders.paid_not_packed']);
});
