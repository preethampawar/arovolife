<?php

declare(strict_types=1);

/**
 * Analytics — funnels, retention and the shape of the base.
 *
 * ANL-001: the registration funnel counts distinct people at each milestone
 * ANL-002: a stage's drop is measured from the stage before it, not from the first
 * ANL-003: the commerce funnel excludes drafts from "order placed"
 * ANL-004: headline totals exclude cancelled and refunded orders
 * ANL-005: retention is the share of LAST month's buyers who bought again
 * ANL-006: a month with no buyers before it reports no retention rather than zero
 * ANL-007: cancelled and refunded orders do not make someone a buyer
 * ANL-008: "never bought" counts distributors with no settled order of their own
 * ANL-009: top-by-volume is ordered by BV in the window and carries no rank or earnings
 * ANL-010: the page renders for an admin and rejects a distributor
 * ANL-011: a malformed date in the query string falls back rather than throwing
 */

use App\Modules\Analytics\Services\FunnelAnalytics;
use App\Modules\Analytics\Services\RetentionAnalytics;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

// ─── helpers ─────────────────────────────────────────────────────────────────

function anlFunnels(): FunnelAnalytics
{
    return app(FunnelAnalytics::class);
}

function anlRetention(): RetentionAnalytics
{
    return app(RetentionAnalytics::class);
}

/** A settled order attributed to a distributor. */
function anlOrder(int $distributorId, Carbon $paidAt, string $status = 'delivered', int $totalPaise = 100000): int
{
    $customerId = DB::table('customers')->insertGetId([
        'display_name' => 'Buyer',
        'created_at' => $paidAt,
        'updated_at' => $paidAt,
    ]);

    return (int) DB::table('orders')->insertGetId([
        'order_no' => 'ORD-ANL-'.random_int(1000000, 9999999),
        'customer_id' => $customerId,
        'attributed_distributor_id' => $distributorId,
        'attribution_source' => 'logged_in',
        'self_consumption' => 1,
        'status' => $status,
        'subtotal_paise' => $totalPaise,
        'gst_paise' => 0,
        'shipping_paise' => 0,
        'discount_paise' => 0,
        'total_paise' => $totalPaise,
        'payment_method' => 'online',
        'idempotency_key' => 'anl-'.uniqid(),
        'ship_name' => 'Buyer',
        'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St',
        'ship_city' => 'Pune',
        'ship_state' => 'MH',
        'ship_pincode' => '411001',
        'placed_at' => $paidAt,
        // A draft has never been paid; everything else in these fixtures has.
        'paid_at' => $status === 'draft' ? null : $paidAt,
        'created_at' => $paidAt,
        'updated_at' => $paidAt,
    ]);
}

/** An admin who can reach the whole admin area. */
function anlAdmin(): User
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

// ─── the funnels ─────────────────────────────────────────────────────────────

it('counts distinct people at each registration milestone', function (): void {
    $from = Carbon::parse('2026-08-01')->startOfDay();
    $to = Carbon::parse('2026-08-31')->endOfDay();
    $inside = Carbon::parse('2026-08-10 09:00');

    // Three prospects start orientation. One of them opens it twice — the
    // funnel must count people, not page views.
    DB::table('orientation_views')->insert([
        ['distributor_id' => 1, 'video_id' => 1, 'started_at' => $inside, 'watch_percent' => 100, 'quiz_passed_at' => $inside],
        ['distributor_id' => 1, 'video_id' => 1, 'started_at' => $inside->copy()->addHour(), 'watch_percent' => 100, 'quiz_passed_at' => null],
        ['distributor_id' => 2, 'video_id' => 1, 'started_at' => $inside, 'watch_percent' => 40, 'quiz_passed_at' => null],
        ['distributor_id' => 3, 'video_id' => 1, 'started_at' => $inside, 'watch_percent' => 100, 'quiz_passed_at' => $inside],
    ]);

    // One prospect started before the window opened. They are outside it.
    DB::table('orientation_views')->insert([
        ['distributor_id' => 4, 'video_id' => 1, 'started_at' => Carbon::parse('2026-07-20'), 'watch_percent' => 100, 'quiz_passed_at' => null],
    ]);

    $stages = anlFunnels()->registration($from, $to);

    expect($stages[0]->label)->toBe('Orientation started')
        ->and($stages[0]->count)->toBe(3)
        ->and($stages[1]->label)->toBe('Orientation passed')
        ->and($stages[1]->count)->toBe(2);
});

