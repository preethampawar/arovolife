<?php

declare(strict_types=1);

use App\Modules\ActionCenter\Models\ActionCenterSnooze;
use App\Modules\ActionCenter\Providers\Orders\InvoiceMissingProvider;
use App\Modules\ActionCenter\Support\Severity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ActionCenter\Helpers;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
    $this->provider = app(InvoiceMissingProvider::class);
});

it('is statutory and counts a paid order with no invoice', function (): void {
    $order = Helpers::paidOrder(now()->subDay());

    expect($this->provider->statutory())->toBeTrue()
        ->and($this->provider->severity())->toBe(Severity::CRITICAL)
        ->and($this->provider->count())->toBe(1)
        ->and($this->provider->items()->first()->subjectId)->toBe($order->id);
});

it('ignores a paid order that already has an invoice', function (): void {
    $order = Helpers::paidOrder(now()->subDay());

    DB::table('invoices')->insert([
        'invoice_no' => 'INV-AC-'.$order->id, 'order_id' => $order->id,
        'issued_at' => now(),
        'seller_state' => 'Telangana', 'buyer_state' => 'Telangana', 'place_of_supply' => 'Telangana',
        'subtotal_paise' => 100000, 'total_paise' => 100000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($this->provider->count())->toBe(0);
});

it('excludes a snoozed order even though the type is statutory (no route ever writes such a snooze)', function (): void {
    $order = Helpers::paidOrder(now()->subDay());

    ActionCenterSnooze::create([
        'action_key' => 'orders.invoice_missing',
        'subject_type' => 'order',
        'subject_id' => $order->id,
        'snoozed_until' => now()->addDays(2),
        'reason' => 'Testing the exclusion path directly.',
    ]);

    expect($this->provider->count())->toBe(0);
});
