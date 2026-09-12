<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Stock\PoOverdueProvider;
use App\Modules\ActionCenter\Services\ActionCenterSettings;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(InventoryFeature::class);
    $this->provider = app(PoOverdueProvider::class);
});

it('is hidden when InventoryFeature is off', function (): void {
    Feature::for(null)->deactivate(InventoryFeature::class);

    expect($this->provider->enabled())->toBeFalse();
});

it('counts a PO sent just outside the overdue window (no expected date) and ignores one just inside it', function (): void {
    $days = app(ActionCenterSettings::class)->poOverdueDays();

    $overdue = Helpers::purchaseOrder(PurchaseOrder::STATUS_SENT, sentAt: now()->subDays($days)->subHour());
    Helpers::purchaseOrder(PurchaseOrder::STATUS_SENT, sentAt: now()->subDays($days)->addHours(2));

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($overdue->id);
});

it('counts a PO whose expected date has passed regardless of when it was sent', function (): void {
    $po = Helpers::purchaseOrder(PurchaseOrder::STATUS_SENT, sentAt: now()->subDay(), expectedAt: now()->subDay()->toDateString());

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($po->id);
});

it('ignores a fully received PO', function (): void {
    $days = app(ActionCenterSettings::class)->poOverdueDays();
    Helpers::purchaseOrder(PurchaseOrder::STATUS_RECEIVED, sentAt: now()->subDays($days + 5));

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed PO', function (): void {
    $days = app(ActionCenterSettings::class)->poOverdueDays();
    $po = Helpers::purchaseOrder(PurchaseOrder::STATUS_SENT, sentAt: now()->subDays($days + 5));

    ActionCenterSnooze::create([
        'action_key' => 'stock.po_overdue',
        'subject_type' => 'purchase_order',
        'subject_id' => $po->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Supplier confirmed dispatch this week.',
    ]);

    expect($this->provider->count())->toBe(0);
});
