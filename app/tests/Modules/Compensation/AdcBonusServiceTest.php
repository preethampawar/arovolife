<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Models\AdcBonusResult;
use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Compensation\Models\AreteCenterMember;
use App\Modules\Compensation\Models\WalletLedgerEntry;
use App\Modules\Compensation\Services\AreteDevelopmentCenterBonusService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

function makeActiveCenter(int $assignedDistributorId, string $name = 'Test Center'): AreteCenter
{
    return AreteCenter::create([
        'name' => $name,
        'location' => null,
        'assigned_distributor_id' => $assignedDistributorId,
        'status' => AreteCenter::STATUS_ACTIVE,
        'approved_at' => null,
        'notes' => null,
    ]);
}

/**
 * A paid order placed by $buyerId on $date. When $centerId is given the buyer
 * chose that centre as the collection point at checkout — the only thing the
 * ADC engine attributes BV by. Returns the order id.
 */
function seedAdcOrder(int $buyerId, ?int $centerId, string $date = '2026-06-15'): int
{
    $customer = Customer::create(['display_name' => 'Buyer']);

    $order = Order::create([
        'order_no' => 'ORD-ADC-'.random_int(100000, 999999),
        'customer_id' => $customer->id,
        'attributed_distributor_id' => $buyerId,
        'arete_center_id' => $centerId,
        'attribution_source' => 'logged_in',
        'payment_method' => Order::PAYMENT_ONLINE,
        'status' => Order::STATUS_PAID,
        'self_consumption' => true,
        'subtotal_paise' => 100000, 'gst_paise' => 0, 'discount_paise' => 0,
        'shipping_paise' => 0, 'total_paise' => 100000,
        'ship_name' => 'Buyer', 'ship_phone_e164' => '+919800000000',
        'ship_line1' => '1 St', 'ship_city' => 'Pune', 'ship_state' => 'MH', 'ship_pincode' => '411001',
        'placed_at' => $date.' 12:00:00', 'idempotency_key' => 'idem-'.uniqid(),
    ]);

    return $order->id;
}

/** Accrual BV for an order collected at $centerId (null = shipped, not collected). */
function seedCenterOrderBv(int $buyerId, ?int $centerId, int $bvPaise, string $date = '2026-06-15'): int
{
    $orderId = seedAdcOrder($buyerId, $centerId, $date);

    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $buyerId,
        'order_id' => $orderId,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => $date.' 12:00:00',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $orderId;
}

