<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Stock\LedgerDriftProvider;
use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(InventoryFeature::class);
    $this->provider = app(LedgerDriftProvider::class);
});

it('is hidden when InventoryFeature is off', function (): void {
    Feature::for(null)->deactivate(InventoryFeature::class);

    expect($this->provider->enabled())->toBeFalse();
});

it('counts a level whose projection disagrees with the ledger and ignores one that agrees', function (): void {
    $drifted = InventoryLevel::create([
        'product_variant_id' => 1, 'warehouse_code' => 'DEFAULT', 'on_hand' => 10, 'reserved' => 0, 'reorder_level' => 0,
    ]);
    StockMovement::create([
        'product_variant_id' => 1, 'warehouse_code' => 'DEFAULT', 'type' => StockMovement::TYPE_OPENING,
        'qty' => 8, 'unit_cost_paise' => 0, 'occurred_at' => now(),
    ]);

    $agreeing = InventoryLevel::create([
        'product_variant_id' => 2, 'warehouse_code' => 'DEFAULT', 'on_hand' => 5, 'reserved' => 0, 'reorder_level' => 0,
    ]);
    StockMovement::create([
        'product_variant_id' => 2, 'warehouse_code' => 'DEFAULT', 'type' => StockMovement::TYPE_OPENING,
        'qty' => 5, 'unit_cost_paise' => 0, 'occurred_at' => now(),
    ]);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($drifted->id);
});

it('excludes a snoozed level', function (): void {
    $level = InventoryLevel::create([
        'product_variant_id' => 1, 'warehouse_code' => 'DEFAULT', 'on_hand' => 10, 'reserved' => 0, 'reorder_level' => 0,
    ]);
    StockMovement::create([
        'product_variant_id' => 1, 'warehouse_code' => 'DEFAULT', 'type' => StockMovement::TYPE_OPENING,
        'qty' => 8, 'unit_cost_paise' => 0, 'occurred_at' => now(),
    ]);

    ActionCenterSnooze::create([
        'action_key' => 'stock.ledger_drift',
        'subject_type' => 'inventory_level',
        'subject_id' => $level->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Known drift, adjustment being posted.',
    ]);

    expect($this->provider->count())->toBe(0);
});