it('measures each drop from the stage before it', function (): void {
    $from = Carbon::parse('2026-08-01')->startOfDay();
    $to = Carbon::parse('2026-08-31')->endOfDay();
    $inside = Carbon::parse('2026-08-10 09:00');

    // 4 start, 2 pass, 1 consents. The second step loses half; the third
    // loses half of what reached it — but only a quarter of the first.
    foreach ([1, 2, 3, 4] as $id) {
        DB::table('orientation_views')->insert([
            'distributor_id' => $id, 'video_id' => 1, 'started_at' => $inside,
            'watch_percent' => 100, 'quiz_passed_at' => $id <= 2 ? $inside : null,
        ]);
    }
    DB::table('consents')->insert([
        'distributor_id' => 1, 'document_type' => 'tnc', 'document_version' => '1.0',
        'accepted_at' => $inside, 'ip' => '127.0.0.1', 'user_agent' => 'test',
    ]);

    $stages = anlFunnels()->registration($from, $to);

    expect($stages[1]->shareOfPrevious)->toBe(50.0)
        ->and($stages[2]->shareOfPrevious)->toBe(50.0)
        // Cumulative survival tells a different and flatter story, which is
        // exactly why the page shows the step-on-step figure.
        ->and($stages[2]->shareOfFirst)->toBe(25.0)
        ->and($stages[2]->dropFromPrevious())->toBe(50.0)
        // Nothing precedes the first stage, so it has no drop to report.
        ->and($stages[0]->dropFromPrevious())->toBeNull();
});

it('does not count a draft order as placed', function (): void {
    $from = Carbon::parse('2026-08-01')->startOfDay();
    $to = Carbon::parse('2026-08-31')->endOfDay();

    anlOrder(1, Carbon::parse('2026-08-05'), 'delivered');
    anlOrder(2, Carbon::parse('2026-08-06'), 'draft');

    $stages = anlFunnels()->commerce($from, $to);
    $placed = collect($stages)->firstWhere('label', 'Order placed');

    expect($placed?->count)->toBe(1);
});

it('excludes cancelled and refunded orders from the headline totals', function (): void {
    $from = Carbon::parse('2026-08-01')->startOfDay();
    $to = Carbon::parse('2026-08-31')->endOfDay();

    anlOrder(1, Carbon::parse('2026-08-05'), 'delivered', 200000);
    anlOrder(2, Carbon::parse('2026-08-06'), 'delivered', 100000);
    anlOrder(3, Carbon::parse('2026-08-07'), 'cancelled', 500000);
    anlOrder(4, Carbon::parse('2026-08-08'), 'refunded', 900000);

    $totals = anlFunnels()->commerceTotals($from, $to);

    expect($totals['orders'])->toBe(2)
        ->and($totals['gross_paise'])->toBe(300000)
        ->and($totals['average_order_paise'])->toBe(150000)
        // They are still reported — separately, as the losses they are.
        ->and($totals['cancelled'])->toBe(1)
        ->and($totals['refunded'])->toBe(1);
});

// ─── retention ───────────────────────────────────────────────────────────────

it('reports retention as the share of last month s buyers who came back', function (): void {
    // July: four buyers. August: two of them return, plus one new.
    foreach ([1, 2, 3, 4] as $id) {
        anlOrder($id, Carbon::parse('2026-07-10'));
    }
    foreach ([1, 2, 9] as $id) {
        anlOrder($id, Carbon::parse('2026-08-10'));
    }

    $rows = anlRetention()->monthlyRetention(2, Carbon::parse('2026-08-20'));
    $august = collect($rows)->firstWhere('month', '2026-08');

    expect($august['buyers'])->toBe(3)
        ->and($august['returning'])->toBe(2)
        // 2 of July's 4 stayed — 50%. Measured against August's 3 buyers it
        // would read 67% and would count the new buyer as retained.
        ->and($august['retention_pct'])->toBe(50.0);
});

