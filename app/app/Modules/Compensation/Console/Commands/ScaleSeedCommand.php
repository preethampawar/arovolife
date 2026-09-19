<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Support\ScaleEnvironment;
use App\Modules\Shared\Features\AreteDevelopmentCenterBonusFeature;
use App\Modules\Shared\Features\FortuneBonusFeature;
use App\Modules\Shared\Features\GenosSalesBonusFeature;
use App\Modules\Shared\Features\GrowthBoosterBonusFeature;
use App\Modules\Shared\Features\PurchaseOffersFeature;
use App\Modules\Shared\Features\RankBonusFeature;
use App\Modules\Shared\Features\RepurchaseEngineFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use RuntimeException;
use SplFixedArray;
use Throwable;

/**
 * Builds a synthetic population big enough to answer one question: does the
 * nightly run finish in an acceptable wall clock at ten lakh distributors?
 *
 * Everything the engines read at scale is written here and nothing else — no
 * KYC, no consent, no PII. The rows are deliberately fiction, which is why
 * {@see ScaleEnvironment} refuses every database anybody depends on: mixed into
 * real data this population is unrecoverable short of a restore.
 *
 * THE TREE IS DELIBERATELY IMBALANCED. A perfectly balanced binary tree is the
 * one shape a real Genos never has, and it is also the cheapest to compute: the
 * closure table is shallow, the cut-off's ancestor walks are short, the
 * carry-forward never has to work, and a benchmark run against it would flatter
 * every engine. So placement is real spillover — walk down from the root taking
 * the left slot with probability `--left-bias` until an empty one is found —
 * which produces the deep, left-heavy tree the engines actually meet, and
 * respects the one-L-one-R slot uniqueness the schema enforces.
 *
 * Bulk `insert()` throughout, never models: an Eloquent create() per row would
 * make the seeder itself the thing being measured.
 */
final class ScaleSeedCommand extends Command
{
    protected $signature = 'compensation:scale-seed
                            {--distributors=1000000 : How many distributors to create}
                            {--months=1 : How many months of orders and BV to lay down}
                            {--left-bias=0.7 : Share of placements that take the left slot when both are free}
                            {--active-fraction=0.02 : Share of distributors that buy on a given day}
                            {--chunk=2000 : Rows per bulk insert}
                            {--fresh : Empty the synthetic tables first}
                            {--force : Skip the confirmation}';

    protected $description = 'Seed a synthetic population for the engine scale benchmark (never production)';

    /**
     * Tables a fresh run must empty, children first.
     *
     * The engines' own OUTPUT is in here as well as the fixture's input. A
     * benchmark measures what an engine does, and an engine that finds its work
     * already done does almost nothing: the first 100k run reported the
     * repurchase evaluation writing no rows at all, because the cycles from the
     * previous 10k run were still there. That is not a fast engine, it is a
     * measurement of nothing.
     *
     * @var list<string>
     */
    private const TABLES = [
        'gsb_cutoff_results',
        'gsb_carryforward',
        'gsb_daily_pools',
        'repurchase_cycles',
        'wallet_ledger_entries',
        'group_bv_daily',
        'bv_ledger_entries',
        'order_items',
        'orders',
        'customers',
        'genealogy_closure',
        'distributors',
        'users',
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

        $count = max(1, (int) $this->option('distributors'));
        $months = max(0, (int) $this->option('months'));
        $leftBias = min(0.95, max(0.5, (float) $this->option('left-bias')));
        $fraction = min(1.0, max(0.0, (float) $this->option('active-fraction')));
        /** @var positive-int $chunk */
        $chunk = max(100, (int) $this->option('chunk'));
        $days = $months * 30;

        [$parents, $sides] = $this->buildTree($count, $leftBias);
        $depths = $this->buildDepths($parents, $count);
        $closureRows = $this->sum($depths, $count) + $count;

        $this->table(['What', 'Rows'], [
            ['Target database', $this->environment->targetDatabase()],
            ['users + distributors', number_format($count).' each'],
            ['genealogy_closure', number_format($closureRows)],
            ['deepest placement', (string) $this->max($depths, $count)],
            ['orders + order_items', number_format((int) round($count * $fraction * $days)).' each'],
            ['bv_ledger_entries', number_format((int) round($count * $fraction * $days))],
            ['group_bv_daily', number_format((int) round($count * $fraction * $days))],
        ]);

        if (! $this->option('force') && ! $this->confirm(
            sprintf('Write this into [%s]?', $this->environment->targetDatabase()),
            false,
        )) {
            $this->info('Nothing written.');

            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            $refusal = $this->environment->truncateRefusalReason();

            if ($refusal !== null) {
                $this->error($refusal);

                return self::FAILURE;
            }

            $this->emptyTables();
        }

        $started = hrtime(true);

        $this->activateEngines();
        $this->seedPeople($count, $parents, $sides, $depths, $chunk);
        $this->seedClosure($count, $parents, $chunk);

        if ($days > 0 && $fraction > 0.0) {
            $variantId = $this->ensureProduct();
            $this->seedActivity($count, $days, $fraction, $chunk, $variantId, $parents, $sides);
        }

        $this->info(sprintf(
            'Seeded %s distributors in %s.',
            number_format($count),
            $this->elapsed($started),
        ));

        return self::SUCCESS;
    }

