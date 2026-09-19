<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Services\Recompute\RecomputeGuard;
use App\Modules\Compensation\Services\Recompute\RecomputeNotPermitted;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lays down the paid orders a test environment needs before any compensation
 * engine can be rehearsed against MONEY.
 *
 * Staging reached September 2026 holding 13 orders and 70,195 BV in total,
 * which is why all 24,409 of its cut-off rows read `no_match` or
 * `below_600bv`: nobody had the 600 BV that opens the income gate, and no leg
 * had ever accumulated the 15,000 BV that slab 1 matches. Every engine ran
 * nightly, correctly, and paid nothing — so the code paths that exist only
 * when a row carries money (the swept-credit refusal, the wallet-entry delete,
 * the repurchase-deduction halves, a payout batch with line items) had never
 * been executed once, on any environment.
 *
 * This writes the INPUT only — orders, their items, and the personal BV ledger
 * entry each one produces. Nothing derived is written here: group_bv_daily,
 * the cut-offs, the pools and every bonus row are rebuilt by
 * `compensation:recompute-all`, which re-dispatches the real propagation job
 * per paid order and then replays each engine at the instant the scheduler
 * would have fired it. That division is the whole point — a fixture that wrote
 * its own derived rows would prove the fixture correct, not the engines.
 *
 * THE SHAPE, and why each part of it is there:
 *
 *  - TITLES. Every distributor gets one personal order, sized by placement
 *    depth. This clears the 600 BV income gate for all of them and buys each a
 *    GSB title, because the title is the ceiling on which slab a distributor
 *    may earn however large their legs get. Depth 0-2 are sized to reach slabs
 *    5, 4 and 3 so the rehearsal spans more than one slab's arithmetic.
 *  - GROUP BV. Every leaf buys weekly through the target month. A leaf's BV
 *    propagates to every ancestor on the side it sits, so purchases at the
 *    bottom are what fill both legs of everyone above — which is the only way
 *    a cut-off ever matches.
 *  - REPURCHASE. The earners repurchase monthly, except a deliberate one in
 *    twelve who does not, so the forfeit path has live subjects too.
 *  - COLLECTION CENTRE. A share of the group-BV orders is collected from an
 *    Arete centre rather than shipped, and each one gets a shipment carrying a
 *    recorded handover. Both halves are required and the second is the easy
 *    one to miss: the ADC engine deliberately will not pay on
 *    orders.arete_center_id alone, because that paid centres for parcels they
 *    were never sent and never released (R-24, H5). It joins `shipments` and
 *    demands a non-null collected_at, so the handover is the evidence and a
 *    fixture without one leaves that engine crediting nobody.
 *  - THE NIGHT. A concentrated burst on one recent day, because the night
 *    rebuild may only run while its night is the newest one (R-91): an August
 *    night is out of window by the time the replay reaches today, so a night
 *    that pays has to be a recent one.
 *
 * THE SECOND PASS, and why it cannot be the first. A distributor who earns is
 * credited a repurchase deduction, and their next cycle is suspended unless
 * that wallet reads zero on its due date (REASON_WALLET_NONZERO). The balance
 * is therefore a function of what the engines credited, which is not known
 * until the replay has run — a fixture cannot write the spend up front because
 * it cannot yet know the amount. So: seed, recompute, then
 * `--settle-repurchase` reads each cycle's closing balance and spends exactly
 * it, and a second recompute sees cycles that complete. Those spend rows
 * survive the wipe by design — `repurchase_wallet_used` is the sole member of
 * {@see DerivedTables::PRESERVED_WALLET_TYPES}, because money that has already
 * been applied to an order is not the replay's to invent or destroy.
 *
 * A settled month can no longer be month-rebuilt, and that is correct rather
 * than unfortunate: {@see MonthRebuilder::spentRepurchaseCredit()} refuses to
 * delete credits whose repurchase half has been spent on an order. Settle the
 * months you want to pay THROUGH, and leave the month you intend to rebuild
 * unsettled — its own credits accrue untouched and its rebuild stays open.
 *
 * Every row it writes is tagged — `order_no` starts `PS-`, `idempotency_key`
 * starts `payseed:` — so `--rollback` removes exactly this fixture and nothing
 * a human created. Gated by {@see RecomputeGuard}: same three locks as the
 * recompute, because this is just as unwelcome in a database anybody depends
 * on, and one gate is better than two that can disagree.
 *
 * DELETE THIS COMMAND before production launch, alongside
 * {@see FortuneStagingE2ESeedCommand} (R-102).
 */
