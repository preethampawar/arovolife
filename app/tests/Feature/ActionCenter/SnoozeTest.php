<?php

declare(strict_types=1);

/**
 * Plan §5 — snooze is the Action Center's only write. It writes its own audit
 * row, statutory types refuse it, the window is capped, and an expired snooze
 * stops filtering without anything sweeping it.
 */

use App\Modules\ActionCenter\Exceptions\CannotSnoozeStatutoryAction;
use App\Modules\ActionCenter\Exceptions\InvalidSnoozeWindow;
use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\AbstractProvider;
use App\Modules\ActionCenter\Providers\Orders\PaidNotPackedProvider;
use App\Modules\ActionCenter\Services\ActionCenterRegistry;
use App\Modules\ActionCenter\Services\ActionCenterService;
use App\Modules\ActionCenter\Services\ActionCenterSettings;
use App\Modules\ActionCenter\Support\ActionGroup;
use App\Modules\ActionCenter\Support\Severity;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

/** Stands in for the statutory catalogue entries (grievance SLA, invoice gaps). */
final class StatutoryTestProvider extends AbstractProvider
{
    public function key(): string
    {
        return 'test.statutory';
    }

    public function group(): string
    {
        return ActionGroup::COMPLIANCE;
    }

    public function label(): string
    {
        return 'Statutory';
    }

    public function description(): string
    {
        return 'A clock nobody may silence.';
    }

    public function permission(): string
    {
        return 'commerce.order.manage';
    }

    public function severity(): string
    {
        return Severity::CRITICAL;
    }

    public function statutory(): bool
    {
        return true;
    }

    public function subjectType(): string
    {
        return 'order';
    }

    public function count(): int
    {
        return 0;
    }

    public function items(int $limit = 50): Collection
    {
        return Collection::make();
    }
}

function acSnoozeService(): ActionCenterService
{
    return new ActionCenterService(
        new ActionCenterRegistry([app(PaidNotPackedProvider::class), new StatutoryTestProvider]),
        app(ActionCenterSettings::class),
    );
}

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->actor = User::factory()->create(['status' => 'active']);
    $this->service = acSnoozeService();
});

it('writes the snooze row and its audit entry together', function (): void {
    $order = Helpers::paidOrder(now()->subDays(3));

    $snooze = $this->service->snooze($this->actor, 'orders.paid_not_packed', 'order', $order->id, 5, 'Courier strike, picking resumes Monday.');

    expect($snooze->snoozed_until->greaterThan(now()->addDays(4)))->toBeTrue();

    $audit = AuditLog::query()->where('action', 'action_center.snoozed')->sole();

    expect($audit->actor_id)->toBe($this->actor->id)
        ->and($audit->subject_type)->toBe('order')
        ->and($audit->subject_id)->toBe($order->id)
        ->and($audit->details['action_key'])->toBe('orders.paid_not_packed')
        ->and($audit->details['reason'])->toBe('Courier strike, picking resumes Monday.');
});

it('refuses to snooze a statutory action type', function (): void {
    expect(fn () => $this->service->snooze($this->actor, 'test.statutory', 'order', 1, 2, 'Known and deferred.'))
        ->toThrow(CannotSnoozeStatutoryAction::class);

    expect(ActionCenterSnooze::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'action_center.snoozed')->count())->toBe(0);
});

it('caps the snooze window at the settings maximum', function (): void {
    DB::table('settings')->insert(['key' => ActionCenterSettings::KEY_MAX_SNOOZE_DAYS, 'value' => '7']);
    $service = acSnoozeService();
    $order = Helpers::paidOrder(now()->subDays(3));

    expect(fn () => $service->snooze($this->actor, 'orders.paid_not_packed', 'order', $order->id, 8, 'Too long a deferral.'))
        ->toThrow(InvalidSnoozeWindow::class);

    expect(fn () => $service->snooze($this->actor, 'orders.paid_not_packed', 'order', $order->id, 0, 'Not a window at all.'))
        ->toThrow(InvalidSnoozeWindow::class);

    $service->snooze($this->actor, 'orders.paid_not_packed', 'order', $order->id, 7, 'Right at the cap.');

    expect(ActionCenterSnooze::query()->count())->toBe(1);
});

it('hides the subject while snoozed and shows it again once the snooze expires', function (): void {
    $order = Helpers::paidOrder(now()->subDays(3));
    $provider = app(PaidNotPackedProvider::class);

    expect($provider->count())->toBe(1);

    $this->service->snooze($this->actor, 'orders.paid_not_packed', 'order', $order->id, 2, 'Awaiting a replacement carton.');

    expect($provider->count())->toBe(0);

    $this->travel(3)->days();

    // The row is still there — nothing sweeps it — it simply no longer matches.
    expect(ActionCenterSnooze::query()->count())->toBe(1)
        ->and($provider->count())->toBe(1);
});

it('unsnoozes a subject and audits that too', function (): void {
    $order = Helpers::paidOrder(now()->subDays(3));
    $provider = app(PaidNotPackedProvider::class);

    $this->service->snooze($this->actor, 'orders.paid_not_packed', 'order', $order->id, 5, 'Deferred by mistake.');
    $this->service->unsnooze($this->actor, 'orders.paid_not_packed', 'order', $order->id);

    expect(ActionCenterSnooze::query()->count())->toBe(0)
        ->and($provider->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'action_center.unsnoozed')->count())->toBe(1);
});
