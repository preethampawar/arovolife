<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\PayoutLineItem;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\DistributorIdCardStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    // The title ladder is read from gsb_slabs, not a constant.
    seedCompensationPlanTables();
});

/** Give a distributor a net personal BV via a ledger accrual (BV × 100 paise). */
function seedIdCardPersonalBv(Distributor $distributor, int $bvPaise): void
{
    static $orderId = 800000;
    BvLedgerEntry::create([
        'distributor_id' => $distributor->id,
        'order_id' => $orderId++,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);
}

/** Settle money to a distributor's bank via a transferred payout line item. */
function seedIdCardPayout(Distributor $distributor, int $netPaise, string $status): void
{
    static $batchId = 900000;
    PayoutLineItem::create([
        'payout_batch_id' => $batchId++,
        'distributor_id' => $distributor->id,
        'gross_paise' => $netPaise,
        'admin_charge_paise' => 0,
        'tds_paise' => 0,
        'wallet_balance_paise' => 0,
        'repurchase_deduction_paise' => 0,
        'net_transferred_paise' => $netPaise,
        'status' => $status,
    ]);
}

it('shows the title the viewer holds on the personal purchase ladder', function (): void {
    $me = Distributor::factory()->create();
    seedIdCardPersonalBv($me, 1_600_000);   // 16,000 BV → Wholesaler (15,000 rung)

    $this->actingAs($me->user);

    expect(app(DistributorIdCardStats::class)->full($me)['personal_sales_title'])
        ->toBe('Wholesaler');
});

it('shows no title (—/null) below the first rung of the ladder', function (): void {
    $me = Distributor::factory()->create();
    seedIdCardPersonalBv($me, 100_000);     // 1,000 BV → below the 3,000 BV Retailer rung

    $this->actingAs($me->user);

    expect(app(DistributorIdCardStats::class)->full($me)['personal_sales_title'])->toBeNull();
});

it('never exposes another distributor\'s title (own data only)', function (): void {
    $me = Distributor::factory()->create();
    $other = Distributor::factory()->create();
    seedIdCardPersonalBv($other, 1_600_000);

    // Authenticated as $me but requesting $other's card → title hidden.
    $this->actingAs($me->user);
    expect(app(DistributorIdCardStats::class)->full($other)['personal_sales_title'])->toBeNull();
});

it('totals only the payouts that actually reached the bank', function (): void {
    $me = Distributor::factory()->create();
    seedIdCardPayout($me, 120_000, PayoutLineItem::STATUS_TRANSFERRED);   // ₹1,200.00
    seedIdCardPayout($me, 45_050, PayoutLineItem::STATUS_TRANSFERRED);    // ₹450.50
    seedIdCardPayout($me, 999_999, PayoutLineItem::STATUS_PENDING);       // not settled
    seedIdCardPayout($me, 999_999, PayoutLineItem::STATUS_FAILED);        // never left

    $this->actingAs($me->user);

    expect(app(DistributorIdCardStats::class)->full($me)['total_withdrawal_income'])
        ->toBe('₹1,650.50');
});

it('shows no withdrawal income before the first transfer clears', function (): void {
    $me = Distributor::factory()->create();
    seedIdCardPayout($me, 500_000, PayoutLineItem::STATUS_PENDING);

    $this->actingAs($me->user);

    expect(app(DistributorIdCardStats::class)->full($me)['total_withdrawal_income'])->toBeNull();
});

it('never exposes another distributor\'s withdrawal income (own data only)', function (): void {
    $me = Distributor::factory()->create();
    $other = Distributor::factory()->create();
    seedIdCardPayout($other, 500_000, PayoutLineItem::STATUS_TRANSFERRED);

    $this->actingAs($me->user);
    expect(app(DistributorIdCardStats::class)->full($other)['total_withdrawal_income'])->toBeNull();
});

it('logs a warning when the downline-visibility switch cannot be read', function (): void {
    Schema::drop('settings');

    Log::shouldReceive('warning')->once()
        ->with(Mockery::pattern('/downlineStatsVisible/'), Mockery::type('array'));

    expect(app(DistributorIdCardStats::class)->downlineStatsVisible())->toBeFalse();
});

it('shows the purchase mark to staff on every admin Genos card while the switch is OFF', function (): void {
    $a = Distributor::factory()->create();
    seedIdCardPersonalBv($a, 60_000);   // 600 BV — on the gate
    $b = Distributor::factory()->create();

    $admin = User::factory()->create();
    Role::findOrCreate('admin', 'web');
    $admin->assignRole('admin');

    $this->actingAs($admin->refresh());
    idCardStatsOnRoute('admin.tree.show');
    $stats = app(DistributorIdCardStats::class)->compactMany(
        Distributor::query()->with('user')->whereIn('id', [$a->id, $b->id])->get()
    );

    expect($stats[$a->id]['purchase_state'])->not->toBeNull()
        ->and($stats[$b->id]['purchase_state'])->not->toBeNull()
        ->and($stats[$a->id]['highest_rank'])->toBeNull()
        ->and($stats[$b->id]['highest_rank'])->toBeNull();
});

it('keeps the purchase mark off downline cards for a distributor while the switch is OFF', function (): void {
    $sponsor = Distributor::factory()->create();
    seedIdCardPersonalBv($sponsor, 60_000);
    $downline = Distributor::factory()->create(['sponsor_id' => $sponsor->id]);
    seedIdCardPersonalBv($downline, 10_000);

    $this->actingAs($sponsor->user);
    $stats = app(DistributorIdCardStats::class)->compactMany(
        Distributor::query()->with('user')->whereIn('id', [$sponsor->id, $downline->id])->get()
    );

    expect($stats[$sponsor->id]['purchase_state'])->not->toBeNull()
        ->and($stats[$downline->id]['purchase_state'])->toBeNull();
});

it('does not widen the purchase mark for staff outside the admin Genos', function (): void {
    $a = Distributor::factory()->create();
    seedIdCardPersonalBv($a, 60_000);

    $admin = User::factory()->create();
    Role::findOrCreate('admin', 'web');
    $admin->assignRole('admin');

    $this->actingAs($admin->refresh());
    idCardStatsOnRoute('dashboard');
    $stats = app(DistributorIdCardStats::class)->compactMany(
        Distributor::query()->with('user')->whereIn('id', [$a->id])->get()
    );

    expect($stats[$a->id]['purchase_state'])->toBeNull();
});

/** Bind a request resolved to the named route, as the HTTP kernel would. */
function idCardStatsOnRoute(string $name): void
{
    $route = app('router')->getRoutes()->getByName($name);
    $request = Request::create('/');
    $request->setRouteResolver(fn () => $route);
    app()->instance('request', $request);
}