final class SeedPayingPeriodCommand extends Command
{
    protected $signature = 'compensation:seed-paying-period
                            {--titles-on=2026-07-02 : Day the personal-BV / title orders are placed}
                            {--month=2026-08 : The month to make pay (YYYY-MM)}
                            {--night= : An extra paying day (YYYY-MM-DD); defaults to yesterday}
                            {--settle-repurchase= : Second pass — spend the repurchase wallet at the close of these cycles (YYYY-MM, comma-separated)}
                            {--rollback : Delete every order a previous run of this command seeded}
                            {--force : Skip the typed confirmation}';

    protected $description = 'TEST ENVIRONMENTS ONLY — seed paid orders that make a month and a night actually pay every bonus';

    /** Orders carrying this prefix are this fixture's, and only this fixture's. */
    private const TAG = 'PS-';

    /**
     * Personal-BV order per placement depth, as [variant id, qty].
     *
     * Sized against gsb_slabs.title_min_bv_paise: 68,000 BV reaches the slab-5
     * title, 32,000 slab 4, 15,000 slab 3, 7,000 slab 2, 3,000 slab 1. Depth
     * decides it because depth is what decides how much group BV can ever
     * arrive underneath — a title above that is a ceiling nothing reaches.
     *
     * @var array<int, array{int, int}>
     */
    private const TITLE_PLAN = [
        0 => [4, 7],   // 70,000 BV — slab 5 title
        1 => [4, 4],   // 40,000 BV — slab 4 title
        2 => [4, 2],   // 20,000 BV — slab 3 title
    ];

    /** Depths 3-6: 12,000 BV, the slab-2 title. */
    private const TITLE_MID = [9, 2];

    /** Depth 7 and below: 6,000 BV, the slab-1 title — and clear of the 600 BV gate. */
    private const TITLE_DEEP = [9, 1];

    /** 15,000 BV — exactly slab 1's matched threshold, so one order per leg moves a slab. */
    private const GROUP_BV_VARIANT = 7;

    /** 600 BV — the repurchase anchor. */
    private const REPURCHASE_VARIANT = 6;

    /** One in twelve earners skips their repurchase, to keep the forfeit path populated. */
    private const FORFEIT_EVERY = 12;

    /**
     * One group-BV order in four is collected from the Arete centre instead of
     * shipped. On the staging tree that is ~163 orders and 24.4 lakh BV a
     * month, which credits about ₹20,000 — comfortably UNDER the ₹1,00,000
     * monthly cap, so this exercises the rate and not the ceiling. Raise it if
     * the cap itself is what needs rehearsing.
     */
    private const CENTRE_EVERY = 4;

    /** Memo prefix on the wallet rows this fixture writes, so --rollback can find them. */
    private const WALLET_TAG = 'payseed:';

    /** carrier_code on the shipments this fixture writes, for the same reason. */
    private const SHIPMENT_TAG = 'PAYSEED';

    /** @var array<int, array<string, mixed>> variant id => row */
    private array $variants = [];

    /** @var array<int, array<string, mixed>> distributor id => row */
    private array $distributors = [];

    /** @var array<int, int> distributor id => customer id */
    private array $customers = [];

    /** The centre orders are collected from, or null if the environment has none. */
    private ?int $areteCenterId = null;

    /** Counts group-BV orders so every CENTRE_EVERY-th one is a collection. */
    private int $centreCounter = 0;

    /** @var list<array<string, mixed>> */
    private array $orderRows = [];

    /** @var list<array<string, mixed>> */
    private array $itemRows = [];

    /** @var list<array<string, mixed>> */
    private array $bvRows = [];

    /** @var list<array<string, mixed>> */
    private array $shipmentRows = [];

    private int $nextOrderId = 1;

