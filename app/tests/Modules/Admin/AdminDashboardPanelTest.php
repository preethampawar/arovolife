<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Services\ActionCenterService;
use App\Modules\Admin\Services\DashboardPanelData;
use App\Modules\Admin\Support\DashboardPanels;
use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Services\DTOs\EngineHealthReport;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\ActionCenterFeature;
use App\Modules\Shared\Features\InventoryFeature;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * The dashboard's permission matrix, and the promise that the shell itself
 * carries no data.
 *
 * Every panel is gated on the ability that already gates its source data, so
 * these tests are the executable form of that claim: a scoped admin must reach
 * exactly the panels whose underlying page they could open, and no others.
 * Playwright cannot cover this — `tests/Browser/fixtures.js` has storage state
 * for `admin` and a distributor only, with nothing per scoped role — so the
 * matrix lives here, where roles can actually be created.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Both killswitches resolve false by default, so without this the matrix
    // tests below would be asserting a flag default rather than a permission —
    // and would keep passing if the permission were removed entirely. DSH-07
    // turns one back off to cover the flag-off path deliberately.
    Feature::for(null)->activate(ActionCenterFeature::class);
    Feature::for(null)->activate(InventoryFeature::class);
});

function dashUser(string $role): User
{
    $user = User::create([
        'full_name' => 'Dash '.$role,
        'email' => 'dash-'.uniqid().'@test.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function panelUrl(string $panel): string
{
    return route('admin.dashboard.panel', $panel);
}

it('DSH-01: a super admin can open every panel', function (): void {
    $admin = dashUser('admin');

    foreach (array_keys(DashboardPanels::PANELS) as $panel) {
        $this->actingAs($admin)->get(panelUrl($panel))->assertOk();
    }
});

it('DSH-02: admin-compliance is refused the four panels it holds no permission for', function (): void {
    $user = dashUser('admin-compliance');

    foreach (['sales', 'inventory', 'money', 'engines'] as $panel) {
        $this->actingAs($user)->get(panelUrl($panel))->assertForbidden();
    }
});

it('DSH-03: admin-operations is refused the finance panels', function (): void {
    $user = dashUser('admin-operations');

    foreach (['money', 'engines'] as $panel) {
        $this->actingAs($user)->get(panelUrl($panel))->assertForbidden();
    }
});

it('DSH-04: admin-operations can open the five panels it does hold', function (): void {
    $user = dashUser('admin-operations');

    foreach (['attention', 'sales', 'inventory', 'orders', 'people'] as $panel) {
        $this->actingAs($user)->get(panelUrl($panel))->assertOk();
    }
});

it('DSH-05: admin-finance can open the money, engine, sales and inventory panels', function (): void {
    $user = dashUser('admin-finance');

    foreach (['money', 'engines', 'sales', 'inventory'] as $panel) {
        $this->actingAs($user)->get(panelUrl($panel))->assertOk();
    }
});

it('DSH-06: an unknown panel key is a 404, not a 500', function (): void {
    $this->actingAs(dashUser('admin'))
        ->get('/admin/dashboard/panel/nope')
        ->assertNotFound();
});

it('DSH-07: a flag-off panel 404s and is absent from the shell entirely', function (): void {
    Feature::for(null)->deactivate(InventoryFeature::class);

    $admin = dashUser('admin');

    // 404 rather than 403: a 403 would confirm the module exists.
    $this->actingAs($admin)->get(panelUrl('inventory'))->assertNotFound();

    expect(DashboardPanels::visibleTo($admin))->not->toHaveKey('inventory');
});

it('DSH-08: a distributor cannot reach a panel at all', function (): void {
    $distributor = User::create([
        'full_name' => 'Not Staff',
        'email' => 'notstaff-'.uniqid().'@test.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($distributor)->get(panelUrl('people'));

    expect($response->status())->toBeIn([403, 302]);
});

it('DSH-09: the dashboard shell carries no panel data and no audit feed', function (): void {
    $admin = dashUser('admin');

    $count = function (string $url) use ($admin): int {
        $queries = 0;
        $listener = function () use (&$queries): void {
            $queries++;
        };
        DB::listen($listener);
        $this->actingAs($admin)->get($url)->assertOk();

        return $queries;
    };

    // Warm-up pass first. Pennant's database store inserts a row the first time
    // each flag resolves, and the sidebar badge counts cache for 60s, so an
    // un-warmed first request is not comparable to anything.
    $count(route('admin.help.show', 'dashboard'));
    $baseline = $count(route('admin.help.show', 'dashboard'));

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $response = $this->actingAs($admin)->get(route('admin.dashboard'));
    $response->assertOk();

    $body = $response->getContent();

    expect($body)->not->toContain('Recent Audit Events')
        ->and($body)->not->toContain('Audit Events Today')
        ->and($body)->not->toContain('Total Users');

    // The shell must carry no panel content: no rupee figure, and none of the
    // headings that only ever appear inside a rendered fragment. The skeleton
    // carries panel titles and pulse bars, nothing else.
    expect($body)->not->toMatch('/₹\s?[0-9]/')
        ->and($body)->not->toContain('Recent registrations')
        ->and($body)->not->toContain('Stock value')
        ->and($body)->not->toContain('Revenue ex GST');

    // The point of the whole change, asserted rather than described — and
    // asserted against a baseline rather than a magic number.
    //
    // A raw budget would be measuring the wrong thing. The shell costs 25
    // queries, and every one of them belongs to the admin chrome that wraps
    // *any* admin page: the Spatie permission tables, Pennant resolving each
    // killswitch once, and the sidebar's own badge counts. None of them comes
    // from this controller. So the guard compares the dashboard against a plain
    // admin page carrying the same chrome and no data of its own: if the
    // dashboard ever goes back to computing a tile eagerly, it costs more than
    // the baseline and this fails, whatever the nav happens to cost that month.
    expect($queries)->toBeLessThanOrEqual($baseline);
});

it('DSH-13: admin-finance sees stock figures but not the counts it cannot open', function (): void {
    // The panel gates on `inventory.view`, which finance holds. Three of its
    // tiles link to screens behind `inventory.manage`, which finance does not:
    // rendering those counts would hand finance numbers it cannot otherwise
    // reach, on tiles that 403 when clicked.
    $body = $this->actingAs(dashUser('admin-finance'))
        ->get(panelUrl('inventory'))
        ->assertOk()
        ->getContent();

    expect($body)->toContain('Stock value')
        ->and($body)->toContain('Low stock')
        ->and($body)->not->toContain('Active warehouses')
        ->and($body)->not->toContain('Open purchase orders')
        ->and($body)->not->toContain('Transfers in transit');

    // Operations holds inventory.manage, so for them all six render.
    $opsBody = $this->actingAs(dashUser('admin-operations'))
        ->get(panelUrl('inventory'))
        ->assertOk()
        ->getContent();

    expect($opsBody)->toContain('Active warehouses')
        ->and($opsBody)->toContain('Transfers in transit');
});

it('DSH-14: the attention panel renders the service\'s own order, criticals first', function (): void {
    // The panel must not re-sort. It used to sort by count, which put a breached
    // statutory clock with one item outstanding below a non-statutory warning
    // with fifty. Asserted on the rendered fragment rather than on a copy of the
    // comparator, so re-introducing a sort in the Blade fails here.
    $admin = dashUser('admin');

    $body = $this->actingAs($admin)
        ->get(panelUrl('attention'))
        ->assertOk()
        ->getContent();

    $labels = app(ActionCenterService::class)->summary($admin)
        ->flatten(1)
        ->pluck('label')
        ->all();

    if (count($labels) < 2) {
        $this->markTestSkipped('Needs at least two outstanding rows to have an order at all.');
    }

    $positions = array_map(fn (string $label): int|false => mb_strpos($body, $label), $labels);

    expect($positions)->not->toContain(false)
        // The service's order, preserved exactly.
        ->and($positions)->toBe(array_values(collect($positions)->sort()->all()));
});

it('DSH-10: admin-compliance sees exactly the three ungated panels', function (): void {
    $visible = DashboardPanels::visibleTo(dashUser('admin-compliance'));

    expect(array_keys($visible))->toBe(['attention', 'orders', 'people']);
});

it('DSH-11: the pending count joins distributors, so an orphan user never inflates it', function (): void {
    // A user with status=pending and NO distributor row: exactly the legacy
    // orphan the old `users`-only tile counted, which read "11 pending" while
    // the KYC queue held one.
    User::create([
        'full_name' => 'Orphan Signup',
        'email' => 'orphan-'.uniqid().'@test.com',
        'phone_e164' => '+9180000'.rand(10000, 99999),
        'password_hash' => bcrypt('x'),
        'password_set_at' => now(),
        'status' => 'pending',
    ]);

    $data = app(DashboardPanelData::class)->people();

    expect($data['pending'])->toBe(0);
});

it('DSH-12: sales counts on the order date, so an unshipped order still counts today', function (): void {
    // Placed and paid today, never shipped. Under the service's default
    // BASIS_SHIPPED this order is invisible, because `shipped_at` is null. The
    // dashboard asks "what came in", so it must count — that is the whole
    // reason the panel overrides the basis, and the reason the migration in
    // this change indexes `placed_at`.
    $customer = Customer::create([
        'display_name' => 'Dash Customer',
        'email_hash' => hash('sha256', 'dash-'.uniqid()),
        'email_enc' => 'dash-'.uniqid().'@test.com',
    ]);

    DB::table('orders')->insert([
        'order_no' => 'ORD-DSH-'.rand(10000, 99999),
        'customer_id' => $customer->id,
        'payment_method' => 'online',
        'status' => Order::STATUS_PAID,
        'self_consumption' => false,
        'subtotal_paise' => 118000,
        'gst_paise' => 18000,
        'discount_paise' => 0,
        'shipping_paise' => 0,
        'total_paise' => 118000,
        'ship_name' => 'Dash', 'ship_phone_e164' => '+919000000000',
        'ship_line1' => '1 St', 'ship_city' => 'Hyd', 'ship_state' => 'TS', 'ship_pincode' => '500001',
        'placed_at' => now(), 'paid_at' => now(), 'shipped_at' => null,
        'idempotency_key' => 'test-dsh-'.uniqid(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $data = app(DashboardPanelData::class)->sales();

    expect($data['windows']['today']['totals']['orders'])->toBe(1);
});

/**
 * DSH-15 — the panels' cached payloads must contain no objects.
 *
 * `config/cache.php` sets `serializable_classes => false`, so the real cache
 * stores unserialize with `allowed_classes: false` and hand every object back
 * as `__PHP_Incomplete_Class`. The write succeeds and the read succeeds; the
 * panel dies on the first method call against the husk, as a 500 inside the
 * fragment. The default test store is `array`, which never serialises at all,
 * so nothing else in this suite can see the problem — which is exactly how it
 * reached a browser instead of a test the first time.
 *
 * Asserted against the cache entry rather than the returned payload, because
 * `DashboardPanelData::remember()` deliberately rebuilds `generated_at` (and
 * the money panel's `stuck_since`) into Carbon on the way out. What must be
 * flat is what goes in.
 */
it('DSH-15: no panel caches an object, so every payload survives a real cache store', function (): void {
    $data = app(DashboardPanelData::class);

    $data->sales();
    $data->orders();
    $data->inventory();
    $data->money();
    $data->engines();
    $data->people();

    foreach (['sales', 'orders', 'inventory', 'money', 'engines', 'people'] as $panel) {
        $raw = Cache::get("admin.dashboard.{$panel}");

        expect($raw)->not->toBeNull("Panel {$panel} cached nothing under its own key.");

        // A faithful replay of what the Redis and file stores do. Any object in
        // there comes back as __PHP_Incomplete_Class and this stops matching.
        $roundTripped = unserialize(serialize($raw), ['allowed_classes' => false]);

        expect($roundTripped)->toEqual($raw, "Panel {$panel} caches an object; it will render as a 500 on any store that serialises.");
    }
});

it('DSH-16: the panels still hand the views real Carbon instants', function (): void {
    // The other half of DSH-15: flat in the cache, rebuilt on the way out, so
    // the "as of HH:MM" stamp is still a date the Blade can format.
    $data = app(DashboardPanelData::class);

    foreach (['sales', 'orders', 'inventory', 'money', 'engines', 'people'] as $panel) {
        expect($data->{$panel}()['generated_at'])->toBeInstanceOf(Carbon::class);
    }

    // And the report is a report again, not the five lists it was cached as.
    expect($data->engines()['report'])->toBeInstanceOf(EngineHealthReport::class);
});

it('DSH-17: a panel title containing an ampersand is escaped once, not twice', function (): void {
    // `<x-ui.card title="{{ $panelTitle }}">` escapes into the attribute and the
    // component escapes again on echo, so "Stock & warehouses" reached the page
    // as "Stock &amp;amp; warehouses" and rendered literally as "Stock &amp;".
    // `:title` passes the value through instead. Asserted on both surfaces: the
    // skeleton in the shell and the fragment that replaces it.
    $admin = dashUser('admin');

    $shell = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();
    $fragment = $this->actingAs($admin)->get(panelUrl('inventory'))->assertOk()->getContent();

    foreach ([$shell, $fragment] as $body) {
        expect($body)->toContain('Stock &amp; warehouses')
            ->and($body)->not->toContain('&amp;amp;');
    }
});
