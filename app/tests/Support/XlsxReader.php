<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSpout\Reader\XLSX\Reader;

/**
 * Test-only helper for reading the body of an XLSX StreamedResponse back into
 * plain rows, so assertions can be made against actual cell values instead of
 * treating the binary workbook as a text blob (which silently breaks
 * substring-based expectations — see RegisterOfDirectSellersExportTest).
 */
final class XlsxReader
{
    /**
     * @return list<list<string>> every row of the first sheet, cells cast to string
     */
    public static function rows(string $binaryXlsxContent): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-').'.xlsx';
        file_put_contents($path, $binaryXlsxContent);

        try {
            $reader = new Reader;
            $reader->open($path);

            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_values(array_map(
                        static fn (mixed $cell): string => is_scalar($cell) ? (string) $cell : '',
                        $row->toArray(),
                    ));
                }

                break; // Only ever one sheet in these exports.
            }

            $reader->close();

            return $rows;
        } finally {
            @unlink($path);
        }
    }

    /**
     * True if no cell, in any row, contains $needle as a substring.
     *
     * @param  list<list<string>>  $rows
     */
    public static function noCellContains(array $rows, string $needle): bool
    {
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                if (str_contains($cell, $needle)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * True if at least one cell, in any row, equals or contains $needle.
     *
     * @param  list<list<string>>  $rows
     */
    public static function anyCellContains(array $rows, string $needle): bool
    {
        return ! self::noCellContains($rows, $needle);
    }
}
