<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use App\Modules\Commerce\Console\Commands\PurchaseOffersMonthlyRunCommand;
use App\Modules\Compensation\Console\Commands\AdcBonusRunCommand;
use App\Modules\Compensation\Console\Commands\FortuneBonusEnrollCommand;
use App\Modules\Compensation\Console\Commands\FortuneBonusRunCommand;
use App\Modules\Compensation\Console\Commands\GbbMonthlyRunCommand;
use App\Modules\Compensation\Console\Commands\GsbDailyCutoffCommand;
use App\Modules\Compensation\Console\Commands\GsbWeeklyPayoutCommand;
use App\Modules\Compensation\Console\Commands\MonthlyPayoutCommand;
use App\Modules\Compensation\Console\Commands\RankBonusRunCommand;
use App\Modules\Compensation\Console\Commands\RankCheckCommand;
use App\Modules\Compensation\Console\Commands\RepurchaseEvaluateCommand;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use InvalidArgumentException;

/**
 * The single source of truth for what the compensation engines are, what they
 * need to have run first, and where their reports live.
 *
 * Pure metadata: no queries, no closures, no feature-flag reads. Callers that
 * need live state (has this period run? is the flag on?) ask
 * EngineStatusService / Pennant separately, which keeps this class safe to use
 * from the console listener that fires on every artisan command.
 *
 * EngineRegistryTest pins every entry against the console commands, the
 * scheduler and the route table, so the two can never silently drift.
 */
final class EngineRegistry
{
    /** @var array<string, EngineDefinition>|null */
    private static ?array $memo = null;

    /**
     * @return array<string, EngineDefinition>
     */
    public static function all(): array
    {
        return self::$memo ??= self::build();
    }

