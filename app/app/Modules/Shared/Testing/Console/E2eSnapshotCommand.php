<?php

declare(strict_types=1);

namespace App\Modules\Shared\Testing\Console;

use App\Console\Actions\SeedReservedTreeAction;
use App\Modules\Fulfilment\Support\FulfilmentSettings;
use App\Modules\Payments\Support\PaymentSettings;
use App\Modules\Shared\Features;
use App\Modules\Shared\Testing\E2eDatabaseTemplate;
use App\Modules\Shared\Testing\E2eMode;
use Database\Seeders\AreteCenterSeeder;
use Database\Seeders\ContentPageSeeder;
use Database\Seeders\PublicPagesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use RuntimeException;

/**
 * Builds the e2e base state from scratch and stores it as the template every
 * `e2e:reset` restores: wipe `arovolife_e2e`, migrate, run the standard seeders
 * at a pinned instant, switch off everything that would leave the machine,
 * then copy the result into `arovolife_e2e_base`.
 *
 * Destructive by design, and only ever on the e2e pair: the connected database
 * is proven `*_e2e` before anything runs (standing consent 2026-11-10, scoped
 * strictly to arovolife_e2e and its template).
 */
final class E2eSnapshotCommand extends Command
{
    /**
     * Every flag pinned, so a flag added later with a different default cannot
     * silently change what the suite sees. Mirrors the dev database's set
     * (2026-11-10) except the two that need the internet: Shiprocket and the
     * HIBP breach check. A new flag must be added here deliberately.
     *
     * @var array<class-string, bool>
     */
    private const FEATURES = [
        Features\ActionCenterFeature::class => false,
        Features\AnnouncementsFeature::class => false,
        Features\AreteCenterApplicationsFeature::class => true,
        Features\AreteDevelopmentCenterBonusFeature::class => true,
        Features\DistributorRequestsFeature::class => true,
        Features\FamilyCardFeature::class => false,
        Features\FaqLibraryFeature::class => false,
        Features\FortuneBonusFeature::class => true,
        Features\GenosSalesBonusFeature::class => true,
        Features\GrowthBoosterBonusFeature::class => true,
        Features\GsbDailyPoolPricingFeature::class => true,
        Features\HibpPasswordCheck::class => false,
        Features\InventoryFeature::class => false,
        Features\LifetimeAwardsFeature::class => true,
        Features\MentorshipBonusFeature::class => true,
        Features\MessagingFeature::class => true,
        Features\OfflineOrdersFeature::class => true,
        Features\PlanChangeNoticeFeature::class => false,
        Features\PurchaseOffersFeature::class => false,
        Features\RankBonusFeature::class => true,
        Features\RankProgressSnapshotFeature::class => false,
        Features\RegistrationKillswitch::class => true,
        Features\RepurchaseEngineFeature::class => true,
        Features\ShiprocketFulfilmentFeature::class => false,
    ];

    protected $signature = 'e2e:snapshot';

    protected $description = 'Rebuild the e2e database (wipe, migrate, seed at a pinned clock) and store it as the reset template';

    public function handle(E2eDatabaseTemplate $template): int
    {
        $database = E2eMode::assertSafe($this->laravel);
        $started = microtime(true);

        E2eMode::writeClock(E2eMode::parseInstant(E2eMode::BASE_CLOCK));
        $this->info("Rebuilding {$database} at ".E2eMode::BASE_CLOCK.' IST');

        $this->call('db:wipe', ['--force' => true]);
        $this->call('migrate', ['--force' => true, '--seed' => true]);
        // Base data every real environment has but DatabaseSeeder leaves out:
        // the public pages the nav links to (/p/management-team …) and the
        // company default Arete centre registration step 11 falls back to.
        foreach ([PublicPagesSeeder::class, AreteCenterSeeder::class] as $seeder) {
            $this->call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        // ContentPageSeeder holds the compensation page as a draft until it is
        // published by hand, as it is on every env; registration refuses to
        // record consent against an unpublished page.
        $this->call('content:publish', ['slug' => ContentPageSeeder::HELD_SLUGS]);

        // The reserved company block (root 444555666, levels 0-5), built by
        // the same action production's seeder uses. Scenario partners without
        // a parent register under its first free depth-5 leg, and the wizard
        // specs refer partners from its last two leaves.
        app(SeedReservedTreeAction::class)->buildFresh();

        $this->sealOutboundSettings();

        $tables = $template->capture();

        // Restore straight away: proves the template round-trips and flushes
        // the e2e cache through the reset's own Redis-database guard.
        $this->call('e2e:reset');

        $this->info(sprintf('Template %s holds %d tables (%.1fs).', $template->templateDatabase(), $tables, microtime(true) - $started));

        return self::SUCCESS;
    }

    /** Live gateways off and every feature flag pinned: the e2e stack has no internet. */
    private function sealOutboundSettings(): void
    {
        foreach ([PaymentSettings::KEY_RAZORPAY_ENABLED, FulfilmentSettings::KEY_SHIPROCKET_ENABLED] as $key) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => 'false']);
        }

        $declared = array_map(
            static fn (string $file): string => 'App\\Modules\\Shared\\Features\\'.basename($file, '.php'),
            glob(app_path('Modules/Shared/Features/*.php')) ?: [],
        );
        $unpinned = array_diff($declared, array_keys(self::FEATURES));

        if ($unpinned !== []) {
            throw new RuntimeException('Pin these feature flags in E2eSnapshotCommand::FEATURES first: '.implode(', ', $unpinned));
        }

        foreach (self::FEATURES as $feature => $on) {
            $on ? Feature::activate($feature) : Feature::deactivate($feature);
        }
    }
}
