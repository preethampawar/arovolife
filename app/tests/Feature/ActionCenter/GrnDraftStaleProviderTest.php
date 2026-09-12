<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Stock\GrnDraftStaleProvider;
use App\Modules\ActionCenter\Services\ActionCenterSettings;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    Feature::for(null)->activate(InventoryFeature::class);
    $this->provider = app(GrnDraftStaleProvider::class);
});

it('is hidden when InventoryFeature is off', function (): void {
    Feature::for(null)->deactivate(InventoryFeature::class);

    expect($this->provider->enabled())->toBeFalse();
});

it('counts a draft GRN just outside the draft window and ignores one just inside it', function (): void {
    $slaHours = app(ActionCenterSettings::class)->grnDraftDays() * 24;

    $stale = Helpers::purchaseInvoiceDraft(now()->subHours($slaHours + 1));
    Helpers::purchaseInvoiceDraft(now()->subHours($slaHours)->addMinutes(5));

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($stale->id);
});

it('excludes a snoozed GRN', function (): void {
    $slaHours = app(ActionCenterSettings::class)->grnDraftDays() * 24;
    $invoice = Helpers::purchaseInvoiceDraft(now()->subHours($slaHours + 5));

    ActionCenterSnooze::create([
        'action_key' => 'stock.grn_draft_stale',
        'subject_type' => 'purchase_invoice',
        'subject_id' => $invoice->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Waiting on the supplier invoice copy.',
    ]);

    expect($this->provider->count())->toBe(0);
});