    /**
     * Place every distributor by spillover and return [parents, sides].
     *
     * Walk down from the root, preferring the left slot, until an empty one is
     * found — which is what the real placement engine does and what the schema
     * insists on: `uniq_distributors_slot` allows one L and one R child per
     * parent, so a tree built by arithmetic on the id collides the moment a
     * parent is handed a third child.
     *
     * The bias is what makes it lopsided. At 0.7 the left group carries most of
     * the population, the tree runs to roughly twice the depth of a balanced
     * one, and the carry-forward has something to do — all three being
     * conditions a benchmark on a tidy tree would never meet.
     *
     * Deterministic: the choice comes from a hash of the node's own id, so two
     * runs of the same size produce the same tree and two benchmarks are
     * comparable.
     *
     * @return array{0: SplFixedArray<int>, 1: SplFixedArray<string>} Sides are 'L', 'R', or '' for the root.
     */
    private function buildTree(int $count, float $leftBias): array
    {
        $parents = new SplFixedArray($count + 1);
        $sides = new SplFixedArray($count + 1);
        $left = new SplFixedArray($count + 1);
        $right = new SplFixedArray($count + 1);

        $parents[1] = 0;
        $sides[1] = '';

        $threshold = (int) round($leftBias * 1000);

        for ($i = 2; $i <= $count; $i++) {
            $node = 1;
            $step = 0;

            while (true) {
                // A cheap, reproducible bit stream per node: the id mixed with
                // the step, so the path a node takes is its own and does not
                // change when the population size does.
                $goLeft = (int) (crc32("{$i}:{$step}") % 1000) < $threshold;
                $slot = $goLeft ? $left : $right;

                if (($slot[$node] ?? 0) === 0) {
                    $slot[$node] = $i;
                    $parents[$i] = $node;
                    $sides[$i] = $goLeft ? 'L' : 'R';

                    break;
                }

                $node = (int) $slot[$node];
                $step++;
            }
        }

        return [$parents, $sides];
    }

    /**
     * @param  SplFixedArray<int>  $parents
     * @return SplFixedArray<int>
     */
    private function buildDepths(SplFixedArray $parents, int $count): SplFixedArray
    {
        $depths = new SplFixedArray($count + 1);
        $depths[0] = 0;
        $depths[1] = 0;

        for ($i = 2; $i <= $count; $i++) {
            $depths[$i] = ((int) $depths[(int) $parents[$i]]) + 1;
        }

        return $depths;
    }

