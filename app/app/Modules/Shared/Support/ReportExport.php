<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Modules\Compensation\Services\Recompute\RecomputeState;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Report download format selection, in one place.
 *
 * Every human-facing report on the platform offers the same two formats off
 * the same URL: `?format=xlsx` (the default) and `?format=csv`. Both read the
 * same `$columns` / `$rows`, so a column added to a report cannot appear in one
 * format and not the other — which is the whole reason this is a facade rather
 * than two parallel code paths in every controller.
 *
 * The NEFT bank uploads deliberately do not come through here: the bank's
 * portal parses CSV and nothing else. See HandlesPayoutBatchActions::exportNeft.
 */
final class ReportExport
{
    public const FORMAT_XLSX = 'xlsx';

    public const FORMAT_CSV = 'csv';

    /**
     * @param  string  $filename  Without extension, e.g. 'gsb-calculation-2026-09-12'
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public static function respond(Request $request, string $filename, array $columns, iterable $rows): StreamedResponse
    {
        $projectedThrough = self::projectedThrough();

        if ($projectedThrough !== null) {
            // A spreadsheet outlives the page it was downloaded from, and the
            // banner that said "these figures are simulated" does not travel
            // with it. On a projected test environment the marker goes into the
            // two places a file carries with it — its name, and its first row —
            // so a workbook of next month's bonuses cannot be mistaken for a
            // record of what anybody earned (hard rule 3).
            $filename = 'PROJECTED-'.$projectedThrough->format('Y-m-d').'-'.$filename;
            $rows = self::withProjectionNotice($projectedThrough->format('d M Y H:i'), $columns, $rows);
        }

        return self::formatFor($request) === self::FORMAT_CSV
            ? Csv::stream($filename, $columns, $rows)
            : SheetWriter::stream($filename, $columns, $rows);
    }

    /**
     * The instant a standing projection simulated through, or null on a normal
     * environment — which is every production one, where {@see RecomputeState}
     * answers without a query.
     *
     * Never lets a reporting download fail over its own disclosure: if the state
     * cannot be read the file is served unmarked rather than not at all, and the
     * banner on the page the operator downloaded it from still stands.
     */
    private static function projectedThrough(): ?Carbon
    {
        try {
            return app(RecomputeState::class)->projectedThrough();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The notice row, then the data. A generator so a `cursor()`-backed export
     * stays lazy — several of these reports are unbounded by design.
     *
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return iterable<int, array<string, mixed>>
     */
    private static function withProjectionNotice(string $through, array $columns, iterable $rows): iterable
    {
        $firstKey = $columns[0]['key'] ?? null;

        if ($firstKey === null) {
            return $rows;
        }

        return (function () use ($firstKey, $through, $rows): iterable {
            yield [$firstKey => sprintf(
                'PROJECTED FIGURES — simulated through %s on a test environment. Not earned, not payable.',
                $through,
            )];

            yield from $rows;
        })();
    }

    /**
     * XLSX unless the request explicitly asks for CSV. Anything unrecognised
     * falls back to XLSX rather than erroring — a mistyped format should hand
     * the operator a workbook, not a 500.
     *
     * Both `format` and the legacy `export` parameter are honoured, because the
     * inventory reports have shipped `?export=csv` links since the module
     * landed and somebody has those bookmarked.
     */
    public static function formatFor(Request $request): string
    {
        $requested = $request->query('format') ?? $request->query('export') ?? '';
        $requested = strtolower(is_string($requested) ? $requested : '');

        return $requested === self::FORMAT_CSV ? self::FORMAT_CSV : self::FORMAT_XLSX;
    }
}
