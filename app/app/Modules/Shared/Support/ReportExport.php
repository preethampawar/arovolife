<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        return self::formatFor($request) === self::FORMAT_CSV
            ? Csv::stream($filename, $columns, $rows)
            : SheetWriter::stream($filename, $columns, $rows);
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
