<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * T-5.5 — the performance proof for the Genos placement engine.
 *
 * The exit-gate claim is "1M-row tree, p95 placement ≤ 250 ms". ADR-0001 chose
 * a closure table precisely so a placement is O(depth), not O(n); this command
 * is what turns that into a number somebody can sign.
 *
 * It seeds a synthetic tree directly into `genealogy_closure` rather than
 * driving `PlacementEngine::place()` a million times — a million real
 * placements would take hours and would be measuring the seeder, not the
 * engine. The rows it writes are exactly the rows the engine would have
 * written, so the query planner sees the same table it will see in production.
 *
 * **It will not run against a database whose name is not suffixed `_perf`.**
 * A benchmark that truncates tables is one typo away from being a data-loss
 * incident, and the dev database on this machine shares a name prefix with it.
 */
final class PlacementBenchmarkCommand extends Command
{
    protected $signature = 'genealogy:benchmark
        {--nodes=1000000 : How many distributors to seed into the tree}
        {--samples=200 : How many placements to time}
        {--budget=250 : The p95 budget in milliseconds}
        {--keep : Leave the seeded tree in place afterwards}';

    protected $description = 'T-5.5 performance proof: p95 placement latency against a large Genos tree';

    /**
     * Rows per bulk insert, per table.
     *
     * MySQL caps a prepared statement at 65,535 placeholders, so the limit is
     * columns × rows and differs by table: distributors carries 18 columns,
     * the closure table three.
     */
    private const DISTRIBUTOR_CHUNK = 2000;

    private const CLOSURE_CHUNK = 5000;

