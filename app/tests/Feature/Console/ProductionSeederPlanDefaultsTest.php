<?php

declare(strict_types=1);

use App\Modules\Shared\Features\ActionCenterFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GsbDailyPoolPricingFeature;
use App\Modules\Shared\Features\InventoryFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Features\RankProgressSnapshotFeature;
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
    // Every plan scalar is seeded, so no engine reads a key that exists only
    // as a code fallback (Task 13 Step 3: seeder keys == plan service keys).
    expect($settings['comp.admin_charge.weekly_cap_paise'])->toBe('2500000')
        ->and($settings['comp.admin_charge.monthly_cap_paise'])->toBe('2500000')
        ->and($settings['comp.monthly_income_cap_paise'])->toBe('500000000')
        ->and($settings['comp.repurchase.cycle_days'])->toBe('30')
        ->and($settings['comp.fortune.min_commission_paise'])->toBe('3000');

    expect($settings['comp.gsb.pool_rate_bp'])->toBe('4500')
        ->and($settings['comp.fortune.pool_rate_bp'])->toBe('500')
        // One admin-charge toggle per cash bonus; Lifetime Awards are
        // merchandise only and have none (client 2026-10-09).
        ->and($settings->keys()->filter(fn (string $key): bool => str_starts_with($key, 'comp.admin_charge.applies_to_'))->sort()->values()->all())
        ->toBe([
            'comp.admin_charge.applies_to_adc',
            'comp.admin_charge.applies_to_fortune',
            'comp.admin_charge.applies_to_gbb',
            'comp.admin_charge.applies_to_gsb',
            'comp.admin_charge.applies_to_mb',
            'comp.admin_charge.applies_to_rank',
        ])
        ->and($settings['payout.min_threshold_paise'])->toBe('10000')
        ->and($settings['commerce.guest_checkout.enabled'])->toBe('false')
        ->and($settings['notifications.engine_health_email'])->toBe('preetham.pawar@gmail.com')
        ->and($settings['security.registration_throttle_requests'])->toBe('60');
});

it('turns every bonus flag on and purchase offers off on a fresh database', function () {
    $this->seed(ProductionSeeder::class);

    expect(Feature::for(null)->active(GenosSalesBonusFeature::class))->toBeTrue()
        ->and(Feature::for(null)->active(GsbDailyPoolPricingFeature::class))->toBeTrue()
        ->and(Feature::for(null)->active(FortuneBonusFeature::class))->toBeTrue()
        ->and(Feature::for(null)->active(RankBonusFeature::class))->toBeTrue()
        ->and(Feature::for(null)->active(RankProgressSnapshotFeature::class))->toBeTrue()
        ->and(Feature::for(null)->active(PurchaseOffersFeature::class))->toBeFalse()
        ->and(Feature::for(null)->active(InventoryFeature::class))->toBeTrue()
        ->and(Feature::for(null)->active(ActionCenterFeature::class))->toBeTrue();
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