    public function handle(RecomputeGuard $guard): int
    {
        try {
            $guard->ensurePermitted();
        } catch (RecomputeNotPermitted $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('rollback')) {
            return $this->rollback();
        }

        if (($settle = (string) $this->option('settle-repurchase')) !== '') {
            return $this->settleRepurchase($settle);
        }

        $month = Carbon::parse($this->option('month').'-01')->startOfMonth();
        $titlesOn = Carbon::parse((string) $this->option('titles-on'))->startOfDay();
        $night = Carbon::parse(((string) $this->option('night')) ?: Carbon::yesterday()->toDateString())->startOfDay();

        if (! $this->option('force') && ! $this->confirm(
            sprintf('Seed fixture orders into %s for %s? ', $guard->targetDatabase(), $month->format('F Y')),
        )) {
            $this->line('Nothing written.');

            return self::SUCCESS;
        }

        $this->load();

        $titles = $this->seedTitles($titlesOn);
        // The month before the target month as well, and not for symmetry: the
        // Growth Booster pool is gated on the PRIOR month's rank, so a single
        // seeded month produces a rank nothing can spend and GBB pays nobody.
        $runway = $this->seedGroupBv($month->copy()->subMonth());
        $target = $this->seedGroupBv($month);
        $group = [
            'orders' => $runway['orders'] + $target['orders'],
            'bv' => $runway['bv'] + $target['bv'],
        ];
        $repurchase = $this->seedRepurchase($month);
        $nightly = $this->seedNight($night);

        $this->flush();

        $this->newLine();
        $this->table(['Part', 'Orders', 'BV'], [
            ['Titles / income gate', $titles['orders'], number_format($titles['bv'] / 100)],
            ['Group BV (leaves)', $group['orders'], number_format($group['bv'] / 100)],
            ['Repurchase', $repurchase['orders'], number_format($repurchase['bv'] / 100)],
            ['The paying night', $nightly['orders'], number_format($nightly['bv'] / 100)],
        ]);

        $this->newLine();
        $this->info('Seeded. Nothing derived has been computed yet — next:');
        $this->line('  1. php artisan compensation:recompute-all --horizon=now --force');
        $this->line('  2. php artisan compensation:seed-paying-period --settle-repurchase=YYYY-MM');
        $this->line('  3. php artisan compensation:recompute-all --horizon=now --force');
        $this->newLine();
        $this->line('Step 2 takes the month a cycle CLOSES in, which is not the month it covers:');
        $this->line('repurchase_cycles runs 30 days from a distributor\'s first order, so a window');
        $this->line('opened 02 Jul is judged on 01 Aug and settling it is --settle-repurchase=2026-08.');
        $this->line('Read the due dates rather than assuming the calendar:');
        $this->line('  select cycle_start_date, due_date, status, count(*) from repurchase_cycles group by 1,2,3;');
        $this->newLine();
        $this->line('Settle the cycles you want to pay THROUGH, and stop before the one whose month');
        $this->line('you mean to rebuild — a settled month\'s rebuild is refused, by design.');

        if ($this->areteCenterId === null) {
            $this->warn('No active distributor-owned Arete centre — the ADC engine will credit nobody.');
        }

        return self::SUCCESS;
    }

    /** Load the catalogue, the tree and each distributor's customer record. */
    private function load(): void
    {
        // Artisan resolves this command once and reuses the instance, so a
        // second run inside the same process inherits the first run's staged
        // rows and re-inserts them under ids that are now taken. One process
        // per run hides it; a test that seeds, rolls back and seeds again does
        // not, and neither would `--rollback && --seed` chained in tinker.
        $this->orderRows = [];
        $this->itemRows = [];
        $this->bvRows = [];
        $this->shipmentRows = [];
        $this->customers = [];
        $this->centreCounter = 0;

        // The HSN code lives on the product, not the variant, and the order
        // line snapshots it — an invoice has to state the code that was in
        // force when the sale happened, not the one the catalogue holds today.
        foreach (
            DB::table('product_variants')
                ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
                ->select('product_variants.*', 'products.hsn_code')
                ->get() as $variant
        ) {
            $this->variants[(int) $variant->id] = (array) $variant;
        }

        foreach (DB::table('distributors')->select('id', 'depth', 'placement_parent_id', 'user_id')->get() as $row) {
            $this->distributors[(int) $row->id] = (array) $row;
        }

        foreach (DB::table('customers')->whereNotNull('distributor_id')->get(['id', 'distributor_id']) as $row) {
            $this->customers[(int) $row->distributor_id] = (int) $row->id;
        }

        $this->nextOrderId = ((int) DB::table('orders')->max('id')) + 1;

        // A company centre never earns, whatever its owner column says, and a
        // centre awaiting its owner has nobody to pay — so the engine only ever
        // credits an active distributor-owned one. Picking any other kind here
        // would seed BV that lands nowhere.
        $centre = DB::table('arete_centers')
            ->where('status', 'active')
            ->where('centre_type', 'distributor')
            ->whereNotNull('assigned_distributor_id')
            ->orderBy('id')
            ->value('id');

        $this->areteCenterId = $centre === null ? null : (int) $centre;
    }

    /**
     * One personal order each, so every distributor clears the 600 BV income
     * gate and holds a title the engine can pay against.
     *
     * @return array{orders: int, bv: int}
     */
    private function seedTitles(Carbon $on): array
    {
        $orders = 0;
        $bv = 0;

        foreach ($this->distributors as $id => $distributor) {
            $depth = (int) $distributor['depth'];

            [$variantId, $qty] = match (true) {
                isset(self::TITLE_PLAN[$depth]) => self::TITLE_PLAN[$depth],
                $depth <= 6 => self::TITLE_MID,
                default => self::TITLE_DEEP,
            };

            $bv += $this->order($id, $on->copy()->setTime(10, 0), [[$variantId, $qty]]);
            $orders++;
        }

        return ['orders' => $orders, 'bv' => $bv];
    }

