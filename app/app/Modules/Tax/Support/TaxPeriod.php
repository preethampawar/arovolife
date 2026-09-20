<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * The window a statutory report covers, as a calendar month or an Indian
 * financial-year quarter.
 *
 * A typed object rather than a pair of dates because the two returns these
 * reports feed are filed on different clocks — GSTR-1 and GSTR-3B monthly,
 * Form 26Q quarterly — and a quarter here is the tax year's quarter (Apr–Jun
 * is Q1), not the calendar's. Working that out at each call site is how a
 * January figure ends up filed as Q1.
 */
final readonly class TaxPeriod
{
    public const KIND_MONTH = 'month';

    public const KIND_QUARTER = 'quarter';

    private function __construct(
        public CarbonInterface $from,
        public CarbonInterface $to,
        /**
         * The first instant AFTER the period, for `>= from AND < toExclusive`.
         *
         * `$to` is the last second of the last day and is what the page shows,
         * but Laravel binds a datetime as `Y-m-d H:i:s` — so on a `datetime(3)`
         * column a `BETWEEN` against it drops everything in the last 999
         * milliseconds of the period, which then belongs to no period at all.
         * Every datetime comparison uses this instead; DATE columns keep `$to`.
         */
        public CarbonInterface $toExclusive,
        public string $label,
        public string $kind,
        /** The query value that selects this period again: `YYYY-MM` or `YYYY-YY-Qn`. */
        public string $selection,
    ) {}

    /**
     * `quarter=YYYY-YY-Qn` wins over `month=YYYY-MM`; neither given (or
     * neither readable) means the last completed calendar month, because a
     * report of a month still running reconciles to nothing.
     */
    public static function fromRequest(Request $request): self
    {
        $quarter = self::parseQuarter(trim((string) $request->query('quarter', '')));

        if ($quarter !== null) {
            return $quarter;
        }

        $month = self::parseMonth(trim((string) $request->query('month', '')));

        if ($month !== null) {
            return $month;
        }

        return self::forMonth(CarbonImmutable::now()->startOfMonth()->subMonth());
    }

    /**
     * The quarters a filer could plausibly want, newest first, stopping at the
     * one now running — a quarter that has not started has no figures.
     *
     * @return array<string, string> value → label
     */
    public static function quarterOptions(int $yearsBack = 3): array
    {
        $now = CarbonImmutable::now();
        $currentFy = $now->month >= 4 ? $now->year : $now->year - 1;

        $options = [];

        for ($fy = $currentFy; $fy > $currentFy - $yearsBack; $fy--) {
            for ($quarter = 4; $quarter >= 1; $quarter--) {
                $value = self::quarterKey($fy, $quarter);
                $period = self::parseQuarter($value);

                if ($period === null || $period->from->greaterThan($now)) {
                    continue;
                }

                $options[$value] = $period->label;
            }
        }

        return $options;
    }

    /** The `quarter=` value that reselects this period, or '' when it is a month. */
    public function quarterValue(): string
    {
        return $this->kind === self::KIND_QUARTER ? $this->selection : '';
    }

    /** The `month=` value that reselects this period, or '' when it is a quarter. */
    public function monthValue(): string
    {
        return $this->kind === self::KIND_MONTH ? $this->selection : '';
    }

    private static function parseMonth(string $value): ?self
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value) !== 1) {
            return null;
        }

        return self::forMonth(CarbonImmutable::parse($value.'-01')->startOfDay());
    }

    private static function parseQuarter(string $value): ?self
    {
        if (preg_match('/^(\d{4})-(\d{2})-Q([1-4])$/', $value, $matches) !== 1) {
            return null;
        }

        $fyStart = (int) $matches[1];

        // The second segment is the year the financial year ENDS in. Refusing a
        // mismatch keeps `2026-30-Q1` from quietly reporting FY 2026-27.
        if ((int) $matches[2] !== ($fyStart + 1) % 100) {
            return null;
        }

        $quarter = (int) $matches[3];
        $startMonth = [1 => 4, 2 => 7, 3 => 10, 4 => 1][$quarter];
        $startYear = $quarter === 4 ? $fyStart + 1 : $fyStart;

        $from = CarbonImmutable::parse(sprintf('%04d-%02d-01', $startYear, $startMonth))->startOfDay();
        $to = $from->addMonths(2)->endOfMonth();

        return new self(
            $from,
            $to,
            $from->addMonths(3)->startOfDay(),
            sprintf(
                'FY %04d-%02d · Q%d (%s–%s)',
                $fyStart,
                ($fyStart + 1) % 100,
                $quarter,
                $from->format('M'),
                $to->format('M Y'),
            ),
            self::KIND_QUARTER,
            self::quarterKey($fyStart, $quarter),
        );
    }

    private static function forMonth(CarbonImmutable $start): self
    {
        return new self(
            $start->startOfMonth(),
            $start->endOfMonth(),
            $start->startOfMonth()->addMonth(),
            $start->format('F Y'),
            self::KIND_MONTH,
            $start->format('Y-m'),
        );
    }

    private static function quarterKey(int $fyStart, int $quarter): string
    {
        return sprintf('%04d-%02d-Q%d', $fyStart, ($fyStart + 1) % 100, $quarter);
    }
}
