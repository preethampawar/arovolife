<?php

declare(strict_types=1);

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Compensation\Models\GroupBvDaily;
use App\Modules\Compensation\Models\GsbCutoffResult;
use App\Modules\Compensation\Models\MentorshipBonusResult;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\MentorshipBonusFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

/**
 * A sponsor→sponsee pair where the sponsee's group BV matches GSB slab 1, so a
 * cut-off credits GSB and (when enabled) the sponsor earns a Mentorship Bonus.
 *
 * @return array{0: Distributor, 1: Distributor} [sponsor, sponsee]
 */
function seedGsbCreditingPair(): array
{
    $sponsor = Distributor::factory()->create(['status' => 'active', 'adn' => '100000001']);
    $sponsee = Distributor::factory()->create(['status' => 'active', 'adn' => '100000002']);

    // Sponsee personal BV 3,000 (Retailer) so GSB transfers; sponsor 600 BV for MB eligibility.
    BvLedgerEntry::create(['distributor_id' => $sponsee->id, 'order_id' => 999_001, 'bv_paise' => 300_000, 'type' => 'accrual', 'effective_at' => now()]);
    BvLedgerEntry::create(['distributor_id' => $sponsor->id, 'order_id' => 999_002, 'bv_paise' => 60_000, 'type' => 'accrual', 'effective_at' => now()]);

    // Weaker side 1,600,000 paise (16,000 BV) ≥ slab-1 threshold (15,000 BV) → credits slab 1.
    GroupBvDaily::create(['distributor_id' => $sponsee->id, 'date' => today()->toDateString(), 'left_bv_paise' => 2_000_000, 'right_bv_paise' => 1_600_000]);

    DB::table('sponsorship')->insert(['sponsor_id' => $sponsor->id, 'distributor_id' => $sponsee->id, 'created_at' => now()]);

    return [$sponsor, $sponsee];
}

it('no-ops when the Genos Sales Bonus feature is off (default)', function (): void {
    seedGsbCreditingPair();

    $code = Artisan::call('gsb:daily-cutoff');

    expect($code)->toBe(0);
    expect(Artisan::output())->toContain('Genos Sales Bonus is disabled');
    expect(GsbCutoffResult::count())->toBe(0);
    expect(MentorshipBonusResult::count())->toBe(0);
});

it('runs GSB but skips the Mentorship Bonus when only the GSB feature is on', function (): void {
    [, $sponsee] = seedGsbCreditingPair();
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    // MentorshipBonusFeature stays off (default).

    expect(Artisan::call('gsb:daily-cutoff'))->toBe(0);

    expect(GsbCutoffResult::where('distributor_id', $sponsee->id)->where('status', GsbCutoffResult::STATUS_CREDITED)->exists())->toBeTrue();
    expect(MentorshipBonusResult::count())->toBe(0); // MB skipped by its flag
});

it('runs both GSB and the Mentorship Bonus when both features are on', function (): void {
    [$sponsor, $sponsee] = seedGsbCreditingPair();
    Feature::for(null)->activate(GenosSalesBonusFeature::class);
    Feature::for(null)->activate(MentorshipBonusFeature::class);

    expect(Artisan::call('gsb:daily-cutoff'))->toBe(0);

    expect(GsbCutoffResult::where('distributor_id', $sponsee->id)->where('status', GsbCutoffResult::STATUS_CREDITED)->exists())->toBeTrue();
    expect(MentorshipBonusResult::where('sponsor_id', $sponsor->id)->exists())->toBeTrue();
});
