<?php

declare(strict_types=1);

/**
 * Declaration v3, and the mechanism for accepting it.
 *
 * The version had never moved before, so nothing had ever had to re-accept.
 * Bumping it to v3 makes every centre owe a fresh signature, and without a
 * surface to give one that would have blocked collection outright — which is
 * what R-95 was warning about.
 */

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\OrderStateMachine;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Compensation\Models\AreteCenterDeclaration;
use App\Modules\Compensation\Services\AreteCenterDeclarationService;
use App\Modules\Compensation\Support\AreteCenterDeclarations;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\AreteCenterApplicationsFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The Distributor factory seeds sponsor_id / placement_parent_id as 0,
    // which no root row satisfies. Nothing here turns on FK behaviour — the
    // subject is the declaration row, the audit row and who may write them.
    disableTestForeignKeys();
});

function declCentre(?int $distributorId = null): AreteCenter
{
    $n = random_int(100000, 999999);

    return AreteCenter::create([
        'name' => "Decl Centre {$n}",
        'centre_type' => $distributorId === null ? AreteCenter::TYPE_COMPANY : AreteCenter::TYPE_DISTRIBUTOR,
        'status' => AreteCenter::STATUS_ACTIVE,
        'assigned_distributor_id' => $distributorId,
        'address_line_1' => '5 Market Street', 'city' => 'Warangal', 'state' => 'TELANGANA',
        'pincode' => '506002', 'contact_number' => '+918888888888',
    ]);
}

/** @param list<string> $keys */
function declRowsAt(AreteCenter $centre, string $version, array $keys): void
{
    foreach ($keys as $key) {
        AreteCenterDeclaration::create([
            'center_id' => $centre->id,
            'declaration_key' => $key,
            'version' => $version,
            'accepted_at' => now(),
            'ip' => '127.0.0.1',
        ]);
    }
}

// ── The version itself ────────────────────────────────────────────────────

it('is on v3 and carries the two clauses R-21 turned on', function (): void {
    expect(AreteCenterDeclarations::VERSION)->toBe('v3');

    $all = AreteCenterDeclarations::all();

    expect($all)->toHaveKey('buyer_data_duty')
        ->and($all['training_use_only'])->toContain('already placed and paid for')
        ->and($all['training_use_only'])->toContain('§5.1 and §5.2')
        // The v2 citation was §9, which is PII Handling, not the clause the
        // prohibition actually lives in.
        ->and($all['training_use_only'])->not->toContain('§9');
});

it('can still render the superseded text an existing row points at', function (): void {
    $v2 = AreteCenterDeclarations::forVersion('v2');

    expect($v2['training_use_only'])->toContain('e-commerce fulfilment point')
        ->and($v2)->not->toHaveKey('buyer_data_duty');
});

it('refuses to guess at a version it has no text for', function (): void {
    AreteCenterDeclarations::forVersion('v9');
})->throws(InvalidArgumentException::class);

// ── What the dispatch gate reads ──────────────────────────────────────────

it('treats a centre holding only v2 acceptances as not having accepted', function (): void {
    $centre = declCentre();
    declRowsAt($centre, 'v2', ['training_use_only', 'details_true', 'inspection_and_phases', 'deactivation_consent', 'contact_consent']);

    expect(AreteCenterDeclaration::currentVersionAcceptedBy($centre->id))->toBeFalse()
        ->and(AreteCenterDeclaration::outstandingFor($centre->id))->toContain('buyer_data_duty');
});

it('treats a partial v3 acceptance as not having accepted', function (): void {
    $centre = declCentre();
    declRowsAt($centre, AreteCenterDeclarations::VERSION, ['training_use_only', 'details_true']);

    expect(AreteCenterDeclaration::currentVersionAcceptedBy($centre->id))->toBeFalse();
});

// ── Accepting ─────────────────────────────────────────────────────────────

