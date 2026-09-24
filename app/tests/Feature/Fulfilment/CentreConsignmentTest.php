<?php

declare(strict_types=1);

/**
 * Slice 7: the centre owner's consignment page (R-47, behind R-97) and the
 * Action Center alert for a parcel left at a centre past the dwell limit.
 */

use App\Modules\ActionCenter\Providers\Orders\AtCentreNotCollectedProvider;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderCoolingOff;
use App\Modules\Commerce\Notifications\OrderReadyForCollectionNotification;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Fulfilment\Models\Shipment;
use App\Modules\Fulfilment\Services\CollectionHandoverService;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\seed;
use function Pest\Laravel\withSession;

require_once __DIR__.'/../../Support/shiprocket-fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    seed(LedgerAccountSeeder::class);
    Http::preventStrayRequests();
    srSetting(AreteCenter::COLLECTION_ENABLED_SETTING, 'true');
});

/**
 * A collection order sent by hand to a centre run by a distributor.
 *
 * @return array{0: Order, 1: User}
 */
function ccShippedToOwnedCentre(): array
{
    $owner = Distributor::factory()->create();
    $centre = srCentre();
    $centre->update(['assigned_distributor_id' => $owner->id, 'is_company_default' => false]);

    $order = srPaidOrder(null, 3, $centre);
    srDispatch($order, Shipment::GATEWAY_MANUAL, 'DTDC');

    return [$order->fresh(), $owner->user];
}

function ccBuyer(Order $order): User
{
    return User::findOrFail($order->customer?->user_id);
}

it('lets the owner confirm arrival, which emails the buyer a code the owner never sees', function () {
    Notification::fake();
    [$order, $owner] = ccShippedToOwnedCentre();

    actingAs($owner)->get(route('my.adc.consignments'))
        ->assertOk()
        ->assertSee($order->order_no)
        ->assertSee('For Ravi · 3 items')
        ->assertDontSee('Kumarswamy')
        ->assertDontSee(SR_BUYER_PHONE);

    actingAs($owner)->post(route('my.adc.consignments.received', $order->order_no))
        ->assertRedirect(route('my.adc.consignments'));

    expect($order->fresh()->status)->toBe(Order::STATUS_AWAITING_COLLECTION);

    $code = null;
    Notification::assertSentTo(ccBuyer($order), OrderReadyForCollectionNotification::class, function ($n) use (&$code): bool {
        $code = $n->collectionCode;

        return true;
    });

    expect($code)->toMatch('/^\d{6}$/');
    expect((string) session('success'))->not->toContain((string) $code);
    actingAs($owner)->get(route('my.adc.consignments'))->assertDontSee((string) $code);
});

it('hands over against the right code, delivering the order and opening cooling-off', function () {
    [$order, $owner] = ccShippedToOwnedCentre();
    app(CollectionHandoverService::class)->acknowledgeArrival($order);
    $code = app(CollectionHandoverService::class)->issueCode(Shipment::where('order_id', $order->id)->sole());

    actingAs($owner)->post(route('my.adc.consignments.handover', $order->order_no), ['code' => $code === '000000' ? '111111' : '000000'])
        ->assertSessionHasErrors('code');
    expect(Shipment::where('order_id', $order->id)->value('handover_attempts'))->toBe(1)
        ->and($order->fresh()->status)->toBe(Order::STATUS_AWAITING_COLLECTION);

    actingAs($owner)->post(route('my.adc.consignments.handover', $order->order_no), ['code' => $code])
        ->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe(Order::STATUS_DELIVERED)
        ->and(Shipment::where('order_id', $order->id)->value('collected_by_user_id'))->toBe($owner->id)
        ->and(OrderCoolingOff::where('order_id', $order->id)->exists())->toBeTrue();
});

it('refuses a distributor who does not run that centre', function () {
    [$order] = ccShippedToOwnedCentre();
    [, $otherOwner] = ccShippedToOwnedCentre();

    actingAs($otherOwner)->post(route('my.adc.consignments.received', $order->order_no))->assertForbidden();
    actingAs($otherOwner)->get(route('my.adc.consignments'))->assertOk()->assertDontSee($order->order_no);
    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
});

it('does not exist while collection is switched off, or for someone who runs no centre', function () {
    [$order, $owner] = ccShippedToOwnedCentre();

    actingAs(Distributor::factory()->create()->user)->get(route('my.adc.consignments'))->assertNotFound();

    srSetting(AreteCenter::COLLECTION_ENABLED_SETTING, 'false');
    actingAs($owner)->get(route('my.adc.consignments'))->assertNotFound();
    actingAs($owner)->post(route('my.adc.consignments.received', $order->order_no))->assertNotFound();
});

it('refuses to record a receipt while staff are impersonating the owner', function () {
    [$order, $owner] = ccShippedToOwnedCentre();

    withSession(['impersonator_id' => 1]);
    actingAs($owner)->post(route('my.adc.consignments.received', $order->order_no))->assertForbidden();

    expect($order->fresh()->status)->toBe(Order::STATUS_SHIPPED);
});

it('alerts staff to a parcel left at the centre past the dwell limit, and only that one', function () {
    [$old] = ccShippedToOwnedCentre();
    [$fresh] = ccShippedToOwnedCentre();
    app(CollectionHandoverService::class)->acknowledgeArrival($old);
    app(CollectionHandoverService::class)->acknowledgeArrival($fresh);
    Shipment::where('order_id', $old->id)->update(['at_centre_at' => now()->subDays(30)]);

    expect(app(AtCentreNotCollectedProvider::class)->items()->pluck('subjectId')->all())->toBe([$old->id])
        ->and($old->fresh()->status)->toBe(Order::STATUS_AWAITING_COLLECTION);
});
