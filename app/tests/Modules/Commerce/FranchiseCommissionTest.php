<?php

declare(strict_types=1);

/**
 * Franchise programme — the fulfilment network and its 3% commission.
 *
 * FRN-001: the flag off leaves no trace — no routes, no checkout picker
 * FRN-002: a franchise code is never mistakable for an ADN
 * FRN-003: an application earns nothing and is not selectable until approved
 * FRN-004: commission is 3% of fulfilled PRODUCT value — not GST, not shipping
 * FRN-005: only delivered orders count; paid-but-undelivered does not
 * FRN-006: the run is idempotent — a second run does not double-credit
 * FRN-007: the company's own primary franchise earns nothing
 * FRN-008: a per-franchise rate override beats the plan rate and is snapshotted
 * FRN-009: a suspended franchise disappears from the checkout picker
 * FRN-010: the commission credits the operator's wallet against the result row
 * FRN-011: admin screens render and an approval is audit-logged
 *
 * Added after the 2026-08-17 compliance review:
 * FRN-012: choosing a franchise never changes who the sale is attributed to
 * FRN-013: an order refunded inside its return window never reaches the calculation
 * FRN-014: every order behind a commission is recorded, not just the total
 * FRN-015: a franchise credit is actually paid out, not just swept
 */

use App\Modules\Commerce\Models\Franchise;
use App\Modules\Commerce\Services\FranchiseCodeGenerator;
use App\Modules\Compensation\Models\FranchiseCommissionResult;
use App\Modules\Compensation\Services\FranchiseCommissionService;
use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\FranchiseFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

// ─── helpers ─────────────────────────────────────────────────────────────────

function frnEnable(): void
{
    Feature::for(null)->activate(FranchiseFeature::class);
}

/** @param  array<string, mixed>  $overrides */
function frnFranchise(array $overrides = []): Franchise
{
    $operator = $overrides['operator'] ?? Distributor::factory()->create();

    return Franchise::create(array_merge([
        'code' => app(FranchiseCodeGenerator::class)->generate(),
        'name' => 'Sangareddy Pickup Point',
        'operator_distributor_id' => $operator instanceof Distributor ? $operator->id : null,
        'is_company_primary' => false,
        'district' => 'Sangareddy',
        'state' => 'Telangana',
        'pincode' => '502001',
        'status' => Franchise::STATUS_ACTIVE,
    ], array_diff_key($overrides, ['operator' => null])));
}

/**
 * An order handed over through a franchise, built the way CheckoutService
 * actually builds one.
 *
 * Catalogue prices are GST-INCLUSIVE: `Cart::totalPaise()` returns the subtotal
 * unchanged and `Cart::gstPaise()` extracts the tax out of it, so
 * `total = subtotal − discount + shipping` with no GST added on top. A fixture
 * that added GST would let a test "prove" GST exclusion against a data shape
 * production never produces.
 *
 * `$subtotalPaise` is therefore the gross, tax-inclusive figure.
 */
function frnOrder(Franchise $franchise, string $deliveredAt, int $subtotalPaise, string $status = 'delivered', int $discountPaise = 0): void
{
    static $sequence = 0;
    $sequence++;

    // 18% GST extracted out of a tax-inclusive line, as Cart::gstPaise() does.
    $gstPaise = (int) round($subtotalPaise * 1800 / (10000 + 1800));
    $shippingPaise = 6_000;

    DB::table('orders')->insert([
        'order_no' => 'ORD-FRN-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
        'customer_id' => 1,
        'franchise_id' => $franchise->id,
        'attribution_source' => 'direct',
        'payment_method' => 'online',
        'status' => $status,
        'subtotal_paise' => $subtotalPaise,
        'discount_paise' => $discountPaise,
        'gst_paise' => $gstPaise,
        'shipping_paise' => $shippingPaise,
        'total_paise' => $subtotalPaise - $discountPaise + $shippingPaise,
        'idempotency_key' => 'frn-'.$sequence.'-'.uniqid(),
        'delivered_at' => Carbon::parse($deliveredAt),
        'paid_at' => Carbon::parse($deliveredAt),
        'created_at' => Carbon::parse($deliveredAt),
        'updated_at' => Carbon::parse($deliveredAt),
    ]);
}

/** The month a given delivery date is counted in — 30 days on. */
function frnCountedMonth(string $deliveredAt): Carbon
{
    return Carbon::parse($deliveredAt)->addDays(30)->startOfMonth();
}