it('lets a centre owner accept, and records it', function (): void {
    $distributor = Distributor::factory()->create();
    $centre = declCentre($distributor->id);

    app(AreteCenterDeclarationService::class)->acceptByOwner(
        $centre, $distributor, AreteCenterDeclarations::keys(), $distributor->user->id, '203.0.113.9'
    );

    expect(AreteCenterDeclaration::currentVersionAcceptedBy($centre->id))->toBeTrue();

    $row = AreteCenterDeclaration::where('center_id', $centre->id)->first();
    expect($row->version)->toBe('v3')->and($row->ip)->toBe('203.0.113.9');

    expect(AuditLog::where('action', 'arete_center.declarations_accepted')
        ->where('subject_id', $centre->id)->exists())->toBeTrue();
});

it('refuses a partial acceptance', function (): void {
    $distributor = Distributor::factory()->create();
    $centre = declCentre($distributor->id);

    app(AreteCenterDeclarationService::class)->acceptByOwner(
        $centre, $distributor, ['training_use_only'], $distributor->user->id, null
    );
})->throws(InvalidArgumentException::class);

it('will not let one distributor accept for another distributor\'s centre', function (): void {
    $owner = Distributor::factory()->create();
    $other = Distributor::factory()->create();
    $centre = declCentre($owner->id);

    app(AreteCenterDeclarationService::class)->acceptByOwner(
        $centre, $other, AreteCenterDeclarations::keys(), $other->user->id, null
    );
})->throws(InvalidArgumentException::class);

it('will not let an admin sign on an assigned distributor\'s behalf', function (): void {
    $owner = Distributor::factory()->create();
    $centre = declCentre($owner->id);

    // The whole point of the split entry points: this signature is the
    // evidence the dispatch gate reads, so staff must not be able to produce
    // it for the person it belongs to.
    app(AreteCenterDeclarationService::class)->acceptForCompanyCentre(
        $centre, 1, AreteCenterDeclarations::keys(), null
    );
})->throws(InvalidArgumentException::class);

it('lets staff accept for a company centre that has no distributor to sign', function (): void {
    $staff = Distributor::factory()->create();
    $centre = declCentre();

    app(AreteCenterDeclarationService::class)->acceptForCompanyCentre(
        $centre, $staff->user->id, AreteCenterDeclarations::keys(), '198.51.100.4'
    );

    expect(AreteCenterDeclaration::currentVersionAcceptedBy($centre->id))->toBeTrue();
    expect(AuditLog::where('action', 'arete_center.declarations_accepted')
        ->where('subject_id', $centre->id)->value('details'))
        ->toMatchArray(['signed_by' => 'company']);
});

it('is idempotent, so a double submit does not duplicate rows', function (): void {
    $distributor = Distributor::factory()->create();
    $centre = declCentre($distributor->id);
    $service = app(AreteCenterDeclarationService::class);

    $service->acceptByOwner($centre, $distributor, AreteCenterDeclarations::keys(), $distributor->user->id, null);
    $service->acceptByOwner($centre, $distributor, AreteCenterDeclarations::keys(), $distributor->user->id, null);

    expect(AreteCenterDeclaration::where('center_id', $centre->id)->count())
        ->toBe(count(AreteCenterDeclarations::keys()));
});

// ── The distributor-facing route ──────────────────────────────────────────

it('lets the owner accept over HTTP and clears the block', function (): void {
    Feature::for(null)->activate(AreteCenterApplicationsFeature::class);

    $distributor = Distributor::factory()->create();
    $centre = declCentre($distributor->id);

    $this->actingAs($distributor->user)
        ->post(route('my.adc.declarations.accept', $centre), [
            'declarations' => AreteCenterDeclarations::keys(),
        ])
        ->assertRedirect(route('my.adc.status'));

    expect(AreteCenterDeclaration::currentVersionAcceptedBy($centre->id))->toBeTrue();
});

it('refuses over HTTP when the centre is not the caller\'s', function (): void {
    Feature::for(null)->activate(AreteCenterApplicationsFeature::class);

    $owner = Distributor::factory()->create();
    $other = Distributor::factory()->create();
    $centre = declCentre($owner->id);

    $this->actingAs($other->user)
        ->post(route('my.adc.declarations.accept', $centre), [
            'declarations' => AreteCenterDeclarations::keys(),
        ])
        ->assertForbidden();

    expect(AreteCenterDeclaration::currentVersionAcceptedBy($centre->id))->toBeFalse();
});

