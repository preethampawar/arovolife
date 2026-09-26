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
use App\Modules\Compensation\Console\Commands\MonthlyCloseCommand;
use App\Modules\Compensation\Console\Commands\MonthlyPayoutCloseCommand;
use App\Modules\Compensation\Console\Commands\MonthlyPayoutCommand;
use App\Modules\Compensation\Console\Commands\MonthlyRunCommand;
use App\Modules\Compensation\Console\Commands\NightlyRunCommand;
use App\Modules\Compensation\Console\Commands\RankBonusRunCommand;
use App\Modules\Compensation\Console\Commands\RankCheckCommand;
use App\Modules\Compensation\Console\Commands\RankProvisionalStandingsCommand;
use App\Modules\Compensation\Console\Commands\RebuildMonthCommand;
use App\Modules\Compensation\Console\Commands\RebuildNightCommand;
use App\Modules\Compensation\Console\Commands\RebuildPayoutCommand;
use App\Modules\Compensation\Console\Commands\RebuildWeekCommand;
use App\Modules\Compensation\Console\Commands\RepurchaseEvaluateCommand;
use App\Modules\Compensation\Console\Commands\WeeklyRunCommand;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Features\RankProgressSnapshotFeature;
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
    /**
     * Engines that were retired but whose runs are still in `engine_runs`.
     *
     * Not registry entries: they have no command, no schedule and no report, so
     * a definition would be a lie the dependency resolver could act on. The run
     * events page still has to name them — a bare `repurchase.snapshot` in the
     * Engine column is an internal key leaking to an admin who has no way to
     * find out what it was (F85).
     *
     * @var array<string, string>
     */
    private const RETIRED_LABELS = [
        'repurchase.snapshot' => 'Repurchase Snapshot (retired)',
    ];

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

    /**
     * The name to show for an engine key, retired engines included. Falls back
     * to the key itself, which is all a row from a future engine can offer.
     */
    public static function labelFor(string $key): string
    {
        if (self::has($key)) {
            return self::get($key)->label;
        }

        return self::RETIRED_LABELS[$key] ?? $key;
    }

    /**
     * Engines whose output is only their latest period — a later success
     * resolves an earlier failure. See EngineDefinition::$latestOnly.
     *
     * @return list<string>
     */
    public static function latestOnlyKeys(): array
    {
        return array_keys(array_filter(self::all(), static fn (EngineDefinition $d): bool => $d->latestOnly));
    }

    /**
     * The runs the SCHEDULER starts: an orchestrator that nothing else fires
     * and that has a cadence of its own.
     *
     * Derived, never hand-listed. The scheduler entries, the skipped-run
     * listener, the health service's failure rule (D5) and the Engine Runs
     * page's failure banners all have to agree on which runs these are, and a
     * fourth run added to a hand-written list in three of the four places is
     * exactly the kind of omission nobody notices until a night goes unreported.
     *
     * `cadence->isScheduled()` is the third condition on purpose: an
     * orchestrator that nothing schedules is a command an operator types, and a
     * run nobody was waiting for at 03:00 is not a run that was missed.
     *
     * @return list<string>
     */
    public static function rootOrchestratorKeys(): array
    {
        $keys = [];

        foreach (self::all() as $key => $definition) {
            if ($definition->isOrchestrator
                && $definition->orchestratedBy === null
                && $definition->cadence->isScheduled()) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The four developer rebuilds (ADR-0016, D4).
     *
     * Derived from the flag, never hand-listed, for the same reason as
     * {@see rootOrchestratorKeys()}: the rebuild preflight refuses while one is
     * in flight, the Engine Runs page hides their cards and the rebuild surface
     * validates against them, and a fifth kind added to three of those four
     * places is the omission nobody notices until two wipes race.
     *
     * @return list<string>
     */
    public static function rebuildKeys(): array
    {
        $keys = [];

        foreach (self::all() as $key => $definition) {
            if ($definition->developerOnly) {
                $keys[] = $key;
            }
        }

        return $keys;
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
                description: "Refreshes every active distributor's repurchase cycle as at the chosen date: opens or rolls cycles, recounts self-purchase BV, and moves each cycle between active, suspended and completed. Impact: writes repurchase cycles and the income-eligibility status the daily cut-off reads — it credits nothing itself. The cut-off for day D needs an evaluate run that has seen the whole of D — a run dated later than D, or a run dated D that started after D ended (the scheduled 00:05 run on D cannot see a purchase made later that same day). Run it for today; running it for a past date would stamp today's purchases onto an older cycle.",
                periodType: EnginePeriodType::Date,
                commandClass: RepurchaseEvaluateCommand::class,
                commandSignature: 'repurchase:evaluate',
                periodOption: '--date',
                dependencies: [],
                featureFlagClass: RepurchaseEngineFeature::class,
                reportRouteName: 'admin.compensation.carry-forwards.index',
                cadence: EngineCadence::daily('00:05'),
                defaultPeriod: 'today',
                orchestratedBy: 'compensation.nightly-run',
            ),

            new EngineDefinition(
                key: 'gsb.daily-cutoff',
                label: 'GSB Daily Cut-off (incl. MSB)',
                description: 'Runs the Genos Sales Bonus cut-off for the chosen day across all active distributors, prices that day\'s GSB and Mentorship pools, and credits the resulting amounts to wallets. Impact: writes the day\'s cut-off results, carry-forwards, daily pools and wallet credits. Idempotent — a distributor already credited for that day is skipped, never credited twice. While the repurchase engine is on it refuses to run at all unless Repurchase Evaluation has a succeeded run that has seen the whole cut-off day — dated after it, or dated for it but started once the day had ended. The scheduled 00:05 run on the cut-off day itself is not enough: a cycle fulfilled later that day would still read as failed and be forfeited permanently, and nothing corrects it afterwards.',
                periodType: EnginePeriodType::Date,
                commandClass: GsbDailyCutoffCommand::class,
                commandSignature: 'gsb:daily-cutoff',
                periodOption: '--date',
                dependencies: [
                    ['key' => 'repurchase.evaluate'],
                ],
                featureFlagClass: GenosSalesBonusFeature::class,
                reportRouteName: 'admin.compensation.daily-cutoffs.index',
                cadence: EngineCadence::dailyForPreviousDay('00:10'),
                defaultPeriod: 'today',
                requiresClosedPeriod: true,
                orchestratedBy: 'compensation.nightly-run',
            ),

            new EngineDefinition(
                key: 'gsb.weekly-payout',
                label: 'GSB Weekly Payout',
                description: 'Builds the weekly payout batch: sweeps GSB and Mentorship credits earned in the Wednesday–Tuesday week that closed seven days before the batch date, applies the admin charge and TDS, and writes one line item per distributor. Impact: writes a payout batch with its line items and marks the swept wallet entries as paid out. Idempotent — one batch per batch date, and a batch already processed is returned unchanged.',
                periodType: EnginePeriodType::Date,
                commandClass: GsbWeeklyPayoutCommand::class,
                commandSignature: 'gsb:weekly-payout',
                periodOption: '--date',
                dependencies: [
                    ['key' => 'gsb.daily-cutoff', 'expand' => 'week'],
                ],
                featureFlagClass: GenosSalesBonusFeature::class,
                reportRouteName: 'admin.compensation.weekly-payouts.index',
                cadence: EngineCadence::weeklyOn(2, '03:00'),
                defaultPeriod: 'today',
                manuallyTriggerable: false,
                orchestratedBy: 'compensation.weekly-run',
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
                    ['key' => 'repurchase.evaluate'],
                ],
                featureFlagClass: GrowthBoosterBonusFeature::class,
                reportRouteName: 'admin.compensation.gbb-calculation.index',
                cadence: EngineCadence::monthlyOn(1, '00:45'),
                defaultPeriod: 'prev-month',
                requiresClosedPeriod: true,
                orchestratedBy: 'compensation.monthly-close',
            ),

            new EngineDefinition(
                key: 'rank.check',
                label: 'Rank Qualification Check',
                description: "Compares every distributor's Genos BV for the month against the rank ladder and records who qualified at which rank, honouring the PYP re-qualification rules. Impact: writes rank qualification rows only — no money moves. The Rank Bonus, Growth Booster, Fortune Bonus and Lifetime Awards all read these rows, so it must run before them. Safe to re-run: a month's qualifications are recorded once per occurrence.",
                periodType: EnginePeriodType::Month,
                commandClass: RankCheckCommand::class,
                commandSignature: 'rank:check-qualifications',
                periodOption: '--month',
                // No `repurchase.evaluate` edge: the prerequisite is a run dated
                // the 1st of the FOLLOWING month, which no dependency shape here
                // can express — the command's own guard enforces it.
                dependencies: [
                    ['key' => 'gsb.daily-cutoff', 'expand' => 'month'],
                ],
                featureFlagClass: RankBonusFeature::class,
                reportRouteName: 'admin.compensation.rb-calculation.index',
                // Scheduled 15 minutes ahead of Rank Bonus: nothing else writes
                // rank_qualifications, and every monthly engine that reads them
                // starts at 00:30.
                cadence: EngineCadence::monthlyOn(1, '00:15'),
                // Now that it fires on the 1st, the month it works is the one
                // that just closed — matching Rank Bonus 15 minutes later and
                // the explicit --month the close passes.
                defaultPeriod: 'prev-month',
                orchestratedBy: 'compensation.monthly-close',
            ),

            new EngineDefinition(
                key: 'rank.provisional-standings',
                label: 'Rank Progress Snapshot',
                description: "Measures who meets each rank's conditions so far this month, up to the last settled day, with the monthly Rank Qualification Check's own rules — and records NO rank. Impact: replaces the rank progress snapshot that the distributor's rank progress and the admin's provisional standing read; no money moves and no bonus, pool, offer, announcement or termination reads it. Refuses a day whose GSB cut-off has not succeeded. Safe to re-run: each run replaces the snapshot.",
                periodType: EnginePeriodType::Date,
                commandClass: RankProvisionalStandingsCommand::class,
                commandSignature: 'rank:provisional-standings',
                periodOption: '--date',
                // The cut-off is checked inside the command, not declared as an
                // edge: a dependency would let a manual trigger run the cut-off,
                // and this engine must never be the reason money moves.
                dependencies: [],
                featureFlagClass: RankProgressSnapshotFeature::class,
                reportRouteName: null,
                // Scheduled on its own, outside the nightly chain: after the
                // 00:05 run has normally finished, before the 03:00 weekly run.
                cadence: EngineCadence::dailyForPreviousDay('02:30'),
                defaultPeriod: 'today',
                requiresClosedPeriod: true,
                latestOnly: true,
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
                    ['key' => 'repurchase.evaluate'],
                ],
                featureFlagClass: RankBonusFeature::class,
                reportRouteName: 'admin.compensation.rb-calculation.index',
                cadence: EngineCadence::monthlyOn(1, '00:30'),
                defaultPeriod: 'prev-month',
                requiresClosedPeriod: true,
                orchestratedBy: 'compensation.monthly-close',
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
                // After Fortune payout, matching MonthlyCloseCommand::STEPS —
                // ADC is off the crediting critical path, so it runs once the
                // money is credited. The minute is a POSITION in the night, not
                // a promise about the clock (see EngineCadence::$time), and the
                // recompute replay orders a replayed day by it: declaring it
                // before Fortune payout would have dev and staging replay the
                // month in an order production no longer uses.
                cadence: EngineCadence::monthlyOn(1, '03:15'),
                defaultPeriod: 'prev-month',
                requiresClosedPeriod: true,
                orchestratedBy: 'compensation.monthly-close',
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
                cadence: EngineCadence::monthlyOn(1, '04:00'),
                defaultPeriod: 'prev-month',
                orchestratedBy: 'compensation.monthly-close',
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
                    ['key' => 'repurchase.evaluate'],
                ],
                featureFlagClass: FortuneBonusFeature::class,
                reportRouteName: 'admin.compensation.fb-calculation.index',
                cadence: EngineCadence::monthlyOn(1, '01:00'),
                defaultPeriod: 'prev-month',
                requiresClosedPeriod: true,
                orchestratedBy: 'compensation.monthly-close',
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
                cadence: EngineCadence::monthlyOn(1, '01:15'),
                defaultPeriod: 'prev-month',
                requiresClosedPeriod: true,
                orchestratedBy: 'compensation.monthly-close',
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
                // The 8th, not the 1st: crediting closes on the 1st and payment
                // waits a week, so a bad month can be caught before it reaches a
                // bank. 04:00 rather than 03:30 keeps it clear of the weekly GSB
                // batch at Tuesday 03:00, which consults the same income cap.
                cadence: EngineCadence::monthlyOn(8, '04:00'),
                defaultPeriod: 'current-month',
                manuallyTriggerable: false,
                orchestratedBy: 'compensation.monthly-payout-close',
            ),

            new EngineDefinition(
                key: 'compensation.monthly-close',
                label: 'Monthly Close (crediting)',
                description: 'Runs the seven crediting engines for a closed month in dependency order — rank qualifications, Rank Bonus, Growth Booster, Fortune enrolment, ADC, Fortune payout, purchase offers — in ONE process, aborting at the first failure instead of letting the next engine read half-written input. Impact: writes nothing of its own; every credit and every result row is written by the engine it invokes, each recording its own run. A re-run resumes at the first step that has not succeeded, so the steps that already landed are never touched again.',
                periodType: EnginePeriodType::Month,
                commandClass: MonthlyCloseCommand::class,
                commandSignature: 'compensation:monthly-close',
                periodOption: '--month',
                dependencies: [],
                featureFlagClass: null,
                reportRouteName: 'admin.compensation.engine-runs.events',
                cadence: EngineCadence::monthlyOn(1, '00:20'),
                defaultPeriod: 'prev-month',
                // Scheduler-only, like the payout batches. A manual trigger goes
                // through EngineRunService, which reads back the run id the
                // console listener recorded — with eight nested commands the last
                // step's id is what it would find, and it would stamp the close's
                // outcome onto that step's row.
                manuallyTriggerable: false,
                requiresClosedPeriod: true,
                orchestratedBy: 'compensation.monthly-run',
                isOrchestrator: true,
            ),

            new EngineDefinition(
                key: 'compensation.monthly-payout-close',
                label: 'Monthly Payout Close',
                description: 'Runs the monthly payout batch a week after the crediting engines, but only once every crediting engine for the month has actually succeeded. Impact: writes nothing of its own — it refuses, naming the engine at fault and the command to re-run it, or it invokes the monthly payout batch, which writes the batch and its line items. The week between crediting and payment is the window in which a bad month can still be caught.',
                periodType: EnginePeriodType::Month,
                commandClass: MonthlyPayoutCloseCommand::class,
                commandSignature: 'compensation:monthly-payout-close',
                periodOption: '--month',
                dependencies: [],
                featureFlagClass: null,
                reportRouteName: 'admin.compensation.weekly-payouts.index',
                cadence: EngineCadence::monthlyOn(8, '04:00'),
                defaultPeriod: 'prev-month',
                // Maker-checker: it creates a payout batch, and the permission
                // that would trigger it is the one that approves the batch.
                manuallyTriggerable: false,
                requiresClosedPeriod: true,
                orchestratedBy: 'compensation.monthly-run',
                isOrchestrator: true,
            ),

            new EngineDefinition(
                key: 'compensation.nightly-run',
                label: 'Nightly Run (repurchase + cut-off)',
                description: 'Runs the two engines every night is due, in dependency order, in ONE process: the repurchase evaluation dated tonight, then the GSB cut-off for yesterday — preceded by any night that was missed, so a day nobody was credited for is healed rather than lost. Impact: writes nothing of its own; every credit and result row is written by the engine it invokes, each recording its own run. A re-run resumes at the first step that has not succeeded, so the steps that already landed are never touched again. The weekly and monthly runs are their own commands and wait on this one — not the other way round.',
                periodType: EnginePeriodType::Date,
                commandClass: NightlyRunCommand::class,
                commandSignature: 'compensation:nightly-run',
                periodOption: '--date',
                dependencies: [],
                featureFlagClass: null,
                reportRouteName: 'admin.compensation.engine-runs.events',
                cadence: EngineCadence::daily('00:05'),
                defaultPeriod: 'today',
                // Scheduler-only, like the closes it fires. A manual trigger goes
                // through EngineRunService, which reads back the run id the
                // console listener recorded — with the whole run nested inside
                // it, the last step's id is what it would find, and it would
                // stamp the run's outcome onto that step's row.
                manuallyTriggerable: false,
                isOrchestrator: true,
            ),

            new EngineDefinition(
                key: 'compensation.weekly-run',
                label: 'Weekly Run (Tuesday payout)',
                description: 'Builds the Tuesday weekly payout batch — and, on any other night, only a Tuesday whose batch was never built, still dated that Tuesday. Waits for tonight\'s nightly run to succeed first. Impact: writes nothing of its own; the batch and its line items are written by GSB Weekly Payout. Runs at 03:00 IST: the batch dated Tuesday T pays the week that closed on T−7, so it never needs tonight\'s cut-off figures, and a night it waits costs a day rather than a figure.',
                periodType: EnginePeriodType::Date,
                commandClass: WeeklyRunCommand::class,
                commandSignature: 'compensation:weekly-run',
                periodOption: '--date',
                dependencies: [],
                featureFlagClass: null,
                reportRouteName: 'admin.compensation.weekly-payouts.index',
                cadence: EngineCadence::daily('03:00', 'the Tuesday batch; on other nights only a Tuesday that was never built'),
                defaultPeriod: 'today',
                manuallyTriggerable: false,
                isOrchestrator: true,
            ),

            new EngineDefinition(
                key: 'compensation.monthly-run',
                label: 'Monthly Run (close + payout)',
                description: "Closes the month that has just ended — the seven crediting engines, in order — the first night every one of its days has a completed cut-off and tonight's nightly run (and the Tuesday batch, when one is owed) has succeeded; and from the 8th builds the monthly payout batch once every crediting engine for the month has succeeded. Impact: writes nothing of its own. A month it cannot close or pay is recorded as a deferral (an alert and a skipped run), never a failed night, and is re-attempted the next night.",
                periodType: EnginePeriodType::Date,
                commandClass: MonthlyRunCommand::class,
                commandSignature: 'compensation:monthly-run',
                periodOption: '--date',
                dependencies: [],
                featureFlagClass: null,
                reportRouteName: 'admin.compensation.engine-runs.events',
                cadence: EngineCadence::daily('04:00', 'closes the month once its last day is cut off; pays from the 8th'),
                defaultPeriod: 'today',
                manuallyTriggerable: false,
                isOrchestrator: true,
            ),

            // The four developer rebuilds. They are registry entries so their
            // runs are recorded and their signatures pinned like every other
            // engine's — never so an admin can reach them: `developerOnly` keeps
            // them off the Engine Runs cards for every role, and
            // `EngineCadence::unscheduled()` keeps them out of the scheduler, so
            // the replay (which fires leaves only) cannot touch them either.
            new EngineDefinition(
                key: 'compensation.rebuild-night',
                label: 'Rebuild — night',
                description: "Deletes the previous day's cut-off results, daily pools, mentorship results and their wallet credits, rewinds the carry-forward, and re-runs the night. Refuses once a later day has been cut off. Impact: removes exactly those rows in one transaction and re-derives them through the ordinary nightly run — no credit is ever written by hand, and a credit that has already been paid refuses the rebuild outright.",
                periodType: EnginePeriodType::Date,
                commandClass: RebuildNightCommand::class,
                commandSignature: 'compensation:rebuild-night',
                periodOption: '--date',
                dependencies: [],
                featureFlagClass: null,
                reportRouteName: null,
                cadence: EngineCadence::unscheduled(),
                defaultPeriod: 'today',
                manuallyTriggerable: false,
                isOrchestrator: true,
                developerOnly: true,
            ),

            new EngineDefinition(
                key: 'compensation.rebuild-week',
                label: 'Rebuild — weekly payout',
                description: "Removes an unapproved Tuesday batch — its lines and its own debits — un-sweeps the credits it took, and builds the batch again. Refuses a batch finance has approved. Impact: deletes the batch's own projection of a payment that never happened and re-derives it through GSB Weekly Payout; the credits themselves are un-swept, never deleted, and the rebuilt batch names its maker so a second person must approve it.",
                periodType: EnginePeriodType::Date,
                commandClass: RebuildWeekCommand::class,
                commandSignature: 'compensation:rebuild-week',
                periodOption: '--date',
                dependencies: [],
                featureFlagClass: null,
                reportRouteName: null,
                cadence: EngineCadence::unscheduled(),
                defaultPeriod: 'today',
                manuallyTriggerable: false,
                isOrchestrator: true,
                developerOnly: true,
            ),

            new EngineDefinition(
                key: 'compensation.rebuild-month',
                label: 'Rebuild — monthly close',
                description: "Deletes the month's rank, Growth Booster, Fortune, ADC and purchase-offer rows and their wallet credits, removes the month's unapproved payout batch if one exists, and re-runs the close. Refuses a frozen month or one the next month was built on. Impact: removes those rows in one transaction and re-derives them through the ordinary monthly close; consumed purchase-offer grants and delivered award milestones are kept, and no credit is ever written by hand.",
                periodType: EnginePeriodType::Month,
                commandClass: RebuildMonthCommand::class,
                commandSignature: 'compensation:rebuild-month',
                periodOption: '--month',
                dependencies: [],
                featureFlagClass: null,
                reportRouteName: null,
                cadence: EngineCadence::unscheduled(),
                defaultPeriod: 'prev-month',
                manuallyTriggerable: false,
                isOrchestrator: true,
                developerOnly: true,
            ),

            new EngineDefinition(
                key: 'compensation.rebuild-payout',
                label: 'Rebuild — monthly payout',
                description: "Removes the month's unapproved payout batch, un-sweeps its credits, deletes its own debits, and re-runs the payout close so every distributor's amount is frozen again. Impact: deletes the batch row, its line items and the three sweep debits it wrote, then re-derives all of them through the monthly payout close; the credits are un-swept rather than deleted, and an approved batch is refused outright.",
                periodType: EnginePeriodType::Month,
                commandClass: RebuildPayoutCommand::class,
                commandSignature: 'compensation:rebuild-payout',
                periodOption: '--month',
                dependencies: [],
                featureFlagClass: null,
                reportRouteName: null,
                cadence: EngineCadence::unscheduled(),
                defaultPeriod: 'prev-month',
                manuallyTriggerable: false,
                isOrchestrator: true,
                developerOnly: true,
            ),
        ];

        $keyed = [];
        foreach ($definitions as $definition) {
            $keyed[$definition->key] = $definition;
        }

        return $keyed;
    }
}