    /**
     * Every leaf buys once a week through the month.
     *
     * Leaves specifically: their BV rolls up to every ancestor and is never
     * diluted by having a leg of their own, which is what puts matched volume
     * on both sides of everyone above them.
     *
     * @return array{orders: int, bv: int}
     */
    private function seedGroupBv(Carbon $month): array
    {
        $orders = 0;
        $bv = 0;
        $leaves = $this->leaves();
        $end = $month->copy()->endOfMonth();

        foreach ($leaves as $index => $leafId) {
            // Stagger the start across the first week so the days fill evenly
            // rather than every leaf buying on the same four dates.
            $day = $month->copy()->addDays(2 + ($index % 7));

            while ($day->lessThanOrEqualTo($end)) {
                $collected = (++$this->centreCounter % self::CENTRE_EVERY) === 0;

                $bv += $this->order(
                    $leafId,
                    $day->copy()->setTime(11, 0),
                    [[self::GROUP_BV_VARIANT, 1]],
                    $collected ? $this->areteCenterId : null,
                );
                $orders++;
                $day->addWeek();
            }
        }

        return ['orders' => $orders, 'bv' => $bv];
    }

    /**
     * The earners repurchase, except one in twelve.
     *
     * The skipped ones are not an oversight: a fixture where every cycle is
     * fulfilled never exercises the forfeit verdict, and forfeiture is the
     * half of the repurchase engine that decides a day pays nothing.
     *
     * @return array{orders: int, bv: int}
     */
    private function seedRepurchase(Carbon $month): array
    {
        $orders = 0;
        $bv = 0;
        $seen = 0;

        foreach (array_keys($this->distributors) as $id) {
            // Every depth, not just the top seven. Deep distributors earn once
            // the tree is filled, and each repurchase order is also the thing a
            // wallet spend attaches to: the ledger's unique key on
            // (type, reference_type, reference_id) allows one spend per order,
            // so settling two instants needs two orders. Skipping depth > 6 left
            // 226 distributors holding a single title order, 97 of them unable
            // to settle the second instant at all, and August forfeited for
            // them — an exclusion that cost more than the orders it saved.
            $seen++;

            if ($seen % self::FORFEIT_EVERY === 0) {
                continue;
            }

            // Weekly, and through the month after the target one as well.
            //
            // Not for the BV — one order a month already cleared the 600 BV
            // requirement. It is that a repurchase order is the only thing a
            // wallet spend can attach to, and the ledger's unique key on
            // (type, reference_type, reference_id) allows exactly one spend per
            // order. Two settlement instants consumed a distributor's only two
            // orders, and every later round then reported "had a balance but no
            // seeded order to apply it to" and spent nothing — 146 cycles stayed
            // suspended no matter how many times the loop ran. Four orders a
            // month leaves headroom for the top-up rounds that pool drift makes
            // necessary, and buying weekly is what a real distributor does
            // anyway.
            foreach ([$month->copy()->subMonth(), $month, $month->copy()->addMonth()] as $cycleMonth) {
                foreach ([7, 14, 21, 28] as $dayOffset) {
                    $at = $cycleMonth->copy()->addDays($dayOffset)->setTime(12, 0);

                    // Never seed a paid order in the future: the replay walks
                    // the calendar to today, so an order dated after it would
                    // sit unpropagated and silently short every leg it belongs
                    // to.
                    if ($at->greaterThan(Carbon::now())) {
                        continue;
                    }

                    $bv += $this->order(
                        $id,
                        $at,
                        // Two units, not one: rank_tiers.repurchase_bv_paise asks
                        // 1,000 BV at Silver and 1,100 at Pearl, so a single 600 BV
                        // anchor purchase satisfies the repurchase engine and still
                        // fails every rank's own repurchase requirement.
                        [[self::REPURCHASE_VARIANT, 2]],
                    );
                    $orders++;
                }
            }
        }

        return ['orders' => $orders, 'bv' => $bv];
    }

    /**
     * A burst on one recent day, so the newest night is a night that pays.
     *
     * R-91: a night may only be rebuilt while it is the newest one, so the
     * paying night the rebuild rehearsal needs cannot be a month old.
     *
     * @return array{orders: int, bv: int}
     */
    private function seedNight(Carbon $night): array
    {
        $orders = 0;
        $bv = 0;

        foreach ($this->leaves() as $leafId) {
            $bv += $this->order($leafId, $night->copy()->setTime(13, 0), [[self::GROUP_BV_VARIANT, 1]]);
            $orders++;
        }

        return ['orders' => $orders, 'bv' => $bv];
    }

    /**
     * Distributors with no placement children — the bottom of the tree.
     *
     * @return list<int>
     */
    private function leaves(): array
    {
        $parents = [];

        foreach ($this->distributors as $distributor) {
            if ($distributor['placement_parent_id'] !== null) {
                $parents[(int) $distributor['placement_parent_id']] = true;
            }
        }

        return array_values(array_filter(
            array_keys($this->distributors),
            static fn (int $id): bool => ! isset($parents[$id]),
        ));
    }