/** A refund reversal on an order's BV — what BvLedgerService::reverse() writes. */
function seedAdcOrderBvReversal(int $buyerId, int $orderId, int $bvPaise, string $date = '2026-06-20'): void
{
    DB::table('bv_ledger_entries')->insert([
        'distributor_id' => $buyerId,
        'order_id' => $orderId,
        'bv_paise' => -abs($bvPaise),
        'type' => 'reversal',
        'effective_at' => $date.' 12:00:00',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

it('credits 3% of the BV collected at the centre to the assigned distributor', function (): void {
    $assignee = Distributor::factory()->create();
    $buyer = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    seedCenterOrderBv($buyer->id, $center->id, 1_000_000); // 1,000 BV → gross = 30,000 paise = ₹300

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['credited'])->toBe(1)
        ->and($result['skipped_no_bv'])->toBe(0);

    $bonus = AdcBonusResult::where('center_id', $center->id)->first();
    expect($bonus)->not->toBeNull();
    expect($bonus->total_attributed_bv_paise)->toBe(1_000_000);
    expect($bonus->order_count)->toBe(1);
    expect($bonus->gross_paise)->toBe(30_000);                    // 3% of 1,000,000
    // Deductions are applied at payout time, not at credit time.
    expect($bonus->admin_charge_paise)->toBe(0);
    expect($bonus->tds_paise)->toBe(0);
    expect($bonus->net_paise)->toBe(30_000);
    expect($bonus->status)->toBe(AdcBonusResult::STATUS_CREDITED);

    $ledger = WalletLedgerEntry::where('distributor_id', $assignee->id)
        ->where('type', 'adc_credit')->first();
    expect($ledger)->not->toBeNull();
    expect($ledger->amount_paise)->toBe(30_000);
});

it('applies the monthly cap of ₹1,00,000 (10,000,000 paise)', function (): void {
    $assignee = Distributor::factory()->create();
    $buyer = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    // 1,000,000,000 paise → 3% = 30,000,000 paise → cap at 10,000,000
    seedCenterOrderBv($buyer->id, $center->id, 1_000_000_000);

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $svc->runForMonth($month);

    $bonus = AdcBonusResult::where('center_id', $center->id)->first();
    expect($bonus->gross_paise)->toBe(10_000_000); // capped at ₹1,00,000
    // Deductions deferred to payout time.
    expect($bonus->admin_charge_paise)->toBe(0);
    expect($bonus->tds_paise)->toBe(0);
    expect($bonus->net_paise)->toBe(10_000_000);
});

it('exempts ADC from the admin charge when the applies_to toggle is off', function (): void {
    DB::table('settings')->insert([
        'key' => 'comp.admin_charge.applies_to_adc', 'value' => 'false', 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $assignee = Distributor::factory()->create();
    $buyer = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    seedCenterOrderBv($buyer->id, $center->id, 1_000_000); // gross = 30,000

    app(AreteDevelopmentCenterBonusService::class)->runForMonth($month);

    $bonus = AdcBonusResult::where('center_id', $center->id)->first();
    // Deductions are deferred to payout time; engine always stores 0.
    expect($bonus->admin_charge_paise)->toBe(0);
    expect($bonus->tds_paise)->toBe(0);
    expect($bonus->net_paise)->toBe(30_000);
});

it('is idempotent — second run skips already-credited center', function (): void {
    $assignee = Distributor::factory()->create();
    $buyer = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    seedCenterOrderBv($buyer->id, $center->id, 1_000_000); // gross = 30,000

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $first = $svc->runForMonth($month);
    $second = $svc->runForMonth($month); // re-run must not reprocess

    $bonus = AdcBonusResult::where('center_id', $center->id)->first();
    expect($bonus->net_paise)->toBe(30_000);
    expect($bonus->status)->toBe(AdcBonusResult::STATUS_CREDITED);
    expect($first['credited'])->toBe(1);
    expect($second['credited'])->toBe(0);                              // idempotent: already credited
    expect(AdcBonusResult::where('center_id', $center->id)->count())->toBe(1);
    expect(WalletLedgerEntry::where('type', 'adc_credit')->where('distributor_id', $assignee->id)->count())->toBe(1); // credited gross
});

it('skips a centre with no collection orders in the month', function (): void {
    $assignee = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    makeActiveCenter($assignee->id);
    // No orders seeded

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['credited'])->toBe(0)
        ->and($result['skipped_no_bv'])->toBe(1);

    expect(AdcBonusResult::count())->toBe(0);
    expect(WalletLedgerEntry::where('type', 'adc_credit')->count())->toBe(0);
});

it('ignores BV from orders that were shipped rather than collected at a centre', function (): void {
    $assignee = Distributor::factory()->create();
    $buyer = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    makeActiveCenter($assignee->id);
    seedCenterOrderBv($buyer->id, null, 1_000_000); // no arete_center_id on the order

    $result = app(AreteDevelopmentCenterBonusService::class)->runForMonth($month);

    expect($result['credited'])->toBe(0)
        ->and($result['skipped_no_bv'])->toBe(1);
    expect(AdcBonusResult::count())->toBe(0);
});

it('excludes BV outside the month window', function (): void {
    $assignee = Distributor::factory()->create();
    $buyer = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    seedCenterOrderBv($buyer->id, $center->id, 500_000, '2026-05-31'); // prior month — should be excluded
    seedCenterOrderBv($buyer->id, $center->id, 200_000, '2026-07-01'); // next month — should be excluded

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['credited'])->toBe(0)
        ->and($result['skipped_no_bv'])->toBe(1);
});

it('attributes BV by the collection point chosen at checkout, not by centre membership', function (): void {
    $assigneeA = Distributor::factory()->create();
    $assigneeB = Distributor::factory()->create();
    $buyer = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $centerA = makeActiveCenter($assigneeA->id, 'Centre A');
    $centerB = makeActiveCenter($assigneeB->id, 'Centre B');

    // The buyer is on Centre A's member roll but collected this order at Centre B.
    AreteCenterMember::create([
        'center_id' => $centerA->id,
        'distributor_id' => $buyer->id,
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);
    seedCenterOrderBv($buyer->id, $centerB->id, 1_000_000);

    $result = app(AreteDevelopmentCenterBonusService::class)->runForMonth($month);

    expect($result['credited'])->toBe(1)
        ->and($result['skipped_no_bv'])->toBe(1);

    expect(AdcBonusResult::where('center_id', $centerA->id)->exists())->toBeFalse();
    expect(AdcBonusResult::where('center_id', $centerB->id)->value('gross_paise'))->toBe(30_000);
    expect(WalletLedgerEntry::where('type', 'adc_credit')->where('distributor_id', $assigneeA->id)->exists())->toBeFalse();
    expect(WalletLedgerEntry::where('type', 'adc_credit')->where('distributor_id', $assigneeB->id)->value('amount_paise'))->toBe(30_000);
});

it('is idempotent — re-running does not double-credit', function (): void {
    $assignee = Distributor::factory()->create();
    $buyer = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    seedCenterOrderBv($buyer->id, $center->id, 1_000_000);

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $svc->runForMonth($month);
    $svc->runForMonth($month);

    expect(AdcBonusResult::where('center_id', $center->id)->count())->toBe(1);
    expect(WalletLedgerEntry::where('distributor_id', $assignee->id)
        ->where('type', 'adc_credit')->count())->toBe(1);
});

it('aggregates BV and order count across multiple collection orders', function (): void {
    $assignee = Distributor::factory()->create();
    $buyer1 = Distributor::factory()->create();
    $buyer2 = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    seedCenterOrderBv($buyer1->id, $center->id, 500_000);
    seedCenterOrderBv($buyer2->id, $center->id, 300_000);

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $svc->runForMonth($month);

    $bonus = AdcBonusResult::where('center_id', $center->id)->first();
    expect($bonus->total_attributed_bv_paise)->toBe(800_000);
    expect($bonus->order_count)->toBe(2);
    expect($bonus->gross_paise)->toBe((int) floor(800_000 * 0.03)); // 24,000
});

it('pays on the net attributed BV after a partial refund in the month', function (): void {
    $assignee = Distributor::factory()->create();
    $buyer = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    $orderId = seedCenterOrderBv($buyer->id, $center->id, 1_000_000, '2026-06-10');
    seedAdcOrderBvReversal($buyer->id, $orderId, 400_000, '2026-06-20'); // net 600,000

    $result = app(AreteDevelopmentCenterBonusService::class)->runForMonth($month);

    expect($result['credited'])->toBe(1)
        ->and($result['skipped_no_bv'])->toBe(0);

    $bonus = AdcBonusResult::where('center_id', $center->id)->first();
    expect($bonus->total_attributed_bv_paise)->toBe(600_000);   // net, not the 1,000,000 accrual
    expect($bonus->gross_paise)->toBe(18_000);                  // 3% of the net
    expect($bonus->net_paise)->toBe(18_000);

    expect(WalletLedgerEntry::where('distributor_id', $assignee->id)
        ->where('type', 'adc_credit')->value('amount_paise'))->toBe(18_000);
});

it('skips a centre whose attributed BV nets below zero after refunds', function (): void {
    $assignee = Distributor::factory()->create();
    $buyer = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    // A May order refunded in June: the accrual falls outside the window, the reversal inside.
    $orderId = seedCenterOrderBv($buyer->id, $center->id, 500_000, '2026-05-20');
    seedAdcOrderBvReversal($buyer->id, $orderId, 500_000, '2026-06-05');
    seedCenterOrderBv($buyer->id, $center->id, 300_000, '2026-06-10'); // net −200,000

    $result = app(AreteDevelopmentCenterBonusService::class)->runForMonth($month);

    // A refunded-out centre is counted apart from one that never sold.
    expect($result['credited'])->toBe(0)
        ->and($result['skipped_net_negative'])->toBe(1)
        ->and($result['skipped_no_bv'])->toBe(0)
        ->and($result['total_net_paise'])->toBe(0);

    // No result row, no wallet credit, and above all no negative gross.
    expect(AdcBonusResult::count())->toBe(0);
    expect(WalletLedgerEntry::where('type', 'adc_credit')->count())->toBe(0);
});

it('counts a centre whose refunds exactly cancel its sales as no BV', function (): void {
    $assignee = Distributor::factory()->create();
    $buyer = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id);
    $orderId = seedCenterOrderBv($buyer->id, $center->id, 300_000, '2026-06-10');
    seedAdcOrderBvReversal($buyer->id, $orderId, 300_000, '2026-06-20'); // net 0

    $result = app(AreteDevelopmentCenterBonusService::class)->runForMonth($month);

    expect($result['credited'])->toBe(0)
        ->and($result['skipped_no_bv'])->toBe(1)
        ->and($result['skipped_net_negative'])->toBe(0)
        ->and($result['total_net_paise'])->toBe(0);

    expect(AdcBonusResult::count())->toBe(0);
    expect(WalletLedgerEntry::where('type', 'adc_credit')->count())->toBe(0);
});

it('skips inactive centers', function (): void {
    $assignee = Distributor::factory()->create();
    $buyer = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = AreteCenter::create([
        'name' => 'Inactive Center',
        'location' => null,
        'assigned_distributor_id' => $assignee->id,
        'status' => AreteCenter::STATUS_INACTIVE,
        'approved_at' => null,
        'notes' => null,
    ]);
    seedCenterOrderBv($buyer->id, $center->id, 1_000_000);

    $svc = app(AreteDevelopmentCenterBonusService::class);
    $result = $svc->runForMonth($month);

    expect($result['credited'])->toBe(0);
    expect(AdcBonusResult::count())->toBe(0);
});

it('applies a per-center monthly cap override below the standard cap (phase penalty)', function (): void {
    $assignee = Distributor::factory()->create();
    $buyer = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id, 'Penalised Center');
    $center->update(['monthly_cap_override_paise' => 2_000_000]); // ₹20,000
    // 1,000,000,000 paise BV → 3% = 30,000,000 paise, standard cap 10,000,000
    seedCenterOrderBv($buyer->id, $center->id, 1_000_000_000);

    app(AreteDevelopmentCenterBonusService::class)->runForMonth($month);

    $bonus = AdcBonusResult::where('center_id', $center->id)->first();
    expect($bonus->gross_paise)->toBe(2_000_000);

    $ledger = WalletLedgerEntry::where('distributor_id', $assignee->id)
        ->where('type', 'adc_credit')->first();
    expect($ledger->amount_paise)->toBe(2_000_000);
});

it('never lets the per-center override raise the standard cap', function (): void {
    $assignee = Distributor::factory()->create();
    $buyer = Distributor::factory()->create();
    $month = Carbon::parse('2026-06-01');

    $center = makeActiveCenter($assignee->id, 'Over-eager Center');
    $center->update(['monthly_cap_override_paise' => 50_000_000]); // ₹5,00,000 — above the plan cap
    seedCenterOrderBv($buyer->id, $center->id, 1_000_000_000);

    app(AreteDevelopmentCenterBonusService::class)->runForMonth($month);

    $bonus = AdcBonusResult::where('center_id', $center->id)->first();
    expect($bonus->gross_paise)->toBe(10_000_000); // still the ₹1,00,000 plan cap
});
