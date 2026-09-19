<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Support\EnginePeriodType;
use App\Modules\Compensation\Support\EngineRegistry;
use App\Modules\Compensation\Support\ScaleEnvironment;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Times the engines against the synthetic population and says what it found.
 *
 * Four numbers per engine, because each names a different kind of problem:
 * WALL CLOCK says whether the night finishes, QUERY COUNT says whether the work
 * is per-distributor (2–6 queries each at ten lakh is a query count nothing can
 * outrun), PEAK MEMORY says whether the engine materialises the whole
 * population as models, and ROWS WRITTEN is the denominator that makes the
 * other three comparable between runs.
 *
 * Run it at 1k, 10k, 100k and 1M rather than only at the endpoint: the SHAPE of
 * the curve is the finding. An engine that takes ten times as long for ten
 * times the distributors is fine at any size; one that takes a hundred times as
 * long has a quadratic in it, and that is visible three sizes before it
 * becomes an outage.
 */
final class ScaleBenchmarkCommand extends Command
{
    protected $signature = 'compensation:scale-benchmark
                            {--engines= : Comma-separated registry keys (default: the nightly run)}
                            {--date= : The day to cut off (YYYY-MM-DD, default yesterday)}
                            {--month= : The month to close (YYYY-MM, default last month)}
                            {--report= : Write a markdown report to this path}';

    protected $description = 'Measure each compensation engine against the synthetic population';

    /** The nightly run's own engines — what the benchmark exists to answer for. */
    private const DEFAULT_ENGINES = ['repurchase.evaluate', 'gsb.daily-cutoff'];

    /** Where each engine's output lands, for the rows-written column. */
    private const RESULT_TABLES = [
        'repurchase.evaluate' => 'repurchase_cycles',
        'gsb.daily-cutoff' => 'gsb_cutoff_results',
        'rank.check' => 'rank_qualifications',
        'rank.bonus' => 'rank_bonus_results',
        'gbb.monthly' => 'gbb_results',
        'fortune.enroll' => 'fortune_participants',
        'fortune.payout' => 'fortune_results',
        'adc.bonus' => 'adc_bonus_results',
    ];

    public function __construct(private readonly ScaleEnvironment $environment)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $this->environment->ensurePermitted();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $population = (int) DB::table('distributors')->count();

        if ($population === 0) {
            $this->error('No population to measure. Run compensation:scale-seed first.');

            return self::FAILURE;
        }

        $date = $this->option('date') !== null
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::yesterday();

        $month = $this->option('month') !== null
            ? Carbon::parse((string) $this->option('month').'-01')
            : Carbon::today()->startOfMonth()->subMonthNoOverflow();

        $keys = $this->engineKeys();
        $results = [];

        $this->info(sprintf('Benchmarking %d engine(s) against %s distributors.', count($keys), number_format($population)));

        foreach ($keys as $key) {
            $results[] = $this->measure($key, $date, $month, $population);
        }

        $this->newLine();
        $this->table(
            ['Engine', 'Wall clock', 'Queries', 'Peak memory', 'Rows written', 'Per 1k distributors'],
            array_map(static fn (array $row): array => [
                $row['engine'],
                sprintf('%.2fs', $row['seconds']),
                number_format($row['queries']),
                sprintf('%.0f MB', $row['peak_bytes'] / 1_048_576),
                number_format($row['rows']),
                sprintf('%.3fs', $row['seconds'] / max(1, $population / 1000)),
            ], $results),
        );

        $reportPath = $this->option('report');

        if (is_string($reportPath) && $reportPath !== '') {
            $this->writeReport($reportPath, $population, $results);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{engine: string, key: string, seconds: float, queries: int, peak_bytes: int, rows: int, exit_code: int}
     */
    private function measure(string $key, Carbon $date, Carbon $month, int $population): array
    {
        $definition = EngineRegistry::get($key);
        $period = $definition->periodType === EnginePeriodType::Month ? $month : $date;
        $table = self::RESULT_TABLES[$key] ?? null;

        $rowsBefore = $table === null ? 0 : (int) DB::table($table)->count();

        $queries = 0;
        // Counted, never logged: DB::enableQueryLog() keeps every statement and
        // its bindings, which at ten lakh distributors would exhaust memory
        // before the engine did and report the profiler's own footprint as the
        // engine's.
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        gc_collect_cycles();
        $startedAt = hrtime(true);

        try {
            $exitCode = Artisan::call($definition->commandSignature, [
                $definition->periodOption => $definition->formatPeriod($period),
            ]);
        } catch (Throwable $e) {
            $this->error(sprintf('%s threw %s: %s', $definition->label, $e::class, $e->getMessage()));
            $exitCode = self::FAILURE;
        }

        $seconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        $rowsAfter = $table === null ? 0 : (int) DB::table($table)->count();

        if ($exitCode !== 0) {
            $this->warn(sprintf('%s exited %d — its numbers below measure a failed run.', $definition->label, $exitCode));
        }

        return [
            'engine' => $definition->label,
            'key' => $key,
            'seconds' => $seconds,
            'queries' => $queries,
            'peak_bytes' => memory_get_peak_usage(true),
            'rows' => max(0, $rowsAfter - $rowsBefore),
            'exit_code' => $exitCode,
        ];
    }

    /** @return list<string> */
    private function engineKeys(): array
    {
        $raw = $this->option('engines');

        if (! is_string($raw) || trim($raw) === '') {
            return self::DEFAULT_ENGINES;
        }

        $keys = array_values(array_filter(array_map(trim(...), explode(',', $raw))));

        foreach ($keys as $key) {
            if (! EngineRegistry::has($key)) {
                throw new RuntimeException("Unknown engine [{$key}].");
            }
        }

        return $keys;
    }

    /**
     * @param  list<array{engine: string, key: string, seconds: float, queries: int, peak_bytes: int, rows: int, exit_code: int}>  $results
     */
    private function writeReport(string $path, int $population, array $results): void
    {
        $lines = [
            '# Engine scale benchmark — '.Carbon::now()->toDateString(),
            '',
            sprintf('Population: **%s distributors**. Database: `%s`.', number_format($population), $this->environment->targetDatabase()),
            '',
            '| Engine | Wall clock | Queries | Peak memory | Rows written | Per 1k distributors |',
            '|---|---|---|---|---|---|',
        ];

        foreach ($results as $row) {
            $lines[] = sprintf(
                '| %s | %.2fs | %s | %.0f MB | %s | %.3fs |',
                $row['engine'],
                $row['seconds'],
                number_format($row['queries']),
                $row['peak_bytes'] / 1_048_576,
                number_format($row['rows']),
                $row['seconds'] / max(1, $population / 1000),
            );
        }

        $total = array_sum(array_column($results, 'seconds'));

        $lines[] = '';
        $lines[] = sprintf(
            'Total measured wall clock: **%.1f minutes**. The chain starts at 00:05 IST, so this is the answer to '
            .'"does the night finish": a chain still running when the next one is due is skipped, and a chain still '
            .'running at 08:00 makes the health digest report engines that are merely late.',
            $total / 60,
        );

        file_put_contents($path, implode("\n", $lines)."\n");

        $this->info("Report written to {$path}");
    }
}