    /**
     * Stage one paid order, its items and its BV ledger entry. Returns the BV.
     *
     * @param  list<array{int, int}>  $lines  [variant id, qty]
     * @param  int|null  $areteCenterId  the centre this order is collected
     *                                   from; null ships it. A non-null value
     *                                   also stages the shipment and its
     *                                   handover, which the ADC engine reads
     *                                   as the evidence the work was done.
     */
    private function order(int $distributorId, Carbon $at, array $lines, ?int $areteCenterId = null): int
    {
        $orderId = $this->nextOrderId++;
        $orderNo = self::TAG.$at->format('ymd').'-'.strtoupper(Str::random(6));
        $stamp = $at->toDateTimeString();

        $subtotal = 0;
        $gst = 0;
        $bv = 0;

        foreach ($lines as [$variantId, $qty]) {
            $variant = $this->variants[$variantId];
            $unit = (int) ($variant['sale_price_paise'] ?: $variant['mrp_paise']);
            $rate = (int) $variant['gst_rate_bp'];

            // The catalogue price is GST-inclusive, so the taxable value is
            // backed out of it — matching how a real checkout stores the line.
            $lineTotal = $unit * $qty;
            $taxable = (int) round($lineTotal * 10000 / (10000 + $rate));
            $lineGst = $lineTotal - $taxable;
            $lineBv = (int) $variant['bv_paise'] * $qty;

            $this->itemRows[] = [
                'order_id' => $orderId,
                'product_variant_id' => $variantId,
                'product_name_snapshot' => (string) $variant['name'],
                'variant_sku_snapshot' => (string) $variant['variant_sku'],
                'hsn_code_snapshot' => (string) ($variant['hsn_code'] ?? ''),
                'qty' => $qty,
                'unit_price_paise' => $unit,
                'bv_paise' => $lineBv,
                'gst_rate_bp' => $rate,
                'taxable_value_paise' => $taxable,
                'gst_paise' => $lineGst,
                'line_total_paise' => $lineTotal,
                'created_at' => $stamp,
            ];

            $subtotal += $lineTotal;
            $gst += $lineGst;
            $bv += $lineBv;
        }

        $this->orderRows[] = [
            'id' => $orderId,
            'order_no' => $orderNo,
            'customer_id' => $this->customerFor($distributorId),
            'attributed_distributor_id' => $distributorId,
            'arete_center_id' => $areteCenterId,
            'delivery_type' => $areteCenterId === null ? 'ship' : 'collect',
            'attribution_source' => 'logged_in',
            'payment_method' => 'online',
            'status' => 'paid',
            'self_consumption' => 1,
            'subtotal_paise' => $subtotal,
            'gst_paise' => $gst,
            'total_paise' => $subtotal,
            'idempotency_key' => 'payseed:'.$orderNo,
            'placed_at' => $stamp,
            'paid_at' => $stamp,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];

        $this->bvRows[] = [
            'distributor_id' => $distributorId,
            'order_id' => $orderId,
            'bv_paise' => $bv,
            'type' => 'accrual',
            'effective_at' => $stamp,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];

        if ($areteCenterId !== null) {
            // Collected the next morning. The carrier code is the tag: it is
            // how --rollback tells a shipment this fixture wrote from one a
            // human did, which matters because shipments RESTRICT the order.
            $collectedAt = $at->copy()->addDay()->setTime(10, 0)->toDateTimeString();

            $this->shipmentRows[] = [
                'order_id' => $orderId,
                'arete_center_id' => $areteCenterId,
                'warehouse_code' => 'DEFAULT',
                'carrier_code' => self::SHIPMENT_TAG,
                'status' => 'at_centre',
                'at_centre_at' => $collectedAt,
                'collected_at' => $collectedAt,
                'created_at' => $stamp,
                'updated_at' => $collectedAt,
            ];
        }

        return $bv;
    }

