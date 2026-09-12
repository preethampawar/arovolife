<?php

declare(strict_types=1);

use App\Modules\Shared\Support\Csv;
use App\Modules\Shared\Support\ReportExport;
use App\Modules\Shared\Support\SheetWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The writer pair behind every report download on the platform. A regression
 * here is not one broken report, it is all thirty-two of them at once — which
 * is why the formula-injection guard, the numeric passthrough and the CSV money
 * formatting are each pinned directly rather than through an endpoint.
 */
function sheetCapture(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

/**
 * @return list<array{key: string, label: string}>
 */
function sheetExportColumns(): array
{
    return [
        ['key' => 'name', 'label' => 'Name'],
        ['key' => 'qty', 'label' => 'Qty'],
        ['key' => 'amount', 'label' => 'Amount (Rs)'],
        ['key' => 'created_at', 'label' => 'Created At'],
    ];
}

it('streams an xlsx workbook with the right media type and filename', function (): void {
    $response = SheetWriter::stream('gsb-calculation-2026-09-12', sheetExportColumns(), []);

    expect($response->headers->get('Content-Type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))->toContain('gsb-calculation-2026-09-12.xlsx');
});

it('writes a valid zip container even when there are no rows', function (): void {
    $bytes = sheetCapture(SheetWriter::stream('empty-report', sheetExportColumns(), []));

    expect(substr($bytes, 0, 2))->toBe('PK')
        ->and(strlen($bytes))->toBeGreaterThan(0);
});

it('guards string cells against formula injection and leaves numbers numeric', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'sheet').'.xlsx';

    file_put_contents($path, sheetCapture(SheetWriter::stream('injection', sheetExportColumns(), [
        [
            'name' => '=HYPERLINK("http://evil")',
            'qty' => 42,
            'amount' => 1234.5,
            'created_at' => Carbon::parse('2026-09-12 08:30:00'),
        ],
    ])));

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);

    /** The apostrophe prefix survives into the cell, so Excel renders it as text. */
    expect($sheet)->toContain("'=HYPERLINK(&quot;http://evil&quot;)")
        /** An int is a numeric cell, not an inline string. */
        ->and($sheet)->toContain('<v>42</v>')
        /** D5 — a Carbon is written as the Y-m-d H:i:s string the CSVs emitted. */
        ->and($sheet)->toContain('2026-09-12 08:30:00');
});

it('produces csv byte-identical to the exporter it replaced', function (): void {
    /**
     * S2 deleted AdminInventoryReportController::exportCsv() (its body now lives
     * verbatim in Csv::stream() — verified equal before deletion). This fixture
     * is that method's captured output for this exact input, frozen so a future
     * change to Csv::stream() is still caught even though the reflection target
     * is gone.
     */
    $columns = sheetExportColumns();
    $rows = [
        [
            'name' => '=HYPERLINK("http://evil")',
            'qty' => 7,
            'amount' => '1234.50',
            'created_at' => Carbon::parse('2026-09-12 08:30:00'),
        ],
        ['name' => 'Plain, quoted "value"', 'qty' => 0, 'amount' => '', 'created_at' => null],
    ];

    $before = "Name,Qty,Amount (Rs),Created At\n"
        ."\"'=HYPERLINK(\"\"http://evil\"\")\",7,1234.50,2026-09-12 08:30:00\n"
        ."\"Plain, quoted \"\"value\"\"\",0,,\n";

    expect(sheetCapture(Csv::stream('inventory-stock-on-hand', $columns, $rows)))->toBe($before);
});

it('keeps two decimals on money columns in csv', function (): void {
    $csv = sheetCapture(Csv::stream('money', [['key' => 'amount', 'label' => 'Amount']], [['amount' => 1234.50]]));

    expect($csv)->toContain('1234.50')->and($csv)->not->toContain('1234.5'."\n");
});

it('defaults to xlsx and only returns csv when asked', function (string $query, string $expected): void {
    expect(ReportExport::formatFor(Request::create('/report'.$query)))->toBe($expected);
})->with([
    'bare url' => ['', 'xlsx'],
    'format=xlsx' => ['?format=xlsx', 'xlsx'],
    'legacy export=xlsx' => ['?export=xlsx', 'xlsx'],
    'format=csv' => ['?format=csv', 'csv'],
    'legacy export=csv' => ['?export=csv', 'csv'],
    'unrecognised format' => ['?format=banana', 'xlsx'],
]);
