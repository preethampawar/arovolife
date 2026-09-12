<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Stock\TransferInTransitProvider;
use App\Modules\ActionCenter\Services\ActionCenterSettings;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(InventoryFeature::class);
    $this->provider = app(TransferInTransitProvider::class);
});

it('is hidden when InventoryFeature is off', function (): void {
    Feature::for(null)->deactivate(InventoryFeature::class);

    expect($this->provider->enabled())->toBeFalse();
});

it('counts a transfer just outside the transit window and ignores one just inside it', function (): void {
    $slaHours = app(ActionCenterSettings::class)->transferTransitDays() * 24;

    $overdue = Helpers::stockTransfer(StockTransfer::STATUS_DISPATCHED, dispatchedAt: now()->subHours($slaHours + 1));
    Helpers::stockTransfer(StockTransfer::STATUS_DISPATCHED, dispatchedAt: now()->subHours($slaHours)->addMinutes(5));

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($overdue->id);
});

it('ignores a received transfer', function (): void {
    $slaHours = app(ActionCenterSettings::class)->transferTransitDays() * 24;
    Helpers::stockTransfer(StockTransfer::STATUS_RECEIVED, dispatchedAt: now()->subHours($slaHours + 5), receivedAt: now());

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed transfer', function (): void {
    $slaHours = app(ActionCenterSettings::class)->transferTransitDays() * 24;
    $transfer = Helpers::stockTransfer(StockTransfer::STATUS_DISPATCHED, dispatchedAt: now()->subHours($slaHours + 5));

    ActionCenterSnooze::create([
        'action_key' => 'stock.transfer_in_transit',
        'subject_type' => 'stock_transfer',
        'subject_id' => $transfer->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Confirmed in transit with the courier.',
    ]);

    expect($this->provider->count())->toBe(0);
});
