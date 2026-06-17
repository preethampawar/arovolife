<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * CSV-export helpers.
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
    public static function safe(int|string|null $value): string
    {
        $string = (string) ($value ?? '');

        if ($string !== '' && in_array($string[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$string;
        }

        return $string;
    }
}
