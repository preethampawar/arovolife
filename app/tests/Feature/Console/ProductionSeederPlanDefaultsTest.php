<?php

declare(strict_types=1);

use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GsbDailyPoolPricingFeature;
use App\Modules\Shared\Features\InventoryFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    disableTestForeignKeys();
});

it('seeds the compensation plan tables and plan settings on a fresh database', function () {
    $this->seed(ProductionSeeder::class);

    expect(DB::table('gsb_slabs')->count())->toBe(7)
        ->and(DB::table('rank_tiers')->count())->toBe(9)
        ->and(DB::table('fortune_bonus_levels')->count())->toBe(10)
        ->and(DB::table('fortune_bonus_tiers')->count())->toBe(7)
        ->and(DB::table('lifetime_award_rewards')->count())->toBeGreaterThan(0);

    $settings = DB::table('settings')->pluck('value', 'key');
    expect($settings['comp.gsb.pool_rate_bp'])->toBe('4500')
        ->and($settings['comp.fortune.pool_rate_bp'])->toBe('500')
        ->and($settings['comp.admin_charge.applies_to_awards'])->toBe('false')
        ->and($settings['payout.min_threshold_paise'])->toBe('10000')
        ->and($settings['commerce.guest_checkout.enabled'])->toBe('false')
        ->and($settings['security.registration_throttle_requests'])->toBe('60');
});

it('turns every bonus flag on and purchase offers off on a fresh database', function () {
    $this->seed(ProductionSeeder::class);

    expect(Feature::for(null)->active(GenosSalesBonusFeature::class))->toBeTrue()
        ->and(Feature::for(null)->active(GsbDailyPoolPricingFeature::class))->toBeTrue()
        ->and(Feature::for(null)->active(FortuneBonusFeature::class))->toBeTrue()
        ->and(Feature::for(null)->active(RankBonusFeature::class))->toBeTrue()
        ->and(Feature::for(null)->active(PurchaseOffersFeature::class))->toBeFalse()
        ->and(Feature::for(null)->active(InventoryFeature::class))->toBeFalse();
});

it('never overwrites an edited plan, setting or flag on a re-run', function () {
    $this->seed(ProductionSeeder::class);

    DB::table('fortune_bonus_tiers')->where('tier', 'rank_1')->update(['slabs_required' => 99]);
    DB::table('settings')->where('key', 'comp.gsb.pool_rate_bp')->update(['value' => '4000']);
    Feature::for(null)->deactivate(FortuneBonusFeature::class);
    Feature::for(null)->activate(PurchaseOffersFeature::class);

    $this->seed(ProductionSeeder::class);
    Feature::flushCache();

    expect(DB::table('fortune_bonus_tiers')->where('tier', 'rank_1')->value('slabs_required'))->toBe(99)
        ->and(DB::table('settings')->where('key', 'comp.gsb.pool_rate_bp')->value('value'))->toBe('4000')
        ->and(Feature::for(null)->active(FortuneBonusFeature::class))->toBeFalse()
        ->and(Feature::for(null)->active(PurchaseOffersFeature::class))->toBeTrue();
});
