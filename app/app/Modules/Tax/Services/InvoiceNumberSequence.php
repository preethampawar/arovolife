<?php

declare(strict_types=1);

namespace App\Modules\Tax\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Consecutive invoice numbers, one series per financial year (CGST Rule 46(b)).
 *
 * Format: `AL/2026-27/000001`.
 *
 * The previous implementation was `timestamp % 1000000`, which is neither
 * consecutive nor unique — two invoices raised 1,000,000 seconds apart collide,
 * and any audit that expects a gap-free series sees a random walk. Rule 46(b)
 * wants a consecutive serial number unique within the financial year, and the
 * only honest way to get one is a counter under a lock.
 *
 * India's financial year runs April to March, so an invoice raised in March
 * 2027 belongs to the 2026-27 series and one raised in April 2027 starts
 * 2027-28.
 */
final class InvoiceNumberSequence
{
    private const PREFIX = 'AL';

    /**
     * Reserve the next number in the current year's series.
     *
     * Must be called inside a transaction that also writes the invoice: the
     * row lock is what makes the series gap-free, and a reserved number whose
     * invoice was never written would leave a hole an auditor has to explain.
     */
    public function next(?Carbon $issuedAt = null): string
    {
        $financialYear = $this->financialYear($issuedAt ?? Carbon::now('Asia/Kolkata'));

        return DB::transaction(function () use ($financialYear): string {
            $row = DB::table('invoice_number_sequences')
                ->where('financial_year', $financialYear)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                DB::table('invoice_number_sequences')->insert([
                    'financial_year' => $financialYear,
                    'last_number' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $number = 1;
            } else {
                $number = (int) $row->last_number + 1;

                DB::table('invoice_number_sequences')
                    ->where('financial_year', $financialYear)
                    ->update(['last_number' => $number, 'updated_at' => now()]);
            }

            return sprintf('%s/%s/%06d', self::PREFIX, $financialYear, $number);
        });
    }

    /**
     * The Indian financial year a date falls in, as `2026-27`.
     */
    public function financialYear(Carbon $date): string
    {
        $startYear = $date->month >= 4 ? $date->year : $date->year - 1;

        return sprintf('%d-%02d', $startYear, ($startYear + 1) % 100);
    }
}
