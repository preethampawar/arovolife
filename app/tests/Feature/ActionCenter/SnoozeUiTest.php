<?php

declare(strict_types=1);

/**
 * Plan §5, §9 (A5) — the snooze UI: a successful snooze hides the row on the
 * type page, a statutory type refuses with 422, and invalid input is
 * rejected before anything is written.
 */

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->operations = User::factory()->create(['status' => 'active']);
    $this->operations->assignRole('admin-operations');
});

it('hides the row from the type page once snoozed', function (): void {
    $order = Helpers::paidOrder(now()->subDays(3));

    $this->actingAs($this->operations)
        ->get(route('admin.action-center.show', 'orders.paid_not_packed'))
        ->assertOk()
        ->assertSee($order->order_no);

    $this->actingAs($this->operations)->post(route('admin.action-center.snooze', 'orders.paid_not_packed'), [
        'subject_type' => 'order',
        'subject_id' => $order->id,
        'days' => 3,
        'reason' => 'Courier strike, resumes Monday.',
    ])->assertRedirect(route('admin.action-center.show', 'orders.paid_not_packed'));

    expect(ActionCenterSnooze::query()->count())->toBe(1);

    $this->actingAs($this->operations)
        ->get(route('admin.action-center.show', 'orders.paid_not_packed'))
        ->assertOk()
        ->assertDontSee($order->order_no);
});

it('refuses to snooze a statutory action type with 422', function (): void {
    // invoice_missing is statutory (plan §4, §5).
    $this->actingAs($this->operations)->post(route('admin.action-center.snooze', 'orders.invoice_missing'), [
        'subject_type' => 'order',
        'subject_id' => 1,
        'days' => 3,
        'reason' => 'Trying to silence a statutory clock.',
    ])->assertStatus(422);

    expect(ActionCenterSnooze::query()->count())->toBe(0);
});

it('rejects a snooze with a reason under 10 characters', function (): void {
    $order = Helpers::paidOrder(now()->subDays(3));

    $this->actingAs($this->operations)->post(route('admin.action-center.snooze', 'orders.paid_not_packed'), [
        'subject_type' => 'order',
        'subject_id' => $order->id,
        'days' => 3,
        'reason' => 'too short',
    ])->assertSessionHasErrors('reason');

    expect(ActionCenterSnooze::query()->count())->toBe(0);
});

it('rejects a snooze window past the settings maximum', function (): void {
    $order = Helpers::paidOrder(now()->subDays(3));

    $this->actingAs($this->operations)->post(route('admin.action-center.snooze', 'orders.paid_not_packed'), [
        'subject_type' => 'order',
        'subject_id' => $order->id,
        'days' => 999,
        'reason' => 'Far too long a deferral.',
    ])->assertSessionHasErrors('days');

    expect(ActionCenterSnooze::query()->count())->toBe(0);
});

it('refuses a snoozed by a role that does not hold the provider\'s permission', function (): void {
    $order = Helpers::paidOrder(now()->subDays(3));

    $finance = User::factory()->create(['status' => 'active']);
    $finance->assignRole('admin-finance');

    $this->actingAs($finance)->post(route('admin.action-center.snooze', 'orders.paid_not_packed'), [
        'subject_type' => 'order',
        'subject_id' => $order->id,
        'days' => 3,
        'reason' => 'Finance trying to snooze an ops queue.',
    ])->assertNotFound();

    expect(ActionCenterSnooze::query()->count())->toBe(0);
});

it('unsnoozes and shows the row again', function (): void {
    $order = Helpers::paidOrder(now()->subDays(3));

    $this->actingAs($this->operations)->post(route('admin.action-center.snooze', 'orders.paid_not_packed'), [
        'subject_type' => 'order',
        'subject_id' => $order->id,
        'days' => 3,
        'reason' => 'Awaiting a replacement carton.',
    ]);

    expect(ActionCenterSnooze::query()->count())->toBe(1);

    $this->actingAs($this->operations)->delete(route('admin.action-center.unsnooze', 'orders.paid_not_packed'), [
        'subject_type' => 'order',
        'subject_id' => $order->id,
    ])->assertRedirect(route('admin.action-center.show', 'orders.paid_not_packed'));

    expect(ActionCenterSnooze::query()->count())->toBe(0);

    $this->actingAs($this->operations)
        ->get(route('admin.action-center.show', 'orders.paid_not_packed'))
        ->assertOk()
        ->assertSee($order->order_no);
});

it('keys the snooze on the provider own subject type, never the posted one', function (): void {
    $order = Helpers::paidOrder(now()->subDays(3));

    $this->actingAs($this->operations)->post(route('admin.action-center.snooze', 'orders.paid_not_packed'), [
        'subject_type' => 'spoofed',
        'subject_id' => $order->id,
        'days' => 3,
        'reason' => 'Courier pickup booked for Monday.',
    ])->assertRedirect();

    expect(ActionCenterSnooze::query()
        ->where('action_key', 'orders.paid_not_packed')
        ->where('subject_id', $order->id)
        ->value('subject_type'))->not->toBe('spoofed');

    $this->actingAs($this->operations)
        ->get(route('admin.action-center.show', 'orders.paid_not_packed'))
        ->assertOk()
        ->assertDontSee($order->order_no);
});
