<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\Order;
use App\Modules\Compensation\Models\RepurchaseCycle;
use App\Modules\Compensation\Services\DTOs\RepurchaseCycleCard;
use App\Modules\Compensation\Services\RepurchaseCycleService;
use App\Modules\Compensation\Services\RepurchaseOrderNotice;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedCompensationPlanTables();
    $this->today = Carbon::parse('2026-09-28', 'Asia/Kolkata');
    $this->d = uiDistributor();
    // Anchor: first 600 BV reached on 10 Aug, so an obligation exists.
    uiPaidSelfOrder($this->d['id'], 60_000, Carbon::parse('2026-08-10 11:00'));
});

function rnCycle(int $distributorId, string $start, string $due, string $status, int $required = 60_000): RepurchaseCycle
{
    return RepurchaseCycle::create([
        'distributor_id' => $distributorId, 'cycle_start_date' => $start, 'due_date' => $due,
        'required_bv_paise' => $required, 'completed_bv_paise' => 0, 'status' => $status,
    ]);
}

it('bv_met when this order takes an active cycle over the requirement', function () {
    rnCycle($this->d['id'], '2026-09-10', '2026-10-09', RepurchaseCycle::STATUS_ACTIVE);
    uiPaidSelfOrder($this->d['id'], 30_000, Carbon::parse('2026-09-15 10:00'));
    $order = Order::find(uiPaidSelfOrder($this->d['id'], 30_000, Carbon::parse('2026-09-28 12:00')));

    $notice = app(RepurchaseOrderNotice::class)->for($order, $this->today);

    expect($notice['kind'])->toBe(RepurchaseOrderNotice::BV_MET)
        ->and($notice['message'])->toContain('Keep your repurchase wallet at ₹0 on 9 Oct 2026');
});

it('restored when a suspended cycle is met with the wallet at zero', function () {
    rnCycle($this->d['id'], '2026-08-21', '2026-09-20', RepurchaseCycle::STATUS_SUSPENDED);
    uiPaidSelfOrder($this->d['id'], 20_000, Carbon::parse('2026-09-01 10:00'));
    $order = Order::find(uiPaidSelfOrder($this->d['id'], 40_000, Carbon::parse('2026-09-28 12:00')));

    expect(app(RepurchaseOrderNotice::class)->for($order, $this->today))->toBe([
        'kind' => RepurchaseOrderNotice::RESTORED,
        'message' => 'Your repurchase requirement is met. Your bonus eligibility is restored from today, 28 Sep 2026.',
    ]);
});

it('gives no notice when the wallet is not zero on a suspended cycle', function () {
    rnCycle($this->d['id'], '2026-08-21', '2026-09-20', RepurchaseCycle::STATUS_SUSPENDED);
    uiRepurchaseWallet($this->d['id'], 5_000);
    $order = Order::find(uiPaidSelfOrder($this->d['id'], 60_000, Carbon::parse('2026-09-28 12:00')));
    expect(app(RepurchaseOrderNotice::class)->for($order, $this->today))->toBeNull();
});

it('gives no notice for an unpaid order, a customer order, or a cycle already met before this order', function () {
    rnCycle($this->d['id'], '2026-09-10', '2026-10-09', RepurchaseCycle::STATUS_ACTIVE);
    $notice = app(RepurchaseOrderNotice::class);

    $unpaid = Order::find(uiPaidSelfOrder($this->d['id'], 60_000, Carbon::parse('2026-09-28 12:00')));
    DB::table('orders')->where('id', $unpaid->id)->update(['paid_at' => null, 'status' => 'placed']);
    expect($notice->for($unpaid->fresh(), $this->today))->toBeNull();
    DB::table('bv_ledger_entries')->where('order_id', $unpaid->id)->delete();

    $customer = Order::find(uiPaidSelfOrder($this->d['id'], 60_000, Carbon::parse('2026-09-28 12:00')));
    DB::table('orders')->where('id', $customer->id)->update(['self_consumption' => false]);
    expect($notice->for($customer->fresh(), $this->today))->toBeNull();
    DB::table('bv_ledger_entries')->where('order_id', $customer->id)->delete();

    uiPaidSelfOrder($this->d['id'], 60_000, Carbon::parse('2026-09-20 10:00'));        // already met
    $extra = Order::find(uiPaidSelfOrder($this->d['id'], 10_000, Carbon::parse('2026-09-28 12:00')));
    expect($notice->for($extra, $this->today))->toBeNull();
});

