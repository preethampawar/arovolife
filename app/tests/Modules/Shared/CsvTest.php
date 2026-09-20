<?php

declare(strict_types=1);

use App\Modules\Shared\Support\Csv;

/**
 * CSV formula-injection neutralisation (security audit F2). A cell that a
 * spreadsheet would treat as a formula must be rendered as text on export.
 */
it('prefixes a quote to cells that start with a formula character', function (string $input): void {
    expect(Csv::safe($input))->toBe("'".$input);
})->with(['=cmd|x', '+1+2', '-2+3', '@SUM(A1)', "\tTAB", "\rCR"]);

it('leaves safe values unchanged', function (): void {
    expect(Csv::safe('John Doe'))->toBe('John Doe');
    expect(Csv::safe('AV12345678'))->toBe('AV12345678');
    expect(Csv::safe('2026-06-17'))->toBe('2026-06-17');
    expect(Csv::safe('Telangana'))->toBe('Telangana');
});

it('coerces ints and null safely', function (): void {
    expect(Csv::safe(5))->toBe('5');
    expect(Csv::safe(null))->toBe('');
    expect(Csv::safe(''))->toBe('');
});

it('coerces a float safely (F122: grievance median_resolution_days is a float)', function (): void {
    expect(Csv::safe(3.5))->toBe('3.5');
});

/**
 * D2 — the guard is for STRINGS only. A number cannot carry a formula, and
 * quoting one exports a legitimate negative amount (a loss, a refund, a credit
 * balance) as text the spreadsheet will neither sum nor right-align.
 */
it('leaves negative numbers bare but still guards a negative-looking string', function (): void {
    expect(Csv::safe(-480318.94))->toBe('-480318.94')
        ->and(Csv::safe(-5))->toBe('-5')
        // A string stays a string: nothing distinguishes a crafted cell from a
        // number somebody typed into one, so the guard keeps quoting it.
        ->and(Csv::safe('-480318.94'))->toBe("'-480318.94")
        ->and(Csv::safe('=cmd|x'))->toBe("'=cmd|x");
});
