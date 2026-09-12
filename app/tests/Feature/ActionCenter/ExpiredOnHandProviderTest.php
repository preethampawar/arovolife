<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Stock\ExpiredOnHandProvider;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(InventoryFeature::class);
    $this->provider = app(ExpiredOnHandProvider::class);
});

it('is hidden when InventoryFeature is off', function (): void {
    Feature::for(null)->deactivate(InventoryFeature::class);

    expect($this->provider->enabled())->toBeFalse();
});

it('counts a batch expired yesterday and ignores one expiring today', function (): void {
    $expired = Helpers::stockBatch(now()->subDay()->startOfDay());
    Helpers::stockBatch(now()->startOfDay());

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($expired->id);
});

it('ignores an expired batch with nothing on hand', function (): void {
    Helpers::stockBatch(now()->subDay()->startOfDay(), qtyOnHand: 0);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed batch', function (): void {
    $batch = Helpers::stockBatch(now()->subDay()->startOfDay());

    ActionCenterSnooze::create([
        'action_key' => 'stock.expired_on_hand',
        'subject_type' => 'stock_batch',
        'subject_id' => $batch->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Write-off adjustment already drafted.',
    ]);

    expect($this->provider->count())->toBe(0);
});