it('a restored cycle really dates fulfilment today, so today is not forfeited', function () {
    $cycle = rnCycle($this->d['id'], '2026-08-21', '2026-09-20', RepurchaseCycle::STATUS_SUSPENDED);
    uiPaidSelfOrder($this->d['id'], 60_000, Carbon::parse('2026-09-28 12:00'));

    app(RepurchaseCycleService::class)->evaluate($this->d['id'], Carbon::parse('2026-09-28'));

    $resolved = RepurchaseCycle::where('distributor_id', $this->d['id'])->whereNotNull('fulfilled_on')->orderBy('id')->first();
    expect($resolved?->fulfilled_on?->toDateString())->toBe('2026-09-28');
    expect($resolved->forfeitedWindow()[1]->toDateString())->toBe('2026-09-27');
});

it('the confirmation popup shows once per order, for its owner', function () {
    DB::table('settings')->updateOrInsert(['key' => 'commerce.checkout.enabled'], ['value' => 'true', 'version' => 1, 'updated_at' => now()]);
    Feature::activate(RepurchaseEngineFeature::class);
    rnCycle($this->d['id'], now()->subDays(5)->toDateString(), now()->addDays(25)->toDateString(), RepurchaseCycle::STATUS_ACTIVE);
    $orderId = uiPaidSelfOrder($this->d['id'], 60_000, now());
    DB::table('orders')->where('id', $orderId)->update(['customer_id' => uiCustomerFor($this->d['user'], $this->d['id'])]);
    $orderNo = DB::table('orders')->where('id', $orderId)->value('order_no');

    $first = $this->actingAs($this->d['user'])->get(route('shop.confirmation', $orderNo))->assertOk()->getContent();
    $second = $this->actingAs($this->d['user'])->get(route('shop.confirmation', $orderNo))->assertOk()->getContent();

    expect($first)->toContain('data-repurchase-notice')
        ->and($second)->not->toContain('data-repurchase-notice');
});

it('the dashboard card shows the green check only for a running or completed cycle with BV met', function () {
    $render = function (string $status, int $completed): string {
        $cycle = new RepurchaseCycle([
            'distributor_id' => 1, 'cycle_start_date' => '2026-09-10', 'due_date' => '2026-10-09',
            'required_bv_paise' => 60_000, 'completed_bv_paise' => $completed, 'status' => $status,
        ]);
        $card = RepurchaseCycleCard::fromCycle($cycle, $this->today, 60_000, 60_000, 0);

        return view('dashboard._repurchase-cycle', ['card' => $card])->render();
    };

    expect($render(RepurchaseCycle::STATUS_ACTIVE, 60_000))->toContain('data-repurchase-met')
        ->and($render(RepurchaseCycle::STATUS_ACTIVE, 30_000))->not->toContain('data-repurchase-met')
        ->and($render(RepurchaseCycle::STATUS_SUSPENDED, 60_000))->not->toContain('data-repurchase-met');

    $notQualified = RepurchaseCycleCard::notQualified(10_000, 60_000);
    expect(view('dashboard._repurchase-cycle', ['card' => $notQualified])->render())->not->toContain('data-repurchase-met');
});

it('dates a restore by the order\'s paid day, not the day the page is viewed', function () {
    rnCycle($this->d['id'], '2026-08-21', '2026-09-20', RepurchaseCycle::STATUS_SUSPENDED);
    $order = Order::find(uiPaidSelfOrder($this->d['id'], 60_000, Carbon::parse('2026-09-27 23:58', 'Asia/Kolkata')));

    expect(app(RepurchaseOrderNotice::class)->for($order, $this->today))->toBe([
        'kind' => RepurchaseOrderNotice::RESTORED,
        'message' => 'Your repurchase requirement is met. Your bonus eligibility is restored from 27 Sep 2026.',
    ]);
});

it('a running cycle with BV met asks for the wallet at ₹0 instead of claiming eligibility', function () {
    $cycle = new RepurchaseCycle([
        'distributor_id' => 1, 'cycle_start_date' => '2026-09-10', 'due_date' => '2026-10-09',
        'required_bv_paise' => 60_000, 'completed_bv_paise' => 60_000, 'status' => RepurchaseCycle::STATUS_ACTIVE,
    ]);
    $html = view('dashboard._repurchase-cycle', ['card' => RepurchaseCycleCard::fromCycle($cycle, $this->today, 60_000, 60_000, 0)])->render();

    expect($html)->toContain('Repurchase BV met. Keep your repurchase wallet at ₹0 on 9 Oct 2026 to complete the cycle.')
        ->not->toContain('Today\'s business counts toward your bonuses');
});

it('gives no notice for an order paid before the current cycle began', function () {
    rnCycle($this->d['id'], '2026-09-28', '2026-10-27', RepurchaseCycle::STATUS_ACTIVE);
    $order = Order::find(uiPaidSelfOrder($this->d['id'], 60_000, Carbon::parse('2026-09-27 23:50', 'Asia/Kolkata')));
    uiPaidSelfOrder($this->d['id'], 60_000, Carbon::parse('2026-09-28 09:00', 'Asia/Kolkata'));

    expect(app(RepurchaseOrderNotice::class)->for($order, $this->today))->toBeNull();
});
