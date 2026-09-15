<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Services\DTOs\LandedCostAllocation;
use App\Modules\Inventory\Services\DTOs\LandedCostLine;
use InvalidArgumentException;

/**
 * Spreads a GRN's freight, insurance, handling and other charges across its
 * lines, turning a supplier price into a landed cost.
 *
 * This is the single owner of that arithmetic (plan D5). Nothing else in the
 * codebase may divide charges across lines — PurchaseInvoiceService calls this,
 * and so does the backfill command.
 *
 * Pure: no DB, no clock, no config. That is what makes the exactness property
 * testable at scale.
 *
 * ## Why largest-remainder
 *
 * The obvious `intdiv(charges * weight, totalWeight)` per line loses up to one
 * paise per line to truncation. On a 40-line GRN that is 39 paise of cost that
 * never reaches a batch. Largest-remainder hands those leftover paise to the
 * lines with the biggest fractional claim, so the allocated shares sum exactly
 * to the charge total for every possible input.
 *
 * Ties break by line index, so the same GRN always allocates the same way —
 * re-saving a draft must not shuffle costs between lines.
 */
final class LandedCostAllocator
{
    public const BASIS_VALUE = 'value';

    public const BASIS_QTY = 'qty';

    public const BASIS_WEIGHT = 'weight';

    public const BASES = [self::BASIS_VALUE, self::BASIS_QTY, self::BASIS_WEIGHT];

    /**
     * @param  list<array{qty: int, taxable_value_paise: int, weight_g?: int}>  $lines
     */
    public function allocate(array $lines, int $chargesPaise, string $basis = self::BASIS_VALUE): LandedCostAllocation
    {
        if (! in_array($basis, self::BASES, true)) {
            throw new InvalidArgumentException("Unknown landed-cost allocation basis [{$basis}].");
        }

        if ($chargesPaise < 0) {
            throw new InvalidArgumentException('Landed-cost charges cannot be negative.');
        }

        if ($lines === []) {
            return new LandedCostAllocation([], $basis, $chargesPaise);
        }

        $shares = $this->shareOut($chargesPaise, $this->weights($lines, $basis));

        $out = [];

        foreach ($lines as $i => $line) {
            $qty = $line['qty'];
            $landedValue = $line['taxable_value_paise'] + $shares[$i];

            $out[] = new LandedCostLine(
                qty: $qty,
                taxableValuePaise: $line['taxable_value_paise'],
                allocatedChargesPaise: $shares[$i],
                // A zero-qty line cannot have a per-unit cost. It also cannot
                // hold stock, so 0 is the honest answer rather than a div-by-zero.
                landedUnitCostPaise: $qty > 0 ? (int) round($landedValue / $qty) : 0,
            );
        }

        return new LandedCostAllocation($out, $basis, $chargesPaise);
    }

    /**
     * The per-line figure the charges are divided in proportion to.
     *
     * Falls back down the ladder when a basis carries no signal: a GRN of
     * zero-value samples still has quantities, and a GRN of weightless items
     * still has lines. Without the ladder, a zero total weight would silently
     * drop every paise of freight.
     *
     * @param  list<array{qty: int, taxable_value_paise: int, weight_g?: int}>  $lines
     * @return list<int>
     */
    private function weights(array $lines, string $basis): array
    {
        $by = static fn (callable $f): array => array_map($f, $lines);

        $weights = match ($basis) {
            self::BASIS_VALUE => $by(static fn (array $l): int => max(0, (int) $l['taxable_value_paise'])),
            self::BASIS_QTY => $by(static fn (array $l): int => max(0, (int) $l['qty'])),
            self::BASIS_WEIGHT => $by(static fn (array $l): int => max(0, (int) ($l['weight_g'] ?? 0) * (int) $l['qty'])),
            default => $by(static fn (array $l): int => max(0, (int) $l['taxable_value_paise'])),
        };

        if (array_sum($weights) > 0) {
            return array_values($weights);
        }

        $qty = $by(static fn (array $l): int => max(0, (int) $l['qty']));

        if (array_sum($qty) > 0) {
            return array_values($qty);
        }

        return array_fill(0, count($lines), 1);
    }

    /**
     * Largest-remainder apportionment. Sum of the result is exactly $total.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    private function shareOut(int $total, array $weights): array
    {
        $totalWeight = array_sum($weights);

        if ($total === 0 || $totalWeight <= 0) {
            return array_fill(0, count($weights), 0);
        }

        $shares = [];
        $remainders = [];

        foreach ($weights as $i => $weight) {
            $exact = $total * $weight;
            $shares[$i] = intdiv($exact, $totalWeight);
            $remainders[$i] = $exact % $totalWeight;
        }

        $leftover = $total - array_sum($shares);

        // Hand the truncated paise to the largest fractional claims first.
        // Sorting indices (not the shares) keeps the result addressable by
        // line position, and the index tiebreak keeps it deterministic.
        $order = array_keys($remainders);
        usort($order, static fn (int $a, int $b): int => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);

        foreach (array_slice($order, 0, $leftover) as $i) {
            $shares[$i]++;
        }

        ksort($shares);

        return array_values($shares);
    }
}