    /**
     * The distributor's own customer record, created if they never bought.
     *
     * Orders cannot exist without one, and 110 of the 317 distributors on
     * staging had never placed an order and so had none.
     */
    private function customerFor(int $distributorId): int
    {
        if (isset($this->customers[$distributorId])) {
            return $this->customers[$distributorId];
        }

        $id = (int) DB::table('customers')->insertGetId([
            'user_id' => $this->distributors[$distributorId]['user_id'],
            'distributor_id' => $distributorId,
            'display_name' => 'Fixture '.$distributorId,
            'marketing_opt_in' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->customers[$distributorId] = $id;
    }

    /** Write the staged rows, children after their parents. */
    private function flush(): void
    {
        $bar = $this->output->createProgressBar(count($this->orderRows));
        $bar->start();

        foreach (array_chunk($this->orderRows, 500) as $chunk) {
            DB::table('orders')->insert($chunk);
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine();

        foreach (array_chunk($this->itemRows, 500) as $chunk) {
            DB::table('order_items')->insert($chunk);
        }

        foreach (array_chunk($this->bvRows, 500) as $chunk) {
            DB::table('bv_ledger_entries')->insert($chunk);
        }

        foreach (array_chunk($this->shipmentRows, 500) as $chunk) {
            DB::table('shipments')->insert($chunk);
        }
    }

    /**
     * Second pass: spend each named cycle's closing repurchase balance, so the
     * cycle completes and the months after it are not forfeited.
     *
     * The verdict {@see RepurchaseCycleService::resolveAtWindowEnd()} takes is
     * frozen at the window's LAST instant, and it asks two things: was the BV
     * bought, and did the wallet read zero. The first pass answers the BV; only
     * a spend answers the wallet, and it has to be dated inside the window or
     * the balance it clears is not the balance that gets judged. Hence 22:00 on
     * the due date — late enough to capture everything the engines credited
     * during the cycle, early enough to be inside it.
     *
     * @param  string  $months  comma-separated YYYY-MM
     */
    private function settleRepurchase(string $months): int
    {
        $written = 0;
        $skipped = 0;
        $rows = 0;
        $corrected = 0;

        // Orders already carrying a repurchase-wallet spend, this run's included.
        $claimed = DB::table('wallet_ledger_entries')
            ->where('type', 'repurchase_wallet_used')
            ->where('reference_type', 'order')
            ->whereNotNull('reference_id')
            ->pluck('reference_id')
            ->flip()
            ->all();

        // A rebuild's re-run (`monthly-close --restart`) stamps the credits it
        // re-derives at the REAL clock, where `compensation:recompute-all`
        // stamps them at the replayed scheduler clock. Both this settlement and
        // the engines read the wallet by `created_at`, so against a
        // rebuild-stamped ledger a month's credits sit outside the window and
        // the balance reads far too low — 24 distributors instead of 137, on
        // 2026-09-19.
        //
        // The discriminator is the TIME of day, not the date: a replay stamps a
        // credit at the engine's own cadence (00:05 to 04:00 — ADR-0016), and
        // today's cut-off legitimately carries today's date. A rebuild's re-run
        // stamps whatever o'clock the operator typed it at.
        $misstamped = DB::table('wallet_ledger_entries')
            ->where('type', 'repurchase_deduction')
            ->whereRaw('TIME(created_at) > ?', ['04:00:00'])
            ->count();

        // A warning rather than a refusal: the heuristic cannot tell a
        // rebuild's re-run from credit written by hand, and re-running this
        // command after a recompute now corrects its own spends in place, so
        // the failure it describes costs one more pass rather than a wrong
        // ledger anybody has to unpick.
        if ($misstamped > 0) {
            $this->warn(sprintf(
                '%d repurchase credit(s) are stamped at a wall-clock time no engine runs at, which is what a '
                .'rebuild\'s re-run leaves behind. Both this settlement and the engines read the wallet by '
                .'created_at, so those credits sit outside the window and the balance reads too low. If that is '
                .'what these are, run `compensation:recompute-all --horizon=now --force` and settle again.',
                $misstamped,
            ));
        }

        foreach (explode(',', $months) as $token) {
            $month = Carbon::parse(trim($token).'-01')->startOfMonth();

            $cycles = DB::table('repurchase_cycles')
                ->whereBetween('due_date', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
                ->get(['distributor_id', 'due_date']);

            // TWO instants matter, and settling only one is why the Growth
            // Booster stayed blocked through a month that otherwise paid.
            //
            //  - The repurchase CYCLE closes on its own due date, 30 days from
            //    the distributor's first order. That verdict decides the daily
            //    group-BV forfeit.
            //  - The monthly GATES — Growth Booster, Fortune, rank
            //    requalification, AO-GO — read
            //    {@see RepurchaseWalletGateService::clearedAtMonthEnd()},
            //    which asks about 23:59:59 on the last day of the CALENDAR
            //    month. The client separated these on 2026-09-05 and confirmed
            //    it on 2026-09-07.
            //
            // A cycle opened 02 Jul is judged on 01 Aug, so a spend that clears
            // it lands a day AFTER the July month-end the gates read, and July's
            // Growth Booster sees a full wallet. Both instants get a spend.
            $byDueDate = [];

            foreach ($cycles as $cycle) {
                $byDueDate[(string) $cycle->due_date][] = (int) $cycle->distributor_id;
            }

            $monthEnd = $month->copy()->endOfMonth()->toDateString();

            if (! isset($byDueDate[$monthEnd])) {
                $byDueDate[$monthEnd] = DB::table('distributors')->pluck('id')->map(
                    static fn ($id): int => (int) $id,
                )->all();
            }

            // Earliest instant first: each spend is sized on what is left after
            // the ones before it, so settling out of order would overspend.
            ksort($byDueDate);

            foreach ($byDueDate as $dueDate => $distributorIds) {
                $dueEnd = Carbon::parse($dueDate)->endOfDay();
                $spentAt = Carbon::parse($dueDate)->setTime(22, 0);
                $instantRows = [];

                $balances = DB::table('wallet_ledger_entries')
                    ->whereIn('distributor_id', $distributorIds)
                    ->whereIn('type', ['repurchase_deduction', 'repurchase_wallet_used'])
                    ->where('created_at', '<=', $dueEnd)
                    ->groupBy('distributor_id')
                    ->selectRaw(
                        "distributor_id, COALESCE(SUM(CASE WHEN type = 'repurchase_deduction' THEN amount_paise ELSE 0 END), 0) "
                        ."- COALESCE(SUM(CASE WHEN type = 'repurchase_wallet_used' THEN ABS(amount_paise) ELSE 0 END), 0) AS balance",
                    )
                    ->pluck('balance', 'distributor_id');

                // What THIS fixture already spent at this very instant, on a
                // previous run. `$balances` above has already subtracted it, so
                // zeroing the wallet means moving the existing row to
                // (its own amount + what is still left) rather than adding a
                // second one — `uniq_wallet_ledger_source` allows only one
                // spend per order, so an under-sized spend could otherwise only
                // be corrected by burning another order. That is what made the
                // settlement need three rounds and then starve.
                $existing = DB::table('wallet_ledger_entries')
                    ->where('type', 'repurchase_wallet_used')
                    ->where('memo', 'like', self::WALLET_TAG.'%')
                    ->where('created_at', $spentAt->toDateTimeString())
                    ->whereIn('distributor_id', $distributorIds)
                    ->pluck('amount_paise', 'distributor_id');

                // The order the credit is applied to has to exist, has to
                // predate the spend — a wallet debit pointing at an order
                // placed after it would be a refund waiting to misbehave — and
                // has to be one no other spend already claims. The ledger holds
                // a unique key on (type, reference_type, reference_id), which is
                // what stops one order's repurchase credit being spent twice,
                // so settling two instants needs two orders.
                $candidates = [];

                foreach (
                    DB::table('orders')
                        ->where('order_no', 'like', self::TAG.'%')
                        ->where('status', 'paid')
                        ->where('paid_at', '<=', $spentAt)
                        ->whereIn('attributed_distributor_id', $distributorIds)
                        ->orderByDesc('id')
                        ->get(['id', 'attributed_distributor_id']) as $order
                ) {
                    $candidates[(int) $order->attributed_distributor_id][] = (int) $order->id;
                }

                foreach ($distributorIds as $distributorId) {
                    $balance = (int) ($balances[$distributorId] ?? 0);
                    $already = abs((int) ($existing[$distributorId] ?? 0));

                    if ($already > 0) {
                        // Corrects in BOTH directions: $balance below zero means
                        // the last run spent more than the replay went on to
                        // credit, and shrinking the row is what repairs it.
                        $want = $already + $balance;

                        if ($want === $already) {
                            continue;
                        }

                        DB::table('wallet_ledger_entries')
                            ->where('type', 'repurchase_wallet_used')
                            ->where('memo', 'like', self::WALLET_TAG.'%')
                            ->where('created_at', $spentAt->toDateTimeString())
                            ->where('distributor_id', $distributorId)
                            ->update(['amount_paise' => -max(0, $want)]);

                        $corrected++;

                        continue;
                    }

                    if ($balance <= 0) {
                        continue;
                    }

                    $orderId = null;

                    foreach ($candidates[$distributorId] ?? [] as $candidate) {
                        if (! isset($claimed[$candidate])) {
                            $orderId = $candidate;
                            break;
                        }
                    }

                    if ($orderId === null) {
                        $skipped++;

                        continue;
                    }

                    $claimed[$orderId] = true;

                    $instantRows[] = [
                        'distributor_id' => $distributorId,
                        'type' => 'repurchase_wallet_used',
                        'amount_paise' => -$balance,
                        'reference_id' => $orderId,
                        'reference_type' => 'order',
                        'memo' => self::WALLET_TAG.' repurchase wallet spent as at '.$dueEnd->toDateTimeString(),
                        'created_at' => $spentAt->toDateTimeString(),
                    ];
                    $written++;
                }

                // Written before the next instant is measured. Each spend is
                // sized on the balance the ones before it left behind, and that
                // balance is read back from the ledger — so holding these in
                // memory until the end makes every later instant spend the same
                // money again.
                foreach (array_chunk($instantRows, 500) as $chunk) {
                    DB::table('wallet_ledger_entries')->insert($chunk);
                }

                $rows += count($instantRows);
            }
        }

        $this->info(sprintf('Spent %d repurchase wallet balance(s) across %d row(s).', $written, $rows));

        if ($corrected > 0) {
            $this->info(sprintf('Re-sized %d spend(s) a later replay had left wrong.', $corrected));
        }

        if ($skipped > 0) {
            $this->warn(sprintf('%d had a balance but no seeded order to apply it to — left unspent.', $skipped));
        }

        $this->line('  php artisan compensation:recompute-all --horizon=now --force');

        return self::SUCCESS;
    }

    /**
     * Tables whose foreign key to `orders` is ON DELETE RESTRICT, so a row in
     * any of them pins the order it points at. None of them is written by this
     * fixture — they are what a human or a gateway callback leaves behind on a
     * fixture order — which is why finding one stops the rollback instead of
     * being cleared out from under whoever made it.
     *
     * @var list<string>
     */
    private const RESTRICTING_TABLES = [
        'invoices',
        'payment_events',
        'payment_intents',
        'refund_intents',
        'return_requests',
        'shipment_events',
        'shipments',
    ];

    /** Remove exactly what a previous run wrote — matched on the order tag. */
    private function rollback(): int
    {
        $ids = DB::table('orders')->where('order_no', 'like', self::TAG.'%')->pluck('id')->all();

        if ($ids === []) {
            $this->line('No fixture orders to remove.');

            return self::SUCCESS;
        }

        // Find what pins these orders BEFORE deleting anything. The delete used
        // to run children-first in chunks and trip a RESTRICT on the parent
        // half way through, which left the orders standing with their items
        // and BV already gone — 500 hollow orders that still counted as
        // seeded, so the next run seeded a second set on top of them.
        // Shipments are the one restricting table this fixture writes into, so
        // its own rows are cleared without ceremony and only somebody else's
        // count as a pin. Tagged on carrier_code, the same way the orders are
        // tagged on order_no.
        $ownShipments = 0;

        foreach (array_chunk($ids, 500) as $chunk) {
            $ownShipments += DB::table('shipments')
                ->whereIn('order_id', $chunk)
                ->where('carrier_code', self::SHIPMENT_TAG)
                ->count();
        }

        $pinned = [];

        foreach (self::RESTRICTING_TABLES as $table) {
            $count = 0;

            foreach (array_chunk($ids, 500) as $chunk) {
                $count += DB::table($table)
                    ->whereIn('order_id', $chunk)
                    ->when($table === 'shipments', fn ($q) => $q->where('carrier_code', '!=', self::SHIPMENT_TAG))
                    ->count();
            }

            if ($count > 0) {
                $pinned[$table] = $count;
            }
        }

        if ($pinned !== [] && ! $this->option('force')) {
            $this->error('Fixture orders are referenced by rows this fixture did not write:');

            foreach ($pinned as $table => $count) {
                $this->line(sprintf('  %-16s %d row(s)', $table, $count));
            }

            $this->newLine();
            $this->line('Those are a human\'s or a gateway\'s, not the seed\'s. Re-run with --force to');
            $this->line('delete them along with the orders, or resolve them first.');

            return self::FAILURE;
        }

        // One transaction: a rollback that fails half way is worse than one
        // that refuses, because what it leaves behind still answers to the tag.
        DB::transaction(function () use ($ids, $pinned, $ownShipments): void {
            if ($ownShipments > 0) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    DB::table('shipments')
                        ->whereIn('order_id', $chunk)
                        ->where('carrier_code', self::SHIPMENT_TAG)
                        ->delete();
                }

                $this->info(sprintf('Removed %d seeded shipment(s).', $ownShipments));
            }

            // The wallet spends are the one thing this fixture writes that a
            // recompute preserves, so leaving them behind would keep debiting a
            // wallet whose credits have been rebuilt from deleted orders.
            $spends = DB::table('wallet_ledger_entries')
                ->where('type', 'repurchase_wallet_used')
                ->where('memo', 'like', self::WALLET_TAG.'%')
                ->delete();

            if ($spends > 0) {
                $this->info(sprintf('Removed %d seeded repurchase wallet spend(s).', $spends));
            }

            foreach (array_keys($pinned) as $table) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    DB::table($table)->whereIn('order_id', $chunk)->delete();
                }
            }

            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table('bv_ledger_entries')->whereIn('order_id', $chunk)->delete();
                DB::table('order_items')->whereIn('order_id', $chunk)->delete();
                DB::table('orders')->whereIn('id', $chunk)->delete();
            }
        });

        foreach ($pinned as $table => $count) {
            $this->warn(sprintf('Also removed %d %s row(s) that referenced a fixture order.', $count, $table));
        }

        $this->info(sprintf('Removed %d fixture order(s).', count($ids)));
        $this->line('The derived rows they produced are still there — run compensation:recompute-all to clear them.');

        return self::SUCCESS;
    }
}