    public static function get(string $key): EngineDefinition
    {
        return self::all()[$key]
            ?? throw new InvalidArgumentException("Unknown compensation engine [{$key}].");
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** Reverse lookup for the console listener: artisan name → definition. */
    public static function findBySignature(string $signature): ?EngineDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->commandSignature === $signature) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return array<string, EngineDefinition>
     */
    private static function build(): array
    {
        $definitions = [
            new EngineDefinition(
                key: 'repurchase.evaluate',
                label: 'Repurchase Evaluation',
                description: "Refreshes every active distributor's monthly repurchase cycle as at the chosen date: opens or rolls cycles, recounts self-purchase BV, and moves each cycle between active, grace, suspended and completed. Impact: writes repurchase cycles and the income-eligibility status the daily cut-off reads — it credits nothing itself. Run it for today; running it for a past date would stamp today's purchases onto an older cycle.",
                periodType: EnginePeriodType::Date,
                commandClass: RepurchaseEvaluateCommand::class,
                commandSignature: 'repurchase:evaluate',
                periodOption: '--date',
                dependencies: [],
                featureFlagClass: RepurchaseEngineFeature::class,
                reportRouteName: 'admin.compensation.carry-forwards.index',
                cadence: EngineCadence::daily('00:30'),
                defaultPeriod: 'today',
            ),

            new EngineDefinition(
                key: 'gsb.daily-cutoff',
                label: 'GSB Daily Cut-off (incl. MSB)',
                description: 'Runs the Genos Sales Bonus cut-off for the chosen day across all active distributors, prices that day\'s GSB and Mentorship pools, and credits the resulting amounts to wallets. Impact: writes the day\'s cut-off results, carry-forwards, daily pools and wallet credits. Idempotent — a distributor already credited for that day is skipped, never credited twice.',
                periodType: EnginePeriodType::Date,
                commandClass: GsbDailyCutoffCommand::class,
                commandSignature: 'gsb:daily-cutoff',
                periodOption: '--date',
                dependencies: [
                    ['key' => 'repurchase.evaluate'],
                ],
                featureFlagClass: GenosSalesBonusFeature::class,
                reportRouteName: 'admin.compensation.daily-cutoffs.index',
                cadence: EngineCadence::daily('00:10', 'runs the previous day'),
                defaultPeriod: 'today',
                requiresClosedPeriod: true,
            ),

            new EngineDefinition(
                key: 'gsb.weekly-payout',
                label: 'GSB Weekly Payout',
                description: 'Builds the weekly payout batch: sweeps every unpaid weekly-bonus wallet entry, applies the admin charge and TDS, and writes one line item per distributor. Impact: writes a payout batch with its line items and marks the swept wallet entries as paid out. Idempotent — one batch per batch date, and a batch already processed is returned unchanged.',
                periodType: EnginePeriodType::Date,
                commandClass: GsbWeeklyPayoutCommand::class,
                commandSignature: 'gsb:weekly-payout',
                periodOption: '--date',
                dependencies: [
                    ['key' => 'gsb.daily-cutoff', 'expand' => 'week'],
                ],
                featureFlagClass: GenosSalesBonusFeature::class,
                reportRouteName: 'admin.compensation.weekly-payouts.index',
                cadence: EngineCadence::weeklyOn(2, '09:00'),
                defaultPeriod: 'today',
                manuallyTriggerable: false,
            ),

            new EngineDefinition(
                key: 'gbb.monthly',
                label: 'Growth Booster Bonus',
                description: "Prices the month's Growth Booster pool from company BV and the month's total AGP points, then credits each eligible distributor's share. Impact: freezes the month's pool economics and writes the Growth Booster results plus wallet credits. Idempotent — already-credited distributors are skipped and a re-run reuses the frozen pool rather than recomputing it.",
                periodType: EnginePeriodType::Month,
                commandClass: GbbMonthlyRunCommand::class,
                commandSignature: 'gbb:monthly-run',
                periodOption: '--month',
                dependencies: [
                    ['key' => 'gsb.daily-cutoff', 'expand' => 'month'],
                    ['key' => 'rank.check', 'shift' => 'prev-month'],
                ],
                featureFlagClass: GrowthBoosterBonusFeature::class,
                reportRouteName: 'admin.compensation.gbb-calculation.index',
                cadence: EngineCadence::monthlyOn(2, '08:00'),
                defaultPeriod: 'prev-month',
                requiresClosedPeriod: true,
            ),

            new EngineDefinition(
                key: 'rank.check',
                label: 'Rank Qualification Check',
                description: "Compares every distributor's Genos BV for the month against the rank ladder and records who qualified at which rank, honouring the PYP re-qualification rules. Impact: writes rank qualification rows only — no money moves. The Rank Bonus, Growth Booster, Fortune Bonus and Lifetime Awards all read these rows, so it must run before them. Safe to re-run: a month's qualifications are recorded once per occurrence.",
                periodType: EnginePeriodType::Month,
                commandClass: RankCheckCommand::class,
                commandSignature: 'rank:check-qualifications',
                periodOption: '--month',
                dependencies: [
                    ['key' => 'gsb.daily-cutoff', 'expand' => 'month'],
                ],
                featureFlagClass: RankBonusFeature::class,
                reportRouteName: 'admin.compensation.rb-calculation.index',
                cadence: EngineCadence::unscheduled(),
                defaultPeriod: 'current-month',
            ),

            new EngineDefinition(
                key: 'rank.bonus',
                label: 'Rank Bonus',
                description: "Prices the month's Rank Bonus pool and credits each qualified distributor their share of the month's rank points, including any AO/GO grants. Impact: writes the Rank Bonus results and wallet credits. Idempotent — distributors already credited for the month are skipped. It pays only distributors the Rank Qualification Check has already recorded.",
                periodType: EnginePeriodType::Month,
                commandClass: RankBonusRunCommand::class,
                commandSignature: 'rank:monthly-run',
                periodOption: '--month',
                dependencies: [
                    ['key' => 'rank.check'],
                ],
                featureFlagClass: RankBonusFeature::class,
                reportRouteName: 'admin.compensation.rb-calculation.index',
                cadence: EngineCadence::monthlyOn(8, '08:00'),
                defaultPeriod: 'prev-month',
                requiresClosedPeriod: true,
            ),

            new EngineDefinition(
                key: 'adc.bonus',
                label: 'ADC Bonus',
                description: "Calculates each Arete Development Center's bonus from the net BV of orders collected at it in the month, applies the centre's monthly cap, and credits the centre owner. Impact: writes the ADC results and wallet credits. Idempotent — centres already credited for the month are skipped. It does not depend on rank qualifications.",
                periodType: EnginePeriodType::Month,
                commandClass: AdcBonusRunCommand::class,
                commandSignature: 'adc:monthly-run',
                periodOption: '--month',
                dependencies: [],
                featureFlagClass: AreteDevelopmentCenterBonusFeature::class,
                reportRouteName: 'admin.compensation.adc-calculation.index',
                cadence: EngineCadence::monthlyOn(8, '09:30'),
                defaultPeriod: 'prev-month',
                requiresClosedPeriod: true,
            ),

            new EngineDefinition(
                key: 'offers.monthly',
                label: 'Purchase Offers',
                description: 'Grants the two purchase offers for distributors who hold no rank: the half-price company-announced product for a month in which they repurchased the qualifying volume, and redeem points for completing a six-month purchase streak. Impact: writes purchase offer grants and redeem-point accruals. It moves no cash — redeem points are a discount entitlement, not wallet money. Idempotent per distributor per month. Needs a product announced for the month at Admin → Offers, or no half-price grant is possible.',
                periodType: EnginePeriodType::Month,
                commandClass: PurchaseOffersMonthlyRunCommand::class,
                commandSignature: 'offers:monthly-run',
                periodOption: '--month',
                dependencies: [],
                featureFlagClass: PurchaseOffersFeature::class,
                reportRouteName: 'admin.commerce.offers.index',
                cadence: EngineCadence::monthlyOn(2, '06:00'),
                defaultPeriod: 'prev-month',
            ),

            new EngineDefinition(
                key: 'fortune.enroll',
                label: 'Fortune Bonus Enrolment',
                description: 'Places newly eligible distributors into the Fortune Bonus matrix for the month, first-come-first-served by their earliest GSB credit date. Impact: writes Fortune Bonus participant rows only — no money moves. It runs once per month: once the month\'s Fortune pool has been frozen by the payout run, enrolment is closed and a re-run reports "pool frozen" and changes nothing.',
                periodType: EnginePeriodType::Month,
                commandClass: FortuneBonusEnrollCommand::class,
                commandSignature: 'fortune:enroll-eligible',
                periodOption: '--month',
                dependencies: [
                    ['key' => 'rank.check'],
                ],
                featureFlagClass: FortuneBonusFeature::class,
                reportRouteName: 'admin.compensation.fb-calculation.index',
                cadence: EngineCadence::monthlyOn(9, '08:45'),
                defaultPeriod: 'prev-month',
                requiresClosedPeriod: true,
            ),

            new EngineDefinition(
                key: 'fortune.payout',
                label: 'Fortune Bonus Payout',
                description: "Prices the month's Fortune Bonus pool across the matrix levels and credits every enrolled participant, applying the level caps and the per-head minimum (pro-rated down if the pool cannot cover it). Impact: freezes the month's pool economics and writes the Fortune Bonus results plus wallet credits. Idempotent — already-credited participants are skipped and a re-run reuses the frozen pool.",
                periodType: EnginePeriodType::Month,
                commandClass: FortuneBonusRunCommand::class,
                commandSignature: 'fortune:monthly-run',
                periodOption: '--month',
                dependencies: [
                    ['key' => 'fortune.enroll'],
                ],
                featureFlagClass: FortuneBonusFeature::class,
                reportRouteName: 'admin.compensation.fb-calculation.index',
                cadence: EngineCadence::monthlyOn(9, '09:00'),
                defaultPeriod: 'prev-month',
                requiresClosedPeriod: true,
            ),

            new EngineDefinition(
                key: 'payout.monthly',
                label: 'Monthly Payout Batch',
                description: 'Builds the monthly payout batch over the Growth Booster, Rank, Fortune, Awards and ADC wallet credits: applies the admin charge and TDS and writes one line item per distributor. Impact: writes a payout batch with its line items and marks the swept wallet entries as paid out. Idempotent — one batch per month, and a batch already processed is returned unchanged. Run the monthly bonus engines first, or their credits miss this batch.',
                periodType: EnginePeriodType::Month,
                commandClass: MonthlyPayoutCommand::class,
                commandSignature: 'payout:monthly-run',
                periodOption: '--month',
                dependencies: [
                    ['key' => 'gbb.monthly'],
                    ['key' => 'rank.bonus'],
                    ['key' => 'fortune.payout'],
                    ['key' => 'adc.bonus'],
                ],
                featureFlagClass: null,
                reportRouteName: 'admin.compensation.weekly-payouts.index',
                cadence: EngineCadence::monthlyOn(9, '10:30'),
                defaultPeriod: 'current-month',
                manuallyTriggerable: false,
            ),
        ];

        $keyed = [];
        foreach ($definitions as $definition) {
            $keyed[$definition->key] = $definition;
        }

        return $keyed;
    }
}