function frnStaff(string $role = 'admin'): User
{
    $user = User::create([
        'full_name' => 'Franchise Admin',
        'email' => 'frn-'.uniqid().'@test.com',
        'phone_e164' => '+91'.random_int(7000000000, 9999999999),
        'password_hash' => bcrypt('x'),
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

/** @return array<string, int> */
function frnRun(?Carbon $month = null): array
{
    // Orders in these fixtures are delivered 31 days ago, so their return
    // window closed yesterday — this month.
    return app(FranchiseCommissionService::class)->runForMonth($month ?? Carbon::now()->startOfMonth());
}

// ─── tests ───────────────────────────────────────────────────────────────────

it('FRN-001: the flag off leaves no trace — no routes, no checkout picker', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $staff = frnStaff();

    // 404 rather than 403: an unlaunched programme should not confirm it exists.
    $this->actingAs($staff)->get(route('admin.commerce.franchises.index'))->assertNotFound();
    $this->actingAs($staff)->get(route('admin.commerce.franchises.report'))->assertNotFound();

    frnEnable();

    $this->actingAs($staff)->get(route('admin.commerce.franchises.index'))->assertOk();
});

it('FRN-002: a franchise code is never mistakable for an ADN', function () {
    $code = app(FranchiseCodeGenerator::class)->generate();

    // ADNs are nine digits. A franchise code has a prefix and letters, so no
    // reader — and no regex — can confuse the two.
    expect($code)->toMatch('/^FR-[2-9A-HJ-NP-Z]{5}$/')
        ->and($code)->not->toMatch('/^\d{9}$/');
});

it('FRN-003: an application earns nothing and is not selectable until approved', function () {
    frnEnable();

    $franchise = frnFranchise(['status' => Franchise::STATUS_PENDING]);
    frnOrder($franchise, '-31 days', 10_00_000);

    expect(Franchise::selectable()->count())->toBe(0)
        ->and($franchise->earnsCommission())->toBeFalse();

    $summary = frnRun();

    expect($summary['credited'])->toBe(0)
        ->and(FranchiseCommissionResult::count())->toBe(0);
});

it('FRN-004: commission is 3% of fulfilled PRODUCT value — not GST, not shipping', function () {
    frnEnable();

    $franchise = frnFranchise();
    // ₹10,000 tax-INCLUSIVE, ₹500 discount. The GST inside that line is
    // ₹10,000 × 18/118 = ₹1,525.42, so the net product value is
    // 10,00,000 − 1,52,542 − 50,000 = 7,97,458 paise. Paying 3% of the
    // tax-inclusive figure instead would pay ~3.54% of the real product value.
    frnOrder($franchise, '-31 days', 10_00_000, 'delivered', 50_000);

    frnRun();

    $result = FranchiseCommissionResult::firstOrFail();

    expect($result->base_paise)->toBe(7_97_458)
        ->and($result->rate_bp)->toBe(300)
        ->and($result->gross_paise)->toBe((int) floor(7_97_458 * 300 / 10_000));
});

it('FRN-005: only delivered orders count; paid-but-undelivered does not', function () {
    frnEnable();

    $franchise = frnFranchise();
    frnOrder($franchise, '-31 days', 10_00_000, 'delivered');
    frnOrder($franchise, '-31 days', 10_00_000, 'paid');
    frnOrder($franchise, '-31 days', 10_00_000, 'cancelled');
    frnOrder($franchise, '-31 days', 10_00_000, 'refunded');

    frnRun();

    $result = FranchiseCommissionResult::firstOrFail();

    // The franchise is paid for handing goods over. An order on the shelf has
    // earned nothing yet, and a cancelled or refunded one never will.
    expect($result->order_count)->toBe(1)
        ->and($result->base_paise)->toBe(10_00_000 - (int) round(10_00_000 * 1800 / 11800));
});

it('FRN-006: the run is idempotent — a second run does not double-credit', function () {
    frnEnable();

    $franchise = frnFranchise();
    frnOrder($franchise, '-31 days', 10_00_000);

    frnRun();
    $second = frnRun();

    expect($second['credited'])->toBe(0)
        ->and(FranchiseCommissionResult::count())->toBe(1)
        ->and(DB::table('wallet_ledger_entries')->where('type', 'franchise_credit')->count())->toBe(1);
});

it('FRN-007: the company’s own primary franchise earns nothing', function () {
    frnEnable();

    $franchise = frnFranchise([
        'is_company_primary' => true,
        'operator' => null,
        'operator_distributor_id' => null,
    ]);
    frnOrder($franchise, '-31 days', 50_00_000);

    $summary = frnRun();

    // Paying the company a commission out of its own revenue is a bookkeeping
    // fiction, and there is no operator to pay in any case.
    expect($summary['credited'])->toBe(0)
        ->and($summary['skipped_no_operator'])->toBe(1)
        ->and(FranchiseCommissionResult::count())->toBe(0);
});

it('FRN-008: a per-franchise rate override beats the plan rate and is snapshotted', function () {
    frnEnable();

    $franchise = frnFranchise(['commission_rate_bp' => 500]);
    frnOrder($franchise, '-31 days', 10_00_000);

    frnRun();

    $result = FranchiseCommissionResult::firstOrFail();

    $netBase = 10_00_000 - (int) round(10_00_000 * 1800 / 11800);

    expect($result->rate_bp)->toBe(500)
        ->and($result->gross_paise)->toBe((int) floor($netBase * 500 / 10_000));

    // Changing the plan rate afterwards must not restate what was paid.
    DB::table('settings')->updateOrInsert(['key' => 'comp.franchise.rate_bp'], ['value' => '900', 'updated_at' => now()]);

    expect($result->fresh()->rate_bp)->toBe(500);
});

it('FRN-009: a suspended franchise disappears from the checkout picker', function () {
    frnEnable();

    $franchise = frnFranchise();
    expect(Franchise::selectable()->count())->toBe(1);

    $franchise->update(['status' => Franchise::STATUS_SUSPENDED]);

    // A buyer must never be offered a collection point that cannot hand them
    // their order.
    expect(Franchise::selectable()->count())->toBe(0);
});

it('FRN-010: the commission credits the operator’s wallet against the result row', function () {
    frnEnable();

    $operator = Distributor::factory()->create();
    $franchise = frnFranchise(['operator' => $operator]);
    frnOrder($franchise, '-31 days', 10_00_000);

    frnRun();

    $result = FranchiseCommissionResult::firstOrFail();

    $entry = DB::table('wallet_ledger_entries')
        ->where('type', 'franchise_credit')
        ->where('distributor_id', $operator->id)
        ->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->amount_paise)->toBe($result->gross_paise)
        ->and((int) $entry->reference_id)->toBe($result->id);
});

it('FRN-011: admin screens render and an approval is audit-logged', function () {
    frnEnable();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $staff = frnStaff('admin-compliance');
    $franchise = frnFranchise(['status' => Franchise::STATUS_PENDING]);

    $this->actingAs($staff)->get(route('admin.commerce.franchises.index'))->assertOk()
        ->assertSee($franchise->code, false);
    $this->actingAs($staff)->get(route('admin.commerce.franchises.create'))->assertOk();
    $this->actingAs($staff)->get(route('admin.commerce.franchises.edit', $franchise->id))->assertOk();
    $this->actingAs($staff)->get(route('admin.commerce.franchises.report'))->assertOk();

    $this->actingAs($staff)->post(route('admin.commerce.franchises.approve', $franchise->id))->assertRedirect();

    expect($franchise->fresh()->status)->toBe(Franchise::STATUS_ACTIVE)
        ->and(AuditLog::where('action', 'franchise.approved')->where('subject_id', $franchise->id)->exists())->toBeTrue();
});

it('FRN-012: choosing a franchise never changes who the sale is attributed to', function () {
    frnEnable();

    $seller = Distributor::factory()->create();
    $franchise = frnFranchise();

    DB::table('orders')->insert([
        'order_no' => 'ORD-FRN-ATTR',
        'customer_id' => 1,
        'attributed_distributor_id' => $seller->id,
        'franchise_id' => $franchise->id,
        'attribution_source' => 'cookie',
        'payment_method' => 'online',
        'status' => 'delivered',
        'subtotal_paise' => 10_00_000,
        'gst_paise' => 1_52_542,
        'idempotency_key' => 'frn-attr-'.uniqid(),
        'delivered_at' => Carbon::now()->subDays(31),
        'created_at' => Carbon::now()->subDays(31),
        'updated_at' => Carbon::now()->subDays(31),
    ]);

    frnRun();

    // The most compliance-critical property of the whole feature: a collection
    // point changes where goods are handed over and nothing about who the sale
    // belongs to. The franchise operator is paid; the seller keeps the sale.
    $order = DB::table('orders')->where('order_no', 'ORD-FRN-ATTR')->first();

    expect((int) $order->attributed_distributor_id)->toBe($seller->id)
        ->and((int) $order->franchise_id)->toBe($franchise->id)
        ->and($seller->id)->not->toBe($franchise->operator_distributor_id);
});

it('FRN-013: an order refunded inside its return window never reaches the calculation', function () {
    frnEnable();

    $franchise = frnFranchise();

    // Delivered 10 days ago: its 30-day window is still open, so this month's
    // run must not see it at all. Waiting for the window to close is what
    // removes the need for any clawback (R-23) — the commission is never paid
    // on an order that can still come back.
    frnOrder($franchise, '-10 days', 10_00_000);

    $summary = frnRun();

    expect($summary['credited'])->toBe(0)
        ->and($summary['skipped_no_orders'])->toBe(1);

    // Refunded before its window closed, it is gone from the set for good.
    DB::table('orders')->where('franchise_id', $franchise->id)->update(['status' => 'refunded']);

    expect(app(FranchiseCommissionService::class)->settledOrdersForMonth(
        $franchise->id,
        Carbon::now()->addDays(30)->startOfMonth(),
        Carbon::now()->addDays(30)->endOfMonth(),
    ))->toBe([]);
});

it('FRN-014: every order behind a commission is recorded, not just the total', function () {
    frnEnable();

    $franchise = frnFranchise();
    frnOrder($franchise, '-31 days', 10_00_000);
    frnOrder($franchise, '-32 days', 5_00_000);

    frnRun();

    $result = FranchiseCommissionResult::firstOrFail();
    $traced = DB::table('franchise_commission_result_orders')->where('result_id', $result->id)->get();

    // R-22: an aggregate nobody can decompose is not a trace. Re-running the
    // query a year later would not reproduce it once those orders change state.
    expect($traced)->toHaveCount(2)
        ->and((int) $traced->sum('base_paise'))->toBe($result->base_paise);
});

it('FRN-015: a franchise credit is actually paid out, not just swept', function () {
    // Runs the real monthly payout batch. The previous version of this test
    // asserted that `franchise_credit` was in GROUP_D_TYPES — which is the
    // CAUSE of the bug, not the fix: reverting $grossD to `adc_credit` alone
    // left it passing while a franchise-only earner was paid nothing.
    frnEnable();

    $franchise = frnFranchise();
    frnOrder($franchise, '-31 days', 10_00_000);
    frnRun();

    $operatorId = (int) $franchise->operator_distributor_id;

    $credit = (int) DB::table('wallet_ledger_entries')
        ->where('distributor_id', $operatorId)
        ->where('type', 'franchise_credit')
        ->sum('amount_paise');

    expect($credit)->toBeGreaterThan(0);

    // The operator must clear the payout eligibility gates or the batch skips
    // them for reasons unrelated to what this test is about.
    DB::table('distributors')->where('id', $operatorId)->update([
        'bank_account_enc' => 'stub',
        'bank_ifsc' => 'SBIN0000000',
        'status' => 'active',
    ]);
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $operatorId,
        'order_id' => 990001,
        'bv_paise' => 5_000 * 100,
        'type' => 'accrual',
        'effective_at' => Carbon::now()->subMonths(2),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $batch = app(\App\Modules\Compensation\Services\PayoutService::class)
        ->runMonthlyBatch(Carbon::now()->startOfMonth());

    $line = DB::table('payout_line_items')
        ->where('payout_batch_id', $batch->id)
        ->where('distributor_id', $operatorId)
        ->first();

    expect($line)->not->toBeNull()
        // The credit is in the gross the batch actually pays on. Without the
        // fix it was swept but excluded from the gross, so the entry was
        // marked paid with no matching debit — a phantom wallet balance no
        // later batch could clear.
        ->and((int) $line->gross_paise)->toBe($credit)
        // Exempt from the admin charge, like awards: it pays for fulfilment
        // work, not for a position in the plan.
        ->and((int) $line->admin_charge_paise)->toBe(0);

    // And the ledger balances: the sweep is matched by a debit.
    $swept = (int) DB::table('wallet_ledger_entries')
        ->where('distributor_id', $operatorId)
        ->where('type', 'franchise_credit')
        ->whereNotNull('swept_by_payout_batch_id')
        ->sum('amount_paise');

    expect($swept)->toBe($credit);
});
