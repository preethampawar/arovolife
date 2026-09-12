<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Carbon\CarbonInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streaming XLSX writer — the XLSX half of {@see ReportExport}, which is the
 * single entry point every human-facing report download on the platform goes
 * through. Controllers never call this class directly; they call the facade,
 * which picks the format the request asked for.
 *
 * It streams row by row rather than building a workbook in memory: the BV
 * ledger and the GSB input/output exports are large enough that an in-memory
 * builder would exhaust PHP's memory limit, and those are precisely the reports
 * an operator reaches for when something has gone wrong.
 *
 * The formula-injection guard from {@see Csv::safe()} applies here unchanged.
 * Excel executes `=HYPERLINK(...)` out of an .xlsx cell exactly as it does out
 * of a .csv, so moving format does not retire that hardening — only string
 * cells are guarded, because passing an int through it would stringify the
 * value and cost Excel its right-alignment and its ability to sum the column.
 *
 * The NEFT bank-upload exports deliberately do NOT use this writer: the bank's
 * portal parses CSV. See HandlesPayoutBatchActions::exportNeft.
 */
final class SheetWriter
{
    /**
     * Width, in Excel character units, applied to every column of every export.
     *
     * @var float
     */
    private const COLUMN_WIDTH = 18.0;

    /**
     * @param  string  $filename  Without extension, e.g. 'gsb-calculation-2026-09-12'
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public static function stream(string $filename, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($columns, $rows): void {
                $options = new Options(SHOULD_USE_INLINE_STRINGS: true);
                $options->setColumnWidth(self::COLUMN_WIDTH, ...range(1, max(1, count($columns))));

                $writer = new Writer($options);
                $writer->openToFile('php://output');

                // Freeze the header so a 5,000-row export is still readable
                // once the operator scrolls.
                $writer->getCurrentSheet()->setSheetView((new SheetView)->withFreezeRow(2));

                $writer->addRow(Row::fromValuesWithStyle(
                    array_map(static fn (array $c): string => $c['label'], $columns),
                    new Style(fontBold: true),
                ));

                foreach ($rows as $row) {
                    $writer->addRow(Row::fromValues(array_map(
                        static fn (array $c): int|float|string => self::cell($row[$c['key']] ?? ''),
                        $columns,
                    )));
                }

                $writer->close();
            },
            $filename.'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * D5/D6 — dates become strings (matching what the CSVs emitted), numbers
     * stay numeric, everything else is string-guarded.
     */
    private static function cell(mixed $value): int|float|string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateTimeString();
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return Csv::safe(is_scalar($value) || $value === null ? $value : (string) $value);
    }
}
