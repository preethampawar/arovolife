<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Services\DistributorIdCardStats;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/** Give a distributor a net personal BV via a ledger accrual (BV × 100 paise). */
function seedIdCardPersonalBv(Distributor $distributor, int $bvPaise): void
{
    static $orderId = 800000;
    BvLedgerEntry::create([
        'distributor_id' => $distributor->id,
        'order_id' => $orderId++,
        'bv_paise' => $bvPaise,
        'type' => 'accrual',
        'effective_at' => now(),
    ]);
}

it('ranks the viewer by personal BV among all distributors', function (): void {
    $me = Distributor::factory()->create();   // 1,000 BV → 2nd
    $ahead = Distributor::factory()->create(); // 2,000 BV → 1st
    $behind = Distributor::factory()->create(); // 500 BV → 3rd

    seedIdCardPersonalBv($me, 100_000);
    seedIdCardPersonalBv($ahead, 200_000);
    seedIdCardPersonalBv($behind, 50_000);

    $this->actingAs($me->user);
    $stats = app(DistributorIdCardStats::class)->full($me);

    expect($stats['personal_sales_position'])->toBe('#2');
});

it('shows position #1 for the top distributor', function (): void {
    $top = Distributor::factory()->create();
    $other = Distributor::factory()->create();
    seedIdCardPersonalBv($top, 500_000);
    seedIdCardPersonalBv($other, 100_000);

    $this->actingAs($top->user);
    expect(app(DistributorIdCardStats::class)->full($top)['personal_sales_position'])->toBe('#1');
});

it('shows no position (—/null) before the distributor has any personal BV', function (): void {
    $me = Distributor::factory()->create();      // no personal BV
    $other = Distributor::factory()->create();
    seedIdCardPersonalBv($other, 100_000);       // someone else has sales

    $this->actingAs($me->user);
    expect(app(DistributorIdCardStats::class)->full($me)['personal_sales_position'])->toBeNull();
});

it('never exposes another distributor\'s position (own data only)', function (): void {
    $me = Distributor::factory()->create();
    $other = Distributor::factory()->create();
    seedIdCardPersonalBv($other, 100_000);

    // Authenticated as $me but requesting $other's card → position hidden.
    $this->actingAs($me->user);
    expect(app(DistributorIdCardStats::class)->full($other)['personal_sales_position'])->toBeNull();
});

it('gives tied distributors the same competition-ranked position', function (): void {
    $a = Distributor::factory()->create();
    $b = Distributor::factory()->create();
    $ahead = Distributor::factory()->create();

    seedIdCardPersonalBv($a, 100_000);      // tied
    seedIdCardPersonalBv($b, 100_000);      // tied
    seedIdCardPersonalBv($ahead, 300_000);  // clearly ahead

    $this->actingAs($a->user);
    expect(app(DistributorIdCardStats::class)->full($a)['personal_sales_position'])->toBe('#2');
    $this->actingAs($b->user);
    expect(app(DistributorIdCardStats::class)->full($b)['personal_sales_position'])->toBe('#2');
});
