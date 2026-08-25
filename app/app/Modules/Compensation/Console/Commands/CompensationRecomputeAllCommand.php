<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\Recompute\CompensationRecomputeRunner;
use App\Modules\Compensation\Services\Recompute\CompensationStateWiper;
use App\Modules\Compensation\Services\Recompute\RecomputeGuard;
use App\Modules\Compensation\Services\Recompute\RecomputeNotPermitted;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Shared\Support\IndianNumber as Number;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * TESTING ONLY — wipes every BV-derived row and replays the engines.
 *
 * Slated for deletion once the compensation plan is signed off. See
 * docs/runbooks/artisan-commands.md → compensation:recompute-all.
 */
final class CompensationRecomputeAllCommand extends Command
{
    protected $signature = 'compensation:recompute-all
                            {--from= : First date to replay (YYYY-MM-DD, defaults to the first BV date)}
                            {--to= : Last date to replay (YYYY-MM-DD, defaults to today)}
                            {--only=* : Replay only these engine keys (e.g. --only=gsb.daily-cutoff)}
                            {--windowed : Keep everything before --from; rebuild only the window (needs --from)}
                            {--force : Skip the interactive confirmation}';

    protected $description = 'TESTING ONLY: wipe all BV-derived compensation state and replay every engine from scratch';

    public function handle(
        RecomputeGuard $guard,
        CompensationStateWiper $wiper,
        CompensationRecomputeRunner $runner,
    ): int {
        try {
            $guard->ensurePermitted();
        } catch (RecomputeNotPermitted $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('windowed') && ! $this->option('from')) {
            $this->error('--windowed needs --from: it is the date everything from is rebuilt.');

            return self::FAILURE;
        }

        /** @var list<string> $only */
        $only = array_values(array_filter((array) $this->option('only')));

        foreach ($only as $key) {
            if (! EngineRegistry::has($key)) {
                $this->error("Unknown engine key: {$key}. Known keys: "
                    .implode(', ', array_map(static fn ($d): string => $d->key, EngineRegistry::all())));

                return self::FAILURE;
            }
        }

        if (! $this->option('force') && ! $this->confirmDestruction($guard, $wiper)) {
            $this->info('Aborted.');

            return self::FAILURE;
        }

        $report = $runner->run(
            from: $this->option('from') ? Carbon::parse((string) $this->option('from')) : null,
            to: $this->option('to') ? Carbon::parse((string) $this->option('to')) : null,
            progress: function (string $message): void {
                $this->line($message);
            },
            onlyEngineKeys: $only === [] ? null : $only,
            windowed: (bool) $this->option('windowed'),
        );

        $this->newLine();
        $this->table(['Metric', 'Value'], [
            ['Window replayed', $report->windowLabel()],
            ['Days', Number::format($report->daysReplayed)],
            ['Rows destroyed', Number::format($report->totalRowsRemoved())],
            ['Orders propagated', Number::format($report->ordersPropagated)],
            ['Engine runs', Number::format($report->totalEngineRuns())],
            ['Duration', $report->durationSeconds.'s'],
        ]);

        foreach ($report->warnings as $warning) {
            $this->warn($warning);
        }

        $this->info('Recompute complete.');

        return self::SUCCESS;
    }

    private function confirmDestruction(RecomputeGuard $guard, CompensationStateWiper $wiper): bool
    {
        $counts = $wiper->preview();

        $this->warn('THIS DESTROYS EVERY BONUS, PAYOUT AND WALLET CREDIT ON THIS DATABASE.');
        $this->newLine();
        $this->line('  Connection: <options=bold>'.$guard->targetConnection().'</>');
        $this->line('  Database:   <options=bold>'.$guard->targetDatabase().'</>');
        $this->newLine();

        if ($counts === []) {
            $this->line('  No derived rows exist yet — nothing to destroy.');
        } else {
            $this->line('  Rows to be destroyed:');
            foreach ($counts as $table => $count) {
                $this->line(sprintf('    %-28s %s', $table, Number::format($count)));
            }
        }

        $this->newLine();
        $this->line('Orders, the BV ledger, distributors, the tree and the plan settings are kept.');
        $this->newLine();

        return $this->confirm('Wipe and replay all compensation state?', false);
    }
}