it('reports no retention rather than zero when nobody bought the month before', function (): void {
    anlOrder(1, Carbon::parse('2026-08-10'));

    $rows = anlRetention()->monthlyRetention(1, Carbon::parse('2026-08-20'));

    expect($rows[0]['buyers'])->toBe(1)
        ->and($rows[0]['retention_pct'])->toBeNull();
});

it('does not treat a cancelled or refunded order as a purchase', function (): void {
    anlOrder(1, Carbon::parse('2026-08-10'), 'cancelled');
    anlOrder(2, Carbon::parse('2026-08-11'), 'refunded');
    anlOrder(3, Carbon::parse('2026-08-12'), 'delivered');

    $buyers = anlRetention()->buyerIdsForMonth(Carbon::parse('2026-08-15'));

    expect($buyers)->toBe([3]);
});

it('counts distributors who have never bought anything', function (): void {
    $bought = Distributor::factory()->create();
    Distributor::factory()->count(2)->create();

    anlOrder((int) $bought->id, Carbon::parse('2026-08-10'));

    $shape = anlRetention()->baseShape(Carbon::parse('2026-08-20'));

    expect($shape['total'])->toBe(3)
        ->and($shape['never_bought'])->toBe(2)
        ->and($shape['bought_this_month'])->toBe(1);
});

it('ranks the window by BV and reports no rank or earnings', function (): void {
    $big = Distributor::factory()->create();
    $small = Distributor::factory()->create();

    $from = Carbon::parse('2026-08-01')->startOfDay();
    $to = Carbon::parse('2026-08-31')->endOfDay();

    DB::table('bv_ledger_entries')->insert([
        ['distributor_id' => $big->id, 'order_id' => anlOrder((int) $big->id, Carbon::parse('2026-08-05')), 'bv_paise' => 500000, 'type' => 'accrual', 'effective_at' => Carbon::parse('2026-08-05')],
        ['distributor_id' => $small->id, 'order_id' => anlOrder((int) $small->id, Carbon::parse('2026-08-06')), 'bv_paise' => 100000, 'type' => 'accrual', 'effective_at' => Carbon::parse('2026-08-06')],
        // Outside the window entirely.
        ['distributor_id' => $small->id, 'order_id' => anlOrder((int) $small->id, Carbon::parse('2026-07-06')), 'bv_paise' => 9900000, 'type' => 'accrual', 'effective_at' => Carbon::parse('2026-07-06')],
    ]);

    $rows = anlRetention()->topByVolume($from, $to);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['distributor_id'])->toBe((int) $big->id)
        ->and($rows[0]['bv_paise'])->toBe(500000)
        // The July entry is nine times larger and must not leak in.
        ->and($rows[1]['bv_paise'])->toBe(100000)
        // An admin league table carrying earnings is a recruitment slide
        // waiting to happen (hard rule 3).
        ->and($rows[0])->not->toHaveKeys(['rank', 'earnings', 'projected']);
});

// ─── the page ────────────────────────────────────────────────────────────────

it('renders for an admin and is closed to a distributor', function (): void {
    $this->actingAs(anlAdmin())->get('/admin/analytics')->assertOk()->assertSee('Analytics');

    // A distributor carries no admin role at all, so the whole area is closed.
    $this->actingAs(User::factory()->create())->get('/admin/analytics')->assertForbidden();
});

it('falls back to the default window when the query string is malformed', function (): void {
    // A mistyped URL should not 500 a dashboard.
    $this->actingAs(anlAdmin())
        ->get('/admin/analytics?from=not-a-date&to=2026-13-45')
        ->assertOk();
});