it('shows the owner what is outstanding, and the period they are bound to', function (): void {
    Feature::for(null)->activate(AreteCenterApplicationsFeature::class);

    $distributor = Distributor::factory()->create();
    $centre = declCentre($distributor->id);

    $this->actingAs($distributor->user)
        ->get(route('my.adc.status'))
        ->assertOk()
        ->assertSee('Please accept the centre declarations')
        ->assertSee('Declarations pending')
        ->assertSee('15 days');
});

// ── The bypass the browser found ──────────────────────────────────────────
//
// `DispatchService` holds the gate, but nothing in the application called it:
// the admin order screen's Mark-as-Shipped goes straight to the state machine.
// These cover the transition itself, which is the choke point both routes
// share — the tests below are the ones that would have caught that.

function declCollectionOrder(AreteCenter $centre): Order
{
    $n = random_int(100000, 999999);

    return Order::create([
        'order_no' => "ORD-DECL-{$n}",
        'customer_id' => Customer::create(['display_name' => "Buyer {$n}"])->id,
        'idempotency_key' => "decl-{$n}",
        'status' => Order::STATUS_PAID,
        'delivery_type' => Order::DELIVERY_COLLECT,
        'arete_center_id' => $centre->id,
    ]);
}

it('refuses to ship a collection order whose centre has not accepted', function (): void {
    $centre = declCentre();
    $order = declCollectionOrder($centre);

    expect(fn () => app(OrderStateMachine::class)->markShipped($order))
        ->toThrow(RuntimeException::class, 'has not accepted the current centre declarations');

    expect($order->fresh()->status)->toBe(Order::STATUS_PAID);
});

it('names every outstanding declaration so the operator knows what to chase', function (): void {
    $centre = declCentre();
    $order = declCollectionOrder($centre);

    $error = null;

    try {
        app(OrderStateMachine::class)->markShipped($order);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }

    expect($error)->not->toBeNull();

    foreach (AreteCenterDeclarations::keys() as $key) {
        expect($error)->toContain($key);
    }

    expect($error)->toContain(AreteCenterDeclarations::VERSION);
});

it('refuses to ship a collection order whose centre has been deleted', function (): void {
    $centre = declCentre();
    $order = declCollectionOrder($centre);
    $centre->delete();

    expect(fn () => app(OrderStateMachine::class)->markShipped($order->fresh()))
        ->toThrow(RuntimeException::class, 'its centre no longer exists');
});

it('does not gate a home delivery, which has no centre to declare anything', function (): void {
    $n = random_int(100000, 999999);
    $order = Order::create([
        'order_no' => "ORD-HOME-{$n}",
        'customer_id' => Customer::create(['display_name' => "Buyer {$n}"])->id,
        'idempotency_key' => "home-{$n}",
        'status' => Order::STATUS_PAID,
        'delivery_type' => Order::DELIVERY_SHIP,
    ]);

    // Not asserting a successful ship here — that needs stock, a warehouse and
    // a balanced ledger entry, and DispatchAndCollectionTest already covers it.
    // What matters is that the declaration guard is not what stops it, so the
    // assertion runs whether shipping succeeded or failed for some other reason.
    $error = null;

    try {
        app(OrderStateMachine::class)->markShipped($order);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }

    expect((string) $error)->not->toContain('centre declarations');
});

// ── The two forgery paths the compliance review found ─────────────────────
//
// Both defeat the two-entry-point split in AreteCenterDeclarationService by
// going around it rather than through it. The split was right; it just did not
// reach either of these.