    /**
     * @param  SplFixedArray<int>  $parents
     * @param  SplFixedArray<string>  $sides
     * @param  SplFixedArray<int>  $depths
     * @param  positive-int  $chunk
     */
    private function seedPeople(int $count, SplFixedArray $parents, SplFixedArray $sides, SplFixedArray $depths, int $chunk): void
    {
        $bar = $this->output->createProgressBar($count);
        $bar->setFormat(' %current%/%max% people [%bar%] %elapsed%');

        // One hash for everybody: bcrypt is deliberately slow, and hashing a
        // million passwords would take longer than the benchmark it is for.
        $password = '$2y$12$0000000000000000000000000000000000000000000000000000u';
        $now = Carbon::now()->toDateTimeString();
        $effective = Carbon::now()->subYear()->toDateTimeString();
        $cooling = Carbon::now()->subYear()->addDays(30)->toDateTimeString();

        for ($start = 1; $start <= $count; $start += $chunk) {
            $end = min($count, $start + $chunk - 1);
            $users = [];
            $distributors = [];
            $customers = [];

            for ($i = $start; $i <= $end; $i++) {
                $users[] = [
                    'id' => $i,
                    'email' => "scale{$i}@example.test",
                    // +91 1600 is not an allocated Indian mobile prefix, so a
                    // synthetic number cannot reach a real handset if this data
                    // ever meets code that dials or texts.
                    'phone_e164' => '+911600'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                    'password_hash' => $password,
                    'status' => 'active',
                    'full_name' => "Scale Distributor {$i}",
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $parent = (int) $parents[$i];

                $distributors[] = [
                    'id' => $i,
                    'user_id' => $i,
                    'adn' => 'SC'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                    'pan_hash' => hash('sha256', "scale-pan-{$i}", true),
                    'pan_last4' => str_pad((string) ($i % 10000), 4, '0', STR_PAD_LEFT),
                    // The root points at itself, exactly as the real first
                    // distributor does: both columns are NOT NULL, and a tree
                    // has to start somewhere. The closure walk stops at it
                    // because buildTree() records its parent as 0.
                    'sponsor_id' => $parent > 0 ? $parent : $i,
                    'placement_parent_id' => $parent > 0 ? $parent : $i,
                    // Seven in ten to the left: the imbalance is the point. A
                    // Genos that pays the same on both sides is the one case
                    // the carry-forward never has to work at.
                    // '' is the root, which has no side.
                    'placement_side' => $sides[$i] === '' ? null : $sides[$i],
                    'side_chosen_by' => $sides[$i] === 'R' ? 'spillover_right' : 'spillover_left',
                    'depth' => (int) $depths[$i],
                    'effective_date' => $effective,
                    'cooling_off_end_at' => $cooling,
                    'state' => 'TG',
                    'is_primary_couple' => 1,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // An order's customer_id points at `customers`, not at the
                // distributor: a self-purchase is still a purchase by a person.
                $customers[] = [
                    'id' => $i,
                    'user_id' => $i,
                    'distributor_id' => $i,
                    'display_name' => "Scale Distributor {$i}",
                    'marketing_opt_in' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('users')->insert($users);
            DB::table('distributors')->insert($distributors);
            DB::table('customers')->insert($customers);

            $bar->advance(count($users));
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * The closure table, built incrementally: a node's ancestors are its
     * parent's ancestors plus its parent. Walking up per node is O(depth) and
     * the depth is bounded by the branching factor, so this stays linear in the
     * rows it writes rather than in the tree it walks twice.
     */
    /**
     * @param  SplFixedArray<int>  $parents
     * @param  positive-int  $chunk
     */
    private function seedClosure(int $count, SplFixedArray $parents, int $chunk): void
    {
        $bar = $this->output->createProgressBar($count);
        $bar->setFormat(' %current%/%max% closure [%bar%] %elapsed%');

        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['ancestor_id' => $i, 'descendant_id' => $i, 'depth' => 0];

            $depth = 1;

            for ($ancestor = (int) $parents[$i]; $ancestor > 0; $ancestor = (int) $parents[$ancestor]) {
                $rows[] = ['ancestor_id' => $ancestor, 'descendant_id' => $i, 'depth' => $depth];
                $depth++;
            }

            if (count($rows) >= $chunk) {
                DB::table('genealogy_closure')->insert($rows);
                $rows = [];
            }

            $bar->advance();
        }

        if ($rows !== []) {
            DB::table('genealogy_closure')->insert($rows);
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Orders, their BV ledger entries and the group BV they would have
     * propagated — laid down day by day, so the engines see a calendar rather
     * than one enormous instant.
     */
    /**
     * One product and one variant for every synthetic order to point at.
     *
     * The catalogue is not what is being measured and a thousand SKUs would
     * only make the fixture slower to build, so there is exactly one — enough
     * to satisfy the order-item foreign key and to carry BV.
     */
    private function ensureProduct(): int
    {
        $existing = DB::table('product_variants')->min('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $now = Carbon::now()->toDateTimeString();

        $productId = (int) DB::table('products')->insertGetId([
            'name' => 'Scale Product',
            'sku' => 'SCALE-PRODUCT',
            'slug' => 'scale-product',
            'hsn_code' => '00000000',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'variant_sku' => 'SCALE-1',
            'mrp_paise' => 100000,
            'sale_price_paise' => 100000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  positive-int  $chunk
     * @param  SplFixedArray<int>  $parents
     * @param  SplFixedArray<string>  $sides
     */
    private function seedActivity(
        int $count,
        int $days,
        float $fraction,
        int $chunk,
        int $variantId,
        SplFixedArray $parents,
        SplFixedArray $sides,
    ): void {
        $perDay = max(1, (int) round($count * $fraction));
        $bar = $this->output->createProgressBar($days);
        $bar->setFormat(' %current%/%max% days [%bar%] %elapsed%');

        $orderId = 0;

        for ($dayBack = $days; $dayBack >= 1; $dayBack--) {
            $date = Carbon::today()->subDays($dayBack);
            $stamp = $date->copy()->setTime(11, 0)->toDateTimeString();
            $orders = [];
            $items = [];
            $ledger = [];
            /** @var array<int, array{left?: int, right?: int}> $propagated */
            $propagated = [];

            // The oldest day in the window is everybody's joining purchase.
            // Without it most of the population has no personal BV at all and
            // the cut-off answers `below_600bv` for them — a real answer, but
            // the cheapest one, and a benchmark of it measures the gate rather
            // than the engine. One 600-BV purchase each puts everybody over the
            // personal-BV minimum, exactly as a real roster would be.
            $buyers = $dayBack === $days
                ? range(1, $count)
                : array_map(
                    // Deterministic spread, so two runs of the same size produce
                    // the same population and two benchmarks are comparable.
                    static fn (int $n): int => (($dayBack * 7919 + $n * 104729) % $count) + 1,
                    range(0, $perDay - 1),
                );

            foreach ($buyers as $distributorId) {
                $orderId++;
                // 600–1,200 BV a head. One purchase never reaches slab 1
                // (15,000 BV a side); an ancestor with a few dozen buyers below
                // them does, which is the shape that makes the engine work.
                $bvPaise = 60000 + (($distributorId % 7) * 10000);

                $orders[] = [
                    'id' => $orderId,
                    'order_no' => 'SC'.str_pad((string) $orderId, 12, '0', STR_PAD_LEFT),
                    'customer_id' => $distributorId,
                    'attributed_distributor_id' => $distributorId,
                    'attribution_source' => 'logged_in',
                    'payment_method' => 'online',
                    'status' => 'paid',
                    'self_consumption' => 1,
                    'subtotal_paise' => $bvPaise,
                    'gst_paise' => 0,
                    'discount_paise' => 0,
                    'redeem_points_paise' => 0,
                    'shipping_paise' => 0,
                    'total_paise' => $bvPaise,
                    'placed_at' => $stamp,
                    'paid_at' => $stamp,
                    'idempotency_key' => "scale-{$orderId}",
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];

                $items[] = [
                    'order_id' => $orderId,
                    'product_variant_id' => $variantId,
                    'product_name_snapshot' => 'Scale Product',
                    'variant_sku_snapshot' => 'SCALE-1',
                    'hsn_code_snapshot' => '00000000',
                    'qty' => 1,
                    'unit_price_paise' => $bvPaise,
                    'bv_paise' => $bvPaise,
                    'gst_rate_bp' => 0,
                    'taxable_value_paise' => $bvPaise,
                    'gst_paise' => 0,
                    'line_total_paise' => $bvPaise,
                    'created_at' => $stamp,
                ];

                $ledger[] = [
                    'distributor_id' => $distributorId,
                    'order_id' => $orderId,
                    'bv_paise' => $bvPaise,
                    'type' => 'accrual',
                    'effective_at' => $stamp,
                    'created_at' => $stamp,
                    'updated_at' => $stamp,
                ];

                // Flushed as we go, not once per day: on the joining day every
                // distributor buys, and holding ten lakh order rows, their
                // items and their ledger entries in PHP arrays before the first
                // INSERT is how a seeder runs the machine out of memory
                // building a fixture for a memory benchmark.
                if (count($orders) >= $chunk) {
                    DB::table('orders')->insert($orders);
                    DB::table('order_items')->insert($items);
                    DB::table('bv_ledger_entries')->insert($ledger);
                    $orders = [];
                    $items = [];
                    $ledger = [];
                }

                // Propagate the purchase up the placement chain, crediting each
                // ancestor on the side the buyer sits under — which is what the
                // real propagation job leaves behind, and what decides whether
                // a distributor is worth computing at all.
                //
                // Without this every ancestor has no group BV, so the cut-off's
                // idle partition sweeps ~98% of the population into the bulk
                // path and the benchmark measures the shortcut instead of the
                // engine. The BV is large enough that ancestors near the root
                // cross the slab thresholds and are actually credited.
                for ($child = $distributorId, $ancestor = (int) $parents[$distributorId];
                    $ancestor > 0;
                    $child = $ancestor, $ancestor = (int) $parents[$ancestor]) {
                    $side = $sides[$child] === 'R' ? 'right' : 'left';
                    $propagated[$ancestor][$side] = ($propagated[$ancestor][$side] ?? 0) + $bvPaise;
                }
            }

            if ($orders !== []) {
                DB::table('orders')->insert($orders);
                DB::table('order_items')->insert($items);
                DB::table('bv_ledger_entries')->insert($ledger);
            }

            $groupBv = [];

            foreach ($propagated as $ancestorId => $sideTotals) {
                $groupBv[] = [
                    'distributor_id' => $ancestorId,
                    'date' => $date->toDateString(),
                    'left_bv_paise' => $sideTotals['left'] ?? 0,
                    'right_bv_paise' => $sideTotals['right'] ?? 0,
                    'updated_at' => $stamp,
                ];
            }

            foreach (array_chunk($groupBv, $chunk) as $batch) {
                DB::table('group_bv_daily')->insert($batch);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Turn on the flags the benchmarked engines sit behind.
     *
     * A flag-off engine exits 0 having done nothing, and a benchmark of it
     * reports four queries and no rows — a number that looks like excellent
     * news and means the engine never ran. The fixture owns this, because an
     * operator who has to remember it will one day not.
     */
    private function activateEngines(): void
    {
        foreach ([
            GenosSalesBonusFeature::class,
            RepurchaseEngineFeature::class,
            RankBonusFeature::class,
            GrowthBoosterBonusFeature::class,
            FortuneBonusFeature::class,
            AreteDevelopmentCenterBonusFeature::class,
            PurchaseOffersFeature::class,
        ] as $feature) {
            Feature::for(null)->activate($feature);
        }
    }

    /**
     * Empty the synthetic tables. Permitted only because
     * {@see ScaleEnvironment} has already refused every database that holds
     * anything but this population.
     */
    private function emptyTables(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach (self::TABLES as $table) {
            try {
                DB::table($table)->truncate();
                $this->line("Emptied {$table}.");
            } catch (Throwable $e) {
                $this->warn("Could not empty {$table}: {$e->getMessage()}");
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /** @param SplFixedArray<int> $values */
    private function sum(SplFixedArray $values, int $count): int
    {
        $total = 0;

        for ($i = 1; $i <= $count; $i++) {
            $total += (int) $values[$i];
        }

        return $total;
    }

    /** @param SplFixedArray<int> $values */
    private function max(SplFixedArray $values, int $count): int
    {
        $max = 0;

        for ($i = 1; $i <= $count; $i++) {
            $max = max($max, (int) $values[$i]);
        }

        return $max;
    }

    private function elapsed(int|float $startedAt): string
    {
        return sprintf('%.1fs', (hrtime(true) - $startedAt) / 1_000_000_000);
    }
}
