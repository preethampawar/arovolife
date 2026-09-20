<?php

declare(strict_types=1);

/**
 * The boundaries of a statutory period, which are the whole point of the
 * class: a quarter here is the tax year's quarter, not the calendar's, and
 * getting that wrong files January's tax as Q1.
 */

use App\Modules\Tax\Support\TaxPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** @param array<string, string> $query */
function txPeriod(array $query): TaxPeriod
{
    return TaxPeriod::fromRequest(Request::create('/admin/reports/profit/gst', 'GET', $query));
}

it('reads an Indian financial-year quarter, April to June being the first', function (): void {
    $q1 = txPeriod(['quarter' => '2026-27-Q1']);
    $q3 = txPeriod(['quarter' => '2026-27-Q3']);

    expect($q1->from->format('Y-m-d H:i:s'))->toBe('2026-04-01 00:00:00')
        ->and($q1->to->format('Y-m-d H:i:s'))->toBe('2026-06-30 23:59:59')
        ->and($q3->from->format('Y-m-d H:i:s'))->toBe('2026-10-01 00:00:00')
        ->and($q3->to->format('Y-m-d H:i:s'))->toBe('2026-12-31 23:59:59')
        ->and($q3->kind)->toBe(TaxPeriod::KIND_QUARTER);
});

it('carries the fourth quarter into the next calendar year', function (): void {
    $q4 = txPeriod(['quarter' => '2026-27-Q4']);

    expect($q4->from->format('Y-m-d'))->toBe('2027-01-01')
        ->and($q4->to->format('Y-m-d'))->toBe('2027-03-31')
        ->and($q4->label)->toBe('FY 2026-27 · Q4 (Jan–Mar 2027)');
});

it('lets the quarter override the month', function (): void {
    $period = txPeriod(['quarter' => '2026-27-Q3', 'month' => '2026-05']);

    expect($period->from->format('Y-m-d'))->toBe('2026-10-01')
        ->and($period->monthValue())->toBe('');
});

it('reads a month when no quarter is given', function (): void {
    $period = txPeriod(['month' => '2026-05']);

    expect($period->from->format('Y-m-d H:i:s'))->toBe('2026-05-01 00:00:00')
        ->and($period->to->format('Y-m-d H:i:s'))->toBe('2026-05-31 23:59:59')
        ->and($period->kind)->toBe(TaxPeriod::KIND_MONTH)
        ->and($period->label)->toBe('May 2026')
        ->and($period->monthValue())->toBe('2026-05');
});

it('falls back to the last completed month, never the one still running', function (): void {
    CarbonImmutable::setTestNow('2026-09-20 11:00:00');

    $period = txPeriod([]);

    expect($period->from->format('Y-m-d'))->toBe('2026-08-01')
        ->and($period->to->format('Y-m-d'))->toBe('2026-08-31');
});

it('refuses a quarter whose two halves disagree, rather than reporting a different year', function (): void {
    CarbonImmutable::setTestNow('2026-09-20 11:00:00');

    // The second segment must be the year the financial year ends in.
    $period = txPeriod(['quarter' => '2026-30-Q1']);

    expect($period->kind)->toBe(TaxPeriod::KIND_MONTH)
        ->and($period->from->format('Y-m-d'))->toBe('2026-08-01');
});

it('offers only quarters that have started', function (): void {
    CarbonImmutable::setTestNow('2026-09-20 11:00:00');

    $options = TaxPeriod::quarterOptions(2);

    expect($options)->toHaveKey('2026-27-Q2')
        ->and($options)->toHaveKey('2025-26-Q4')
        // October 2026 has not arrived, so Q3 of the running year is not offered.
        ->and($options)->not->toHaveKey('2026-27-Q3')
        ->and($options['2026-27-Q2'])->toBe('FY 2026-27 · Q2 (Jul–Sep 2026)');
});

it('ends on an exclusive upper bound, so no millisecond belongs to no period', function (): void {
    $may = txPeriod(['month' => '2026-05']);
    $q4 = txPeriod(['quarter' => '2026-27-Q4']);

    // `to` is the last second of the last day and is what the page shows, but
    // a datetime(3) column carries 999 more milliseconds after it — they are
    // reported by comparing against this bound instead.
    expect($may->toExclusive->format('Y-m-d H:i:s'))->toBe('2026-06-01 00:00:00')
        ->and($may->to->format('Y-m-d H:i:s'))->toBe('2026-05-31 23:59:59')
        // Q4 is January–March, so its bound rolls into the next financial year.
        ->and($q4->toExclusive->format('Y-m-d H:i:s'))->toBe('2027-04-01 00:00:00')
        ->and($q4->to->format('Y-m-d H:i:s'))->toBe('2027-03-31 23:59:59');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});
