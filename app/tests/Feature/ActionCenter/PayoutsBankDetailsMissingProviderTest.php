<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Money\PayoutsBankDetailsMissingProvider;
use App\Modules\Compensation\Models\PayoutLineItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(PayoutsBankDetailsMissingProvider::class);
});

it('counts a distributor whose latest line item was held for no bank account', function (): void {
    $distributor = Helpers::distributorWithoutBank();
    $line = Helpers::payoutLineItem($distributor->id, PayoutLineItem::STATUS_NO_BANK_ACCOUNT, ['created_at' => now()->subDays(3)]);

    expect($this->provider->count())->toBe(1);
    expect($this->provider->items()->first()->subjectId)->toBe($distributor->id);
});

it('ignores a distributor whose most recent line item paid successfully', function (): void {
    $distributor = Helpers::distributorWithoutBank();
    Helpers::payoutLineItem($distributor->id, PayoutLineItem::STATUS_NO_BANK_ACCOUNT, ['created_at' => now()->subDays(20)]);
    // A newer line item — bank details were added and this run paid out fine.
    Helpers::payoutLineItem($distributor->id, PayoutLineItem::STATUS_TRANSFERRED, ['created_at' => now()->subDays(1)]);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed distributor', function (): void {
    $distributor = Helpers::distributorWithoutBank();
    Helpers::payoutLineItem($distributor->id, PayoutLineItem::STATUS_NO_BANK_ACCOUNT);

    ActionCenterSnooze::create([
        'action_key' => 'payouts.bank_details_missing',
        'subject_type' => 'distributor',
        'subject_id' => $distributor->id,
        'snoozed_until' => now()->addDays(3),
        'reason' => 'Distributor asked for a week to add bank details.',
    ]);

    expect($this->provider->count())->toBe(0);
});
