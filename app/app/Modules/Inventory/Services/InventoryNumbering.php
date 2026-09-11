<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Human-readable document numbers: `PO-2026-000001`.
 *
 * These are operational documents, not tax documents (contrast
 * `InvoiceNumberSequence`, which CGST Rule 46(b) requires to be gap-free). A
 * MAX()+1 under a row lock on the matching rows is gap-tolerant — a rolled
 * back create can leave a hole — which is an acceptable trade here for not
 * needing a separate sequence table (plan §4.11).
 */
final class InventoryNumbering
{
    /** @var array<string, array{0: string, 1: string}> */
    private const TARGETS = [
        'PO' => ['purchase_orders', 'po_no'],
        'GRN' => ['purchase_invoices', 'grn_no'],
        'TRF' => ['stock_transfers', 'transfer_no'],
        'ADJ' => ['stock_adjustments', 'adjustment_no'],
    ];

    public function next(string $prefix): string
    {
        if (! isset(self::TARGETS[$prefix])) {
            throw new InvalidArgumentException("Unknown document prefix [{$prefix}].");
        }

        [$table, $column] = self::TARGETS[$prefix];
        $year = now()->year;
        $like = "{$prefix}-{$year}-%";

        return DB::transaction(function () use ($table, $column, $prefix, $year, $like): string {
            // Lock the year's existing rows so two concurrent callers cannot
            // both read the same MAX() and mint the same number.
            DB::table($table)->where($column, 'like', $like)->lockForUpdate()->get([$column]);

            $max = DB::table($table)->where($column, 'like', $like)->max($column);
            $next = $max !== null ? ((int) substr((string) $max, -6)) + 1 : 1;

            return sprintf('%s-%d-%06d', $prefix, $year, $next);
        });
    }
}
