<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\BvLedgerEntry;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\DTOs\BvBreakdown;
use App\Modules\Compliance\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for personal Business Volume accumulation
 * (ADR-0006, revised 2026-06-02). Personal BV is a distributor's own-purchase
 * (self-consumption) BV. It accrues when the order is paid
 * ({@see OrderStateMachine::markPaid()}) and is reversed if the order is later
 * refunded OR cancelled ({@see OrderStateMachine::cancel()}), so a non-sale
 * never leaves net BV behind (hard rule #2). BV is only ever attached to a
 * paid product sale — never to registration or recruitment.
 */
final class BvLedgerService
{
    /**
     * Ids per `whereIn` when warming the cache: a placeholder each, and MySQL
     * refuses a prepared statement past 65,535 of them.
     */
    private const WARM_CHUNK = 500;

    /**
     * Credit the order's BV to the attributing distributor's personal ledger.
     * Idempotent (UNIQUE(order_id, type)) and a no-op when the order is not a
     * self-consumption purchase, BV is zero, or self-purchase BV is disabled.
     */
    public function accrue(Order $order): void
    {
        if (! $this->shouldAccrue($order)) {
            return;
        }

        $order->loadMissing('items');
        $bvPaise = $order->bvTotalPaise();
        if ($bvPaise <= 0) {
            return;
        }

        $entry = BvLedgerEntry::firstOrCreate(
            ['order_id' => $order->id, 'type' => BvLedgerEntry::TYPE_ACCRUAL],
            [
                'distributor_id' => $order->attributed_distributor_id,
                'bv_paise' => $bvPaise,
                'effective_at' => Carbon::now(),
            ],
        );

        $this->audit('bv.accrued', $entry);
    }

    /**
     * Reverse a previously accrued order's BV. Writes a negative entry that nets
     * the order's BV back to zero. A no-op if nothing was accrued (e.g. an
     * unpaid COD order or one with no BV). Idempotent. Called when an order is
     * cancelled ({@see OrderStateMachine::cancel()}) and by the Phase-3 refund
     * pipeline — so a non-sale never leaves BV behind (hard rule #2).
     */
    public function reverse(Order $order): void
    {
        $accrual = BvLedgerEntry::where('order_id', $order->id)
            ->where('type', BvLedgerEntry::TYPE_ACCRUAL)
            ->first();

        if ($accrual === null) {
            return;
        }

        $entry = BvLedgerEntry::firstOrCreate(
            ['order_id' => $order->id, 'type' => BvLedgerEntry::TYPE_REVERSAL],
            [
                'distributor_id' => $accrual->distributor_id,
                'bv_paise' => -$accrual->bv_paise,
                'effective_at' => Carbon::now(),
            ],
        );

        $this->audit('bv.reversed', $entry);
    }

    /**
     * Audit a BV ledger write (before/after traceability for the sale-linked
     * credit store, R-22). Only logs an actual write, not an idempotent no-op.
     */
    private function audit(string $action, BvLedgerEntry $entry): void
    {
        if (! $entry->wasRecentlyCreated) {
            return;
        }

        AuditLog::create([
            'action' => $action,
            'subject_type' => 'bv_ledger_entry',
            'subject_id' => $entry->id,
            'details' => [
                'order_id' => $entry->order_id,
                'distributor_id' => $entry->distributor_id,
                'bv_paise' => $entry->bv_paise,
            ],
        ]);
    }

    /** @var array<int,int> Pre-loaded per-distributor totals keyed by distributor_id. */
    private array $personalBvCache = [];

    /**
     * Batch-load personal BV totals for a set of distributors in one query so
     * subsequent {@see totalPersonalBvPaise()} calls return from the cache.
     *
     * @param  int[]  $distributorIds
     */
    public function warmPersonalBvCache(array $distributorIds): void
    {
        if ($distributorIds === []) {
            return;
        }

        // Chunked, because `whereIn` becomes a placeholder per id and MySQL
        // refuses a prepared statement past 65,535 of them. The GSB cut-off
        // warms this for every active distributor at once, so on the scale
        // harness the whole cut-off died here between 10k and 100k (R-90).
        $totals = [];

        foreach (array_chunk($distributorIds, self::WARM_CHUNK) as $chunk) {
            $rows = BvLedgerEntry::query()
                ->whereIn('distributor_id', $chunk)
                ->selectRaw('distributor_id, SUM(bv_paise) as total')
                ->groupBy('distributor_id')
                ->pluck('total', 'distributor_id');

            foreach ($rows as $id => $total) {
                $totals[(int) $id] = (int) $total;
            }
        }

        foreach ($distributorIds as $id) {
            $this->personalBvCache[$id] = $totals[$id] ?? 0;
        }
    }

    /**
     * Drop the warmed totals.
     *
     * The cache is keyed by distributor and never expires, so a caller that
     * warms it for the whole population holds the whole population. A caller
     * working in chunks calls this at the end of each one: the next chunk warms
     * what it needs, and peak memory is the chunk rather than the roster.
     */
    public function forgetPersonalBvCache(): void
    {
        $this->personalBvCache = [];
    }

    /** A distributor's total accumulated personal BV (in paise). */
    public function totalPersonalBvPaise(int $distributorId): int
    {
        if (array_key_exists($distributorId, $this->personalBvCache)) {
            return $this->personalBvCache[$distributorId];
        }

        return (int) BvLedgerEntry::query()->forDistributor($distributorId)->sum('bv_paise');
    }

    /**
     * Net personal BV per distributor, each purchase counted in the window it
     * was made in: the accruals effective inside the window, less every
     * reversal of those same orders, whenever the reversal was recorded.
     *
     * The single reading of "own purchases in the month" for every monthly
     * condition — rank re-qualification, AO-GO, the Fortune tier gate and the
     * rank lifetime title (user decision 2026-10-17, the same rule plan §11.1
     * states: "A refund reduces the month the returned purchase belongs to,
     * not the month of the refund"). An accrual-only sum let a purchase
     * cancelled the same week keep a month qualifying.
     *
     * A reversal recorded after a month was judged changes the figure only
     * for later readers; nothing already credited is clawed back.
     *
     * @param  list<int>|null  $distributorIds  null = every distributor
     * @return array<int, int> distributor id => net BV in paise, never below 0
     */
    public function netPersonalBvByPurchaseDate(?array $distributorIds, ?Carbon $from, ?Carbon $to): array
    {
        if ($distributorIds === []) {
            return [];
        }

        $rows = DB::table('bv_ledger_entries as entry')
            ->join('bv_ledger_entries as purchase', function ($join): void {
                $join->on('purchase.order_id', '=', 'entry.order_id')
                    ->on('purchase.distributor_id', '=', 'entry.distributor_id')
                    ->where('purchase.type', '=', BvLedgerEntry::TYPE_ACCRUAL);
            })
            ->when($distributorIds !== null, fn ($q) => $q->whereIn('entry.distributor_id', $distributorIds))
            ->when($from !== null, fn ($q) => $q->where('purchase.effective_at', '>=', $from->copy()->startOfDay()))
            ->when($to !== null, fn ($q) => $q->where('purchase.effective_at', '<=', $to->copy()->endOfDay()))
            ->select('entry.distributor_id', DB::raw('SUM(entry.bv_paise) as net_bv'))
            ->groupBy('entry.distributor_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->distributor_id] = max(0, (int) $row->net_bv);
        }

        return $map;
    }

    /**
     * A distributor's accrued / reversed / net personal BV over an optional
     * date window (in paise). The single source of the accrued/reversed/net
     * definition for the admin BV-ledger report.
     */
    public function breakdownForDistributor(int $distributorId, ?Carbon $from = null, ?Carbon $to = null): BvBreakdown
    {
        $base = BvLedgerEntry::query()->forDistributor($distributorId)->dateRange($from, $to);

        $accrued = (int) (clone $base)->where('type', BvLedgerEntry::TYPE_ACCRUAL)->sum('bv_paise');
        $reversed = (int) (clone $base)->where('type', BvLedgerEntry::TYPE_REVERSAL)->sum('bv_paise');

        return new BvBreakdown($accrued, $reversed, $accrued + $reversed);
    }

    /**
     * The date a distributor's cumulative personal BV first reached the given
     * threshold (paise) — used as the anchor for their repurchase calendar
     * month, which per the 5 Jul 2026 rule keys off the 600-BV first-purchase
     * point (not the 3,000-BV Retailer title). Returns null if never reached.
     * Reversals net out, so a later-cancelled order can push the crossing date
     * later.
     */
    public function firstReachedBvPaiseAt(int $distributorId, int $thresholdPaise): ?Carbon
    {
        if ($thresholdPaise <= 0) {
            return null;
        }

        $running = 0;
        $entries = BvLedgerEntry::query()
            ->forDistributor($distributorId)
            ->orderBy('effective_at')
            ->orderBy('id')
            ->get(['bv_paise', 'effective_at']);

        foreach ($entries as $entry) {
            $running += (int) $entry->bv_paise;
            if ($running >= $thresholdPaise) {
                return Carbon::parse($entry->effective_at);
            }
        }

        return null;
    }

    /**
     * Net self-consumption (repurchase) BV in a window, in paise — how much of
     * the monthly repurchase obligation a distributor has completed. Counts
     * self-consumption accruals regardless of the self-purchase-earns-BV setting
     * (repurchase is a purchase obligation, not a compensation question), and
     * NETS reversals so a cancelled self-purchase order does not count toward the
     * obligation (same convention as {@see totalPersonalBvPaise()}).
     */
    public function selfPurchaseBvPaise(int $distributorId, ?Carbon $from = null, ?Carbon $to = null): int
    {
        return (int) BvLedgerEntry::query()
            ->forDistributor($distributorId)
            ->whereIn('type', [BvLedgerEntry::TYPE_ACCRUAL, BvLedgerEntry::TYPE_REVERSAL])
            ->dateRange($from, $to)
            ->whereExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('orders')
                    ->whereColumn('orders.id', 'bv_ledger_entries.order_id')
                    ->where('orders.self_consumption', true);
            })
            ->sum('bv_paise');
    }

    private function shouldAccrue(Order $order): bool
    {
        if ($order->attributed_distributor_id === null) {
            return false;
        }

        // Self-consumption (distributor buying for themselves) is gated by the
        // admin setting — the company may disable self-purchase BV to prevent
        // artificial volume accumulation.
        if ($order->self_consumption) {
            return $this->selfPurchaseEarnsBv();
        }

        // Customer sale via Easy Purchase / shared-cart link — the attributed
        // distributor always earns BV (hard rule #2: BV tied to product sale).
        return true;
    }

    private function selfPurchaseEarnsBv(): bool
    {
        return DB::table('settings')
            ->where('key', 'commerce.self_purchase.earns_bv')
            ->value('value') === 'true';
    }
}
