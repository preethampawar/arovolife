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
 * An order fulfilled by a franchise. `subtotal` and `discount` set the
 * commission base; `gst` and `shipping` are deliberately non-zero so a test
 * would fail if either leaked into it.
 */
function frnOrder(Franchise $franchise, string $deliveredAt, int $subtotalPaise, string $status = 'delivered', int $discountPaise = 0): void
{
    static $sequence = 0;
    $sequence++;

    DB::table('orders')->insert([
        'order_no' => 'ORD-FRN-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
        'customer_id' => 1,
        'franchise_id' => $franchise->id,
        'attribution_source' => 'direct',
        'payment_method' => 'online',
        'status' => $status,
        'subtotal_paise' => $subtotalPaise,
        'discount_paise' => $discountPaise,
        'gst_paise' => 90_000,
        'shipping_paise' => 6_000,
        'total_paise' => $subtotalPaise - $discountPaise + 96_000,
        'idempotency_key' => 'frn-'.$sequence.'-'.uniqid(),
        'delivered_at' => Carbon::parse($deliveredAt),
        'paid_at' => Carbon::parse($deliveredAt),
        'created_at' => Carbon::parse($deliveredAt),
        'updated_at' => Carbon::parse($deliveredAt),
    ]);
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
    frnOrder($franchise, 'now', 10_00_000);

    expect(Franchise::selectable()->count())->toBe(0)
        ->and($franchise->earnsCommission())->toBeFalse();

    $summary = frnRun();

    expect($summary['credited'])->toBe(0)
        ->and(FranchiseCommissionResult::count())->toBe(0);
});

it('FRN-004: commission is 3% of fulfilled PRODUCT value — not GST, not shipping', function () {
    frnEnable();

    $franchise = frnFranchise();
    // ₹10,000 product value, ₹500 discount → base ₹9,500. GST and shipping on
    // the row are non-zero and must not appear in the base: one is tax
    // collected for the government, the other a pass-through cost.
    frnOrder($franchise, 'now', 10_00_000, 'delivered', 50_000);

    frnRun();

    $result = FranchiseCommissionResult::firstOrFail();

    expect($result->base_paise)->toBe(9_50_000)
        ->and($result->rate_bp)->toBe(300)
        ->and($result->gross_paise)->toBe(28_500);
});

it('FRN-005: only delivered orders count; paid-but-undelivered does not', function () {
    frnEnable();

    $franchise = frnFranchise();
    frnOrder($franchise, 'now', 10_00_000, 'delivered');
    frnOrder($franchise, 'now', 10_00_000, 'paid');
    frnOrder($franchise, 'now', 10_00_000, 'cancelled');
    frnOrder($franchise, 'now', 10_00_000, 'refunded');

    frnRun();

    $result = FranchiseCommissionResult::firstOrFail();

    // The franchise is paid for handing goods over. An order on the shelf has
    // earned nothing yet, and a cancelled or refunded one never will.
    expect($result->order_count)->toBe(1)
        ->and($result->base_paise)->toBe(10_00_000);
});

it('FRN-006: the run is idempotent — a second run does not double-credit', function () {
    frnEnable();

    $franchise = frnFranchise();
    frnOrder($franchise, 'now', 10_00_000);

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
    frnOrder($franchise, 'now', 50_00_000);

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
    frnOrder($franchise, 'now', 10_00_000);

    frnRun();

    $result = FranchiseCommissionResult::firstOrFail();

    expect($result->rate_bp)->toBe(500)
        ->and($result->gross_paise)->toBe(50_000);

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
    frnOrder($franchise, 'now', 10_00_000);

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