it('refuses to accept declarations while an admin is impersonating the owner', function (): void {
    Feature::for(null)->activate(AreteCenterApplicationsFeature::class);

    $distributor = Distributor::factory()->create();
    $centre = declCentre($distributor->id);

    // A full session swap is what impersonation does, so acting as the
    // distributor with the impersonator marker set is a faithful reproduction.
    $this->actingAs($distributor->user)
        ->withSession(['impersonator_id' => 99])
        ->post(route('my.adc.declarations.accept', $centre), [
            'declarations' => AreteCenterDeclarations::keys(),
        ])
        ->assertForbidden();

    expect(AreteCenterDeclaration::currentVersionAcceptedBy($centre->id))->toBeFalse();
});

it('retires a centre\'s declarations when it is assigned to a different distributor', function (): void {
    $owner = Distributor::factory()->create();
    $centre = declCentre($owner->id);

    app(AreteCenterDeclarationService::class)->acceptByOwner(
        $centre, $owner, AreteCenterDeclarations::keys(), $owner->user->id, null
    );
    expect(AreteCenterDeclaration::currentVersionAcceptedBy($centre->id))->toBeTrue();

    AreteCenterDeclaration::supersedeAllFor($centre->id, 'centre_reassigned');

    // The incoming operator has undertaken nothing, so the gate must close...
    expect(AreteCenterDeclaration::currentVersionAcceptedBy($centre->id))->toBeFalse()
        ->and(AreteCenterDeclaration::outstandingFor($centre->id))->toHaveCount(count(AreteCenterDeclarations::keys()));

    // ...but what the previous owner signed is still on file, because it is
    // still true. Superseded is not deleted.
    expect(AreteCenterDeclaration::where('center_id', $centre->id)->whereNotNull('superseded_at')->count())
        ->toBe(count(AreteCenterDeclarations::keys()));
});

it('closes the two-step admin-on-behalf route: accept for a company centre, then assign an owner', function (): void {
    $staff = User::factory()->create();
    $centre = declCentre();

    app(AreteCenterDeclarationService::class)->acceptForCompanyCentre(
        $centre, $staff->id, AreteCenterDeclarations::keys(), null
    );
    expect(AreteCenterDeclaration::currentVersionAcceptedBy($centre->id))->toBeTrue();

    // An admin now hands the centre to a distributor who has signed nothing.
    $incoming = Distributor::factory()->create();
    $centre->update(['assigned_distributor_id' => $incoming->id]);
    AreteCenterDeclaration::supersedeAllFor($centre->id, 'centre_reassigned');

    expect(AreteCenterDeclaration::currentVersionAcceptedBy($centre->id))->toBeFalse();
});

// ── R-97: collection is not offered before the notice covering it exists ──

it('offers no centre at checkout while the collection switch is off', function (): void {
    $centre = declCentre();
    $centre->update(['is_company_default' => true]);

    // Default is OFF, and RefreshDatabase leaves no settings row behind.
    expect(AreteCenter::collectionEnabled())->toBeFalse()
        ->and(AreteCenter::collectionChoicesFor(null))->toBeEmpty();
});

it('offers the centre once the switch is on', function (): void {
    $centre = declCentre();
    $centre->update(['is_company_default' => true]);

    DB::table('settings')->updateOrInsert(
        ['key' => AreteCenter::COLLECTION_ENABLED_SETTING],
        ['value' => 'true'],
    );

    expect(AreteCenter::collectionEnabled())->toBeTrue()
        ->and(AreteCenter::collectionChoicesFor(null)->pluck('id')->all())->toContain($centre->id);
});

it('still dispatches an order already placed for collection after the switch goes off', function (): void {
    $centre = declCentre();
    $order = declCollectionOrder($centre);

    app(AreteCenterDeclarationService::class)->acceptForCompanyCentre(
        $centre, User::factory()->create()->id, AreteCenterDeclarations::keys(), null
    );

    // The switch is off, so no NEW buyer is offered the centre — but this
    // order was placed while it was on, and turning a switch off must not
    // strand a parcel that is already mid-journey.
    expect(AreteCenter::collectionEnabled())->toBeFalse();

    $error = null;

    try {
        app(OrderStateMachine::class)->markShipped($order);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }

    expect((string) $error)->not->toContain('centre declarations');
});
