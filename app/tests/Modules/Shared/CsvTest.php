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
