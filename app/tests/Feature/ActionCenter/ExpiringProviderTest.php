<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Stock\ExpiringProvider;
use App\Modules\Inventory\Services\InventorySettings;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(InventoryFeature::class);
    $this->provider = app(ExpiringProvider::class);
});

it('is hidden when InventoryFeature is off', function (): void {
    Feature::for(null)->deactivate(InventoryFeature::class);

    expect($this->provider->enabled())->toBeFalse();
});

it('counts a batch just inside the expiry window and ignores one just outside it', function (): void {
    $days = app(InventorySettings::class)->expiryAlertDays();

    $expiring = Helpers::stockBatch(now()->addDays($days)->startOfDay());
    Helpers::stockBatch(now()->addDays($days + 2)->startOfDay());

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($expiring->id);
});

it('excludes a snoozed batch', function (): void {
    $days = app(InventorySettings::class)->expiryAlertDays();
    $batch = Helpers::stockBatch(now()->addDays($days)->startOfDay());

    ActionCenterSnooze::create([
        'action_key' => 'stock.expiring',
        'subject_type' => 'stock_batch',
        'subject_id' => $batch->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Already discounted for quick sale.',
    ]);

    expect($this->provider->count())->toBe(0);
});
