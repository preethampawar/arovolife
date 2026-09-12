<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV-export helpers.
 *
 * Both halves of the export pair live here and in SheetWriter: safe() is shared
 * because Excel executes a formula out of an .xlsx cell exactly as it does out
 * of a .csv, so changing format retires nothing.
 */
final class Csv
{
    /**
     * Neutralise CSV formula injection. A spreadsheet (Excel / LibreOffice /
     * Google Sheets) treats a cell whose first character is `= + - @`, a tab,
     * or a carriage return as a formula — so a crafted value like
     * `=HYPERLINK(...)` or `=cmd|...` would execute when an operator opens the
     * export. Prefixing such a cell with a single quote forces it to render as
     * plain text. Empty and safe values pass through unchanged.
     */
    public static function safe(int|float|string|null $value): string
    {
        $string = (string) ($value ?? '');

        if ($string !== '' && in_array($string[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$string;
        }

        return $string;
    }

    /**
     * Stream a report as CSV. Signature-identical to SheetWriter::stream() so
     * ReportExport can pick between them without either caller caring.
     *
     * @param  string  $filename  Without extension
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public static function stream(string $filename, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, array_map(static fn (array $c): string => $c['label'], $columns));

            foreach ($rows as $row) {
                fputcsv($handle, array_map(static function (array $c) use ($row): string {
                    $value = $row[$c['key']] ?? '';

                    if ($value instanceof Carbon) {
                        $value = $value->toDateTimeString();
                    } elseif (is_bool($value)) {
                        $value = $value ? '1' : '0';
                    } elseif (is_float($value)) {
                        $value = number_format($value, 2, '.', '');
                    } elseif (! is_scalar($value)) {
                        $value = (string) $value;
                    }

                    return self::safe($value);
                }, $columns));
            }

            fclose($handle);
        }, $filename.'.csv', ['Content-Type' => 'text/csv']);
    }
}
