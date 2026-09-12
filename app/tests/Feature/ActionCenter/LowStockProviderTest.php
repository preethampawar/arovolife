<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Stock\LowStockProvider;
use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(InventoryFeature::class);
    $this->provider = app(LowStockProvider::class);
});

it('is hidden when InventoryFeature is off', function (): void {
    Feature::for(null)->deactivate(InventoryFeature::class);

    expect($this->provider->enabled())->toBeFalse();
});

it('counts a level at or under its reorder point and ignores one above it', function (): void {
    $low = InventoryLevel::create([
        'product_variant_id' => 1, 'warehouse_code' => 'DEFAULT', 'on_hand' => 5, 'reserved' => 0, 'reorder_level' => 5,
    ]);
    InventoryLevel::create([
        'product_variant_id' => 2, 'warehouse_code' => 'DEFAULT', 'on_hand' => 20, 'reserved' => 0, 'reorder_level' => 5,
    ]);
    // reorder_level = 0 is "not tracked", never low regardless of on_hand.
    InventoryLevel::create([
        'product_variant_id' => 3, 'warehouse_code' => 'DEFAULT', 'on_hand' => 0, 'reserved' => 0, 'reorder_level' => 0,
    ]);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($low->id);
});

it('excludes a snoozed level', function (): void {
    $level = InventoryLevel::create([
        'product_variant_id' => 1, 'warehouse_code' => 'DEFAULT', 'on_hand' => 5, 'reserved' => 0, 'reorder_level' => 5,
    ]);

    ActionCenterSnooze::create([
        'action_key' => 'stock.low',
        'subject_type' => 'inventory_level',
        'subject_id' => $level->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Purchase order already raised.',
    ]);

    expect($this->provider->count())->toBe(0);
});
