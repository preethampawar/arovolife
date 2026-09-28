<?php
declare(strict_types=1);
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Services\TeamStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->root = uiDistributor();
    $this->l1 = uiDistributor(); uiPlaceUnder($this->root['id'], 'L', $this->l1['id']);
    $this->l2 = uiDistributor(); uiPlaceUnder($this->l1['id'], 'R', $this->l2['id']); // deeper, still Left Genos
    $this->r1 = uiDistributor(); uiPlaceUnder($this->root['id'], 'R', $this->r1['id']);
    $this->now = Carbon::parse('2026-09-28 15:00', 'Asia/Kolkata');
});

it('counts paid self orders placed today per side', function () {
    uiPaidSelfOrder($this->l1['id'], 60000, $this->now->copy()->setTime(9, 0));
    uiPaidSelfOrder($this->l2['id'], 60000, $this->now->copy()->setTime(10, 0));
    uiPaidSelfOrder($this->r1['id'], 60000, $this->now->copy()->setTime(11, 0));

    $counts = app(TeamStatsService::class)->ordersTodayBySide(Distributor::find($this->root['id']), $this->now);
    expect($counts)->toBe(['left' => 2, 'right' => 1]);
});

it('excludes yesterday (IST boundary), cancelled, refunded and non-self orders', function () {
    uiPaidSelfOrder($this->l1['id'], 60000, Carbon::parse('2026-09-27 23:59', 'Asia/Kolkata'));
    uiPaidSelfOrder($this->l1['id'], 60000, $this->now->copy()->setTime(9, 0), 'cancelled');
    uiPaidSelfOrder($this->l1['id'], 60000, $this->now->copy()->setTime(9, 0), 'refunded');
    $customer = uiPaidSelfOrder($this->l1['id'], 60000, $this->now->copy()->setTime(9, 0));
    \DB::table('orders')->where('id', $customer)->update(['self_consumption' => false]);

    expect(app(TeamStatsService::class)->ordersTodayBySide(Distributor::find($this->root['id']), $this->now))
        ->toBe(['left' => 0, 'right' => 0]);
});

it('an empty side is zero, not an error', function () {
    $solo = uiDistributor();
    expect(app(TeamStatsService::class)->ordersTodayBySide(Distributor::find($solo['id']), $this->now))
        ->toBe(['left' => 0, 'right' => 0]);
});

it('My Business shows members and orders today, Right card mirrored and right-aligned', function () {
    uiPaidSelfOrder($this->r1['id'], 60000, now());
    $html = $this->actingAs($this->root['user'])->get(route('my-business'))->assertOk()->getContent();
    expect($html)->toContain('data-team-card="left"')->toContain('data-team-card="right"')
        ->toMatch('/data-team-card="right"[^>]*text-right/');
});