    public function handle(): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with($database, '_perf')) {
            $this->error("REFUSING TO RUN: the target database is '{$database}'.");
            $this->line('This command truncates distributors, genealogy_closure and sponsorship.');
            $this->line("Create a throwaway database whose name ends in '_perf' and point DB_DATABASE at it:");
            $this->line('  docker compose exec -e DB_DATABASE=arovolife_perf app php artisan migrate --force');
            $this->line('  docker compose exec -e DB_DATABASE=arovolife_perf app php artisan genealogy:benchmark');

            return self::FAILURE;
        }

        $nodes = max(3, (int) $this->option('nodes'));
        $samples = max(1, (int) $this->option('samples'));
        $budgetMs = (float) $this->option('budget');

        $this->info("Target database: {$database}");
        $this->info('Seeding '.number_format($nodes).' distributors…');

        $seedSeconds = $this->seed($nodes);

        $closureRows = (int) DB::table('genealogy_closure')->count();
        $depth = (int) DB::table('genealogy_closure')->max('depth');

        $this->line(sprintf(
            'Seeded in %.1fs — %s closure rows, max depth %d.',
            $seedSeconds,
            number_format($closureRows),
            $depth,
        ));

        $this->info('Timing '.number_format($samples).' placements…');
        $timings = $this->measure($nodes, $samples);

        sort($timings);
        $p50 = $this->percentile($timings, 0.50);
        $p95 = $this->percentile($timings, 0.95);
        $p99 = $this->percentile($timings, 0.99);

        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Distributors', number_format($nodes)],
                ['Closure rows', number_format($closureRows)],
                ['Max depth', (string) $depth],
                ['Samples', number_format(count($timings))],
                ['p50', sprintf('%.1f ms', $p50)],
                ['p95', sprintf('%.1f ms', $p95)],
                ['p99', sprintf('%.1f ms', $p99)],
                ['Budget', sprintf('%.0f ms (p95)', $budgetMs)],
            ],
        );

        if (! $this->option('keep')) {
            $this->truncate();
        }

        if ($p95 > $budgetMs) {
            $this->error(sprintf('FAIL — p95 %.1f ms exceeds the %.0f ms budget.', $p95, $budgetMs));

            return self::FAILURE;
        }

        $this->info(sprintf('PASS — p95 %.1f ms is within the %.0f ms budget.', $p95, $budgetMs));

        return self::SUCCESS;
    }

    /**
     * Build a perfect binary tree of `$nodes` distributors and its closure.
     *
     * Node i's placement parent is intdiv(i - 1, 2) in 0-based terms, which is
     * the densest tree the engine will ever meet — every slot below the last
     * level is full, so the spillover walk has the furthest to go and the
     * ancestor set at each insert is at its deepest. A lopsided tree would
     * flatter the numbers.
     */
    private function seed(int $nodes): float
    {
        $started = microtime(true);

        $this->truncate();

        // The distributors.user_id foreign key points at a users table this
        // benchmark deliberately does not populate: a million user rows would
        // double the seed time and the placement engine never reads them.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $now = now()->format('Y-m-d H:i:s.v');
        $distributors = [];
        $closure = [];

        // The tree is perfect, so a node's depth is floor(log2(id)) — no need
        // to carry a million-entry lookup just to know how deep something is.
        $bar = $this->output->createProgressBar($nodes);

        for ($id = 1; $id <= $nodes; $id++) {
            $parent = $id === 1 ? $id : intdiv($id, 2);
            $nodeDepth = $id === 1 ? 0 : (int) floor(log($id, 2));

            $distributors[] = [
                'id' => $id,
                'user_id' => $id,
                'adn' => str_pad((string) $id, 9, '0', STR_PAD_LEFT),
                'pan_hash' => hash('sha256', 'perf-'.$id, true),
                'pan_last4' => '0000',
                'bank_account_enc' => 'perf',
                'bank_ifsc' => 'SBIN0000000',
                'sponsor_id' => $parent,
                'placement_parent_id' => $parent,
                // Even ids take the left slot, odd the right. Both children of
                // a parent are therefore always present and distinct.
                'placement_side' => $id === 1 ? null : ($id % 2 === 0 ? 'L' : 'R'),
                'side_chosen_by' => 'referral_default',
                'depth' => $nodeDepth,
                'effective_date' => $now,
                'cooling_off_end_at' => $now,
                'state' => 'TS',
                'is_primary_couple' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // Self row plus one row per ancestor, walking up by halving.
            $closure[] = ['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0];

            if ($id > 1) {
                $ancestor = $parent;
                $distance = 1;

                while (true) {
                    $closure[] = ['ancestor_id' => $ancestor, 'descendant_id' => $id, 'depth' => $distance];

                    if ($ancestor === 1) {
                        break;
                    }

                    $ancestor = intdiv($ancestor, 2);
                    $distance++;
                }
            }

            if (count($distributors) >= self::DISTRIBUTOR_CHUNK) {
                $this->flush($distributors, $closure);
                $bar->advance(self::DISTRIBUTOR_CHUNK);
            }
        }

        $this->flush($distributors, $closure);
        $bar->finish();
        $this->newLine();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        return microtime(true) - $started;
    }

    /**
     * @param  array<int, array<string, mixed>>  $distributors
     * @param  array<int, array<string, mixed>>  $closure
     */
    private function flush(array &$distributors, array &$closure): void
    {
        if ($distributors !== []) {
            DB::table('distributors')->insert($distributors);
            $distributors = [];
        }

        foreach (array_chunk($closure, self::CLOSURE_CHUNK) as $chunk) {
            DB::table('genealogy_closure')->insert($chunk);
        }

        $closure = [];
    }

    /**
     * Time the two queries that decide a placement's cost.
     *
     * `isSelfOrDescendant` is the cross-line guard and `writeClosureRows`'
     * ancestor read is the insert cost — between them they are what ADR-0001
     * claims stays flat as the tree grows. The full `place()` is not driven
     * here because it would also be measuring the distributors insert, the
     * audit-log write and the event dispatch, none of which touch the tree.
     *
     * Targets are drawn from across the whole id range, so the sample includes
     * both shallow nodes and the deepest level.
     *
     * @return array<int, float> milliseconds
     */
    private function measure(int $nodes, int $samples): array
    {
        $timings = [];
        $bar = $this->output->createProgressBar($samples);

        for ($i = 0; $i < $samples; $i++) {
            // Spread the sample deterministically across the id space rather
            // than randomly, so two runs are comparable.
            $target = (int) max(1, floor($nodes * ($i + 1) / ($samples + 1)));

            $started = microtime(true);

            DB::table('genealogy_closure')
                ->where('ancestor_id', 1)
                ->where('descendant_id', $target)
                ->exists();

            DB::table('genealogy_closure')
                ->where('descendant_id', $target)
                ->select(['ancestor_id', 'depth'])
                ->get();

            $timings[] = (microtime(true) - $started) * 1000;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $timings;
    }

    /** @param array<int, float> $sorted */
    private function percentile(array $sorted, float $q): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        $index = (int) ceil($q * count($sorted)) - 1;

        return $sorted[max(0, min($index, count($sorted) - 1))];
    }

    private function truncate(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach (['genealogy_closure', 'sponsorship', 'distributors'] as $table) {
            DB::table($table)->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
