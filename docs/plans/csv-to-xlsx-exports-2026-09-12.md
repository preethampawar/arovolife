# Report Exports — Add Excel (XLSX) Alongside CSV — Implementation Plan

Date: 2026-09-12 · Status: approved to implement
Decisions confirmed by KP 2026-09-12: keep CSV alongside Excel; openspout dependency approved.
Repo root for all paths: `arovolife-code/app/` (the Laravel root).

---

## Goal

Every human-facing report download on the platform offers **both** a real
`.xlsx` workbook and the existing `.csv` — 32 endpoints across 22 controllers —
served through one export facade that picks the format from a `?format=`
query parameter and streams either. XLSX is the default and the primary button;
CSV remains one click away and every existing CSV URL keeps working. The two
NEFT bank-upload exports keep emitting CSV only, untouched. No report's data,
filters, permissions or row set changes.

## Non-goals

- **The NEFT bank uploads stay CSV.** `HandlesPayoutBatchActions::exportNeft`
  (monthly + weekly) is read by the bank's portal, not by a person. Out of scope,
  and the plan adds a comment at that method saying why, so a later sweep does
  not "finish the job".
- The bank **response file upload** (`accept=".csv,text/csv"` on the payout
  batch pages) is an *import*, not an export. Unchanged.
- **CSV is not removed.** Every existing export URL keeps returning CSV when
  asked for it, so a bookmark, a cron job or a scripted download that hits one
  of these endpoints today keeps working unchanged (decision D2).
- No currency/date *number formats*, no totals-row styling beyond what already
  exists as data, no formulas, no multiple sheets per workbook.
- No change to what any report queries, filters, or who may see it.
- No queued/emailed exports.
- `App\Modules\Shared\Support\Csv` is **not** deleted — `exportNeft` still uses
  `Csv::safe`, and the new writer uses it too.

## Current behaviour

Three different implementations exist today, which is the actual problem this
change has to absorb:

| Style | Where | Shape |
|---|---|---|
| **A — shared `fputcsv` streamer** | `AdminInventoryReportController::exportCsv()` | Declares `$columns` as `[{key,label,align}]` and `$rows` as flat arrays. Clean. Serves 10 reports. |
| **B — hand-concatenated CSV string** | 15 Compensation controllers, `AdminDistributorController`, `AdminBvLedgerController`, `AdminGrievanceReportController` | Builds a `$csv` string with `implode(',', [...])."\n"`, own `csvStr()` helper, `return response($csv, 200, [...])`. Several append a `TOTAL` row. |
| **C — `streamDownload` closure** | `IncomeController::exportGsb/exportWallet`, `TeamRosterController::download`, `HandlesPayoutBatchActions::exportNeft` | `response()->streamDownload(fn() => fputcsv(...), $name, $headers)`. |

`Csv::safe()` neutralises formula injection (`= + - @ \t \r` → prefixed with `'`).
**This guard applies verbatim to XLSX** — Excel executes formulas from an
`.xlsx` cell exactly as it does from a CSV, so dropping it would turn a
hardening fix into a regression. Style B's `csvStr()` helpers wrap `Csv::safe`;
style A calls it directly; **style C's `TeamRosterController` and
`IncomeController` must be checked during implementation and given the guard if
they lack it** (noted per-file below).

## Architecture decisions

**D1 — `openspout/openspout` as the writer.**
Alternatives: PhpSpreadsheet (full styling, but builds the whole sheet in
memory) and maatwebsite/excel (nicer API, same memory profile plus a heavier
tree). Chosen openspout because it streams row-by-row at near-constant memory,
matching today's `streamDownload` behaviour. The BV ledger and the GSB
input/output exports are the largest on the platform and are exactly the ones
that would OOM under an in-memory builder. Cost: no rich number formats — which
D4 puts out of scope anyway.
**This adds a Composer dependency. CLAUDE.md requires approval; approved by KP
on 2026-09-12.**

**D2 — Both formats, selected by one `?format=` parameter.**
Alternatives: replace CSV outright (breaks anyone scripting against these URLs),
or add a second route per endpoint (32 new routes). Chosen: **no new routes at
all** — each existing endpoint reads `?format=xlsx|csv` and streams accordingly.
XLSX is the default when the parameter is absent, so the **existing bare URLs
now return XLSX**; a caller that needs CSV appends `?format=csv`. Each report
page gets two buttons: a primary "Excel" and a secondary "CSV".

Consequence to be explicit about: a script hitting a bare export URL today gets
CSV and will get XLSX after this ships. That is the one behaviour change in the
whole plan, and it is why the acceptance criteria require both formats asserted
per endpoint. If you would rather bare URLs keep returning CSV, that is a
one-line flip in `ReportExport::respond()` — say so before S1 runs.

**D3 — One facade, `ReportExport`, over two writers, with the style-A contract.**
Every call site is normalised to a single call —
`ReportExport::respond($request, $filename, $columns, $rows)` — taking the shape
`AdminInventoryReportController` already uses. The facade reads `?format=` and
delegates to `SheetWriter` (XLSX) or `Csv::stream()` (CSV); a controller never
names a format. The 18 style-B controllers stop concatenating strings and start
returning row arrays. This is the bulk of the work and the bulk of the risk:
**it is a behaviour-preserving refactor, and the acceptance criteria pin each
one to its existing column headers, in order, in both formats.**

Because both formats read the same `$columns`/`$rows`, they cannot drift: one
column added to a report appears in the CSV and the XLSX in the same commit.
That is the main reason for a facade rather than two parallel code paths.

**D4 — Formatting is header bold + freeze top row + column width only.**
No currency or date number formats. Reason: those need per-column type
declarations across 32 reports and openspout's format support is thin. The real
win — Excel no longer mangling a 9-digit ADN or an order number into scientific
notation — comes from writing strings as strings, which happens for free.

**D5 — Dates are written as `Y-m-d H:i:s` strings, not Excel date cells.**
Same reasoning as D4, and it matches what the CSVs emit today, so no report's
output changes meaning. A `Carbon` in a row is coerced by the writer.

**D6 — Numeric cells stay numeric where the source is already an int/float.**
`Csv::safe()` is applied only to string cells. Passing an int through `safe()`
would stringify it and lose Excel's right-alignment and summability. The writer
branches on type; this is the one behavioural improvement over the CSVs.

**D7 — Filenames keep their existing stems; only the extension changes.**
`gsb-calculation-2026-09-12.csv` → `gsb-calculation-2026-09-12.xlsx`. Anyone
with a folder of these keeps their sort order.

## Permission matrix

**No permission changes.** Every endpoint below keeps the exact gate it has
today. This table exists so the Playwright and Pest specs assert that the gate
survived the refactor — a permission silently dropped during a 22-file sweep is
the most likely way this change causes harm.

| # | Endpoint (route name) | Current gate | Must still be |
|---|---|---|---|
| P1 | `admin.inventory.reports.*` (10 reports, `?export=xlsx`) | `can:inventory.view` | unchanged |
| P2 | `admin.distributors.export` | admin-family; **`Gate::before` + route group** — verify exact middleware at line 293 and preserve it | unchanged |
| P3 | `admin.commerce.bv-ledger.export` | `can:audit.read` | unchanged |
| P4 | `admin.commerce.bv-ledger.show.export` | `can:audit.read` | unchanged |
| P5 | `admin.grievances.report.export` | `can:grievance.handle` | unchanged |
| P6 | 15 × `admin.compensation.*.export` | the compensation route group's middleware + per-controller `Feature::for(null)->active(...)` `abort_unless` | unchanged, **feature-flag abort included** |
| P7 | `income.gsb-history.export` | auth + distributor | unchanged |
| P8 | `income.wallet.export` | auth + distributor | unchanged |
| P9 | `dashboard.team-roster.download` | auth + distributor, **scoped to own team** | unchanged — scope must not widen |
| P10 | `admin.compensation.{monthly,weekly}-payouts.neft` | `can:finance.record` | **still CSV, untouched** |

Implementers: do not add, remove or reorder any `middleware()`, `can:`,
`abort_unless` or `Feature::` call. If a file's gate looks wrong, report it —
do not fix it in this change.

## File changes

| # | Path | New/Mod | Change |
|---|---|---|---|
| F1 | `composer.json` | Mod | Require `openspout/openspout` |
| F2 | `app/Modules/Shared/Support/SheetWriter.php` | New | The streaming XLSX writer |
| F2b | `app/Modules/Shared/Support/ReportExport.php` | New | Format-selecting facade over both writers |
| F3 | `app/Modules/Shared/Support/Csv.php` | Mod | Add `stream()` — the CSV half, lifted out of the inventory controller |
| F4 | `app/Modules/Inventory/Http/Controllers/Admin/AdminInventoryReportController.php` | Mod | Swap `exportCsv()` for `ReportExport::respond()` |
| F5–F19 | 15 × `app/Modules/Compensation/Http/Controllers/Admin/Admin*Controller.php` | Mod | Style B → row arrays + `SheetWriter` |
| F20 | `app/Modules/Admin/Http/Controllers/AdminDistributorController.php` | Mod | Style B → `SheetWriter` |
| F21 | `app/Modules/Commerce/Http/Controllers/Admin/AdminBvLedgerController.php` | Mod | Style B → `SheetWriter` (2 methods) |
| F22 | `app/Modules/Grievance/Http/Controllers/AdminGrievanceReportController.php` | Mod | Style B → `SheetWriter` |
| F23 | `app/Modules/Compensation/Http/Controllers/IncomeController.php` | Mod | Style C → `SheetWriter` (2 methods) |
| F24 | `app/Modules/Identity/Http/Controllers/TeamRosterController.php` | Mod | Style C → `SheetWriter` |
| F25 | `app/Modules/Compensation/Http/Controllers/Admin/Concerns/HandlesPayoutBatchActions.php` | Mod | **Comment only** — record why NEFT stays CSV |
| F26 | `resources/views/admin/inventory/reports/show.blade.php` | Mod | Two buttons: Excel + CSV |
| F27–F43 | 17 × `resources/views/admin/compensation/*/index.blade.php` | Mod | Two buttons: Excel + CSV |
| F44 | `resources/views/admin/commerce/bv-ledger/index.blade.php` | Mod | Two buttons: Excel + CSV |
| F45 | `resources/views/admin/commerce/bv-ledger/show.blade.php` | Mod | Two buttons: Excel + CSV |
| F46 | `resources/views/admin/distributors/index.blade.php` | Mod | Two buttons: Excel + CSV |
| F47 | `resources/views/admin/grievances/report.blade.php` | Mod | Two buttons: Excel + CSV |
| F48 | `resources/views/income/gsb-history.blade.php` | Mod | Two buttons: Excel + CSV |
| F49 | `resources/views/income/wallet.blade.php` | Mod | Two buttons: Excel + CSV |
| F50 | `resources/views/dashboard/_my-team.blade.php` | Mod | Two buttons + comment |
| F51 | `tests/Feature/SheetWriterTest.php` | New | Pest unit test for the writer |
| F52 | `tests/Feature/ExportEndpointsTest.php` | New | Pest: all 32 endpoints × 2 formats, content type + gate |
| F53 | `tests/Browser/exports.spec.js` | New | Playwright download assertions |

---

### F1 — `composer.json`

```
composer require openspout/openspout
```

Pin the major version as Composer resolves it; do not hand-edit the lock file.
Verify PHP has `ext-zip`, `ext-xmlwriter` and `ext-mbstring` (openspout
requires them) — the Docker image in `docker/php` should already carry them;
if `composer require` fails on a platform requirement, **stop and report**.

### F2 — `app/Modules/Shared/Support/SheetWriter.php` (new)

The single serialisation point for the whole platform. Every export goes
through `stream()`.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Carbon\CarbonInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
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
     * @param  string  $filename  Without extension, e.g. 'gsb-calculation-2026-09-12'
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  iterable<int, array<string, mixed>>  $rows
     */
    public static function stream(string $filename, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(
            function () use ($columns, $rows): void {
                $options = new Options;
                $options->SHOULD_USE_INLINE_STRINGS = true;

                // Freeze the header so a 5,000-row export is still readable
                // once the operator scrolls.
                $options->setColumnWidth(18, ...range(1, max(1, count($columns))));

                $writer = new Writer($options);
                $writer->openToFile('php://output');
                $writer->getCurrentSheet()->setSheetView(
                    (new \OpenSpout\Writer\XLSX\Entity\SheetView)->setFreezeRow(2)
                );

                $header = (new Style)->setFontBold();
                $writer->addRow(Row::fromValues(
                    array_map(static fn (array $c): string => $c['label'], $columns),
                    $header
                ));

                foreach ($rows as $row) {
                    $writer->addRow(Row::fromValues(array_map(
                        static fn (array $c): int|float|string => self::cell($row[$c['key']] ?? ''),
                        $columns
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
```

**Implementer note:** the exact openspout API for freeze-pane and column width
differs between 3.x and 4.x. Confirm against the installed version
(`composer show openspout/openspout`) and the package's own docs before
assuming the snippet compiles. If freeze-pane is not available on the installed
version, ship bold header + column width and **report the omission** — do not
upgrade the package to get it.

### F2b — `app/Modules/Shared/Support/ReportExport.php` (new)

The single entry point. Every converted controller ends in exactly one call to
this, and no controller names a format.

```php
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
        $requested = strtolower((string) ($request->query('format') ?? $request->query('export') ?? ''));

        return $requested === self::FORMAT_CSV ? self::FORMAT_CSV : self::FORMAT_XLSX;
    }
}
```

**Note the legacy-parameter handling.** `?export=csv` still returns CSV — that
is the compatibility the inventory reports need. `?export=xlsx` and
`?format=xlsx` both work. A bare URL returns XLSX (D2).

### F3 — `app/Modules/Shared/Support/Csv.php`

Gains the CSV half of the pair. Move `AdminInventoryReportController::exportCsv()`
here verbatim, renamed `stream()`, taking the same signature as
`SheetWriter::stream()` so the facade can call either:

```php
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

                    if ($value instanceof \Illuminate\Support\Carbon) {
                        $value = $value->toDateTimeString();
                    } elseif (is_bool($value)) {
                        $value = $value ? '1' : '0';
                    } elseif (! is_scalar($value)) {
                        $value = (string) $value;
                    }

                    return self::safe($value);
                }, $columns));
            }

            fclose($handle);
        }, $filename.'.csv', ['Content-Type' => 'text/csv']);
    }
```

Add the `StreamedResponse` import. Also append to the class docblock:

```php
 * Both halves of the export pair live here and in SheetWriter: safe() is shared
 * because Excel executes a formula out of an .xlsx cell exactly as it does out
 * of a .csv, so changing format retires nothing.
```

**Float cells in CSV:** style-B controllers currently emit money as
`number_format($paise / 100, 2, '.', '')`. After the refactor they pass a float
(D6) and `Csv::stream()` casts it with `(string)`, which drops trailing zeros —
`1234.50` becomes `1234.5`. **This is a visible CSV change.** Handle it in
`Csv::stream()` by formatting floats to 2 decimals:

```php
                    if (is_float($value)) {
                        $value = number_format($value, 2, '.', '');
                    }
```

Insert that branch **before** the `is_scalar` branch. Without it, the acceptance
criterion comparing CSV output to the previous commit will fail on every money
column.

### F4 — `AdminInventoryReportController`

The cheapest conversion on the platform — 10 reports, one exporter.

1. **Move** the private `exportCsv()` body (lines ~593–621) into `Csv::stream()`
   per F3, then delete the method here.
2. In `render()`, replace the export branch:

```php
        if ($request->query('export') !== null || $request->query('format') !== null) {
            return ReportExport::respond($request, "inventory-{$slug}", $columns, $rows);
        }
```

   Note the condition: the presence of *either* parameter means "download";
   which format it is, is `ReportExport`'s business, not this controller's.
3. Replace `use App\Modules\Shared\Support\Csv;` with
   `use App\Modules\Shared\Support\ReportExport;`.
4. Update the class docblock: "CSV-exportable via `?export=csv`" →
   "Exportable as Excel or CSV via `?format=xlsx|csv` (`?export=` still honoured)".
5. Leave `use Symfony\Component\HttpFoundation\StreamedResponse;` — still the
   return type.

The 10 public report methods are **not** touched; they already pass `$columns`
and `$rows` in the right shape.

### F5–F19 — the 15 Compensation controllers

All fifteen share style B. The conversion is the same shape in each; the
per-file table below gives the specifics.

**Worked example — `AdminGsbCalculationController::export()`.** Before, it
concatenated a header string and `implode(',', [...])` rows. After:

```php
    public function export(Request $request): StreamedResponse
    {
        abort_unless(Feature::for(null)->active(GenosSalesBonusFeature::class), 404);

        [$q, $from, $to, $status, $slab] = $this->filters($request);

        $rows = $this->buildQuery($q, $from, $to, $status, $slab)->get();

        $distributorIds = $rows->pluck('distributor_id')->unique()->values()->all();
        $personalBvMap = $this->batchPersonalBvPaise($distributorIds);
        $totals = $this->totals($q, $from, $to, $status, $slab);

        $columns = [
            ['key' => 'sno',        'label' => 'SNo'],
            ['key' => 'adn',        'label' => 'ADN'],
            ['key' => 'name',       'label' => 'Name'],
            ['key' => 'title',      'label' => 'Title'],
            ['key' => 'date',       'label' => 'Date'],
            ['key' => 'slab',       'label' => 'Slab'],
            ['key' => 'score',      'label' => 'Score'],
            ['key' => 'score_value','label' => 'Score Value (Rs)'],
            ['key' => 'income',     'label' => 'Income (Rs)'],
            ['key' => 'deduction',  'label' => 'Repurchase Deduction (Rs)'],
            ['key' => 'credited',   'label' => 'Credited to Wallet (Rs)'],
            ['key' => 'status',     'label' => 'Status'],
        ];

        $out = $rows->values()->map(function ($row, int $i) use ($personalBvMap): array {
            $title = $this->titleService->forBvPaise($personalBvMap[$row->distributor_id] ?? 0)->title ?? '';

            return [
                'sno' => $i + 1,
                'adn' => (string) $row->adn,
                'name' => (string) ($row->full_name ?? ''),
                'title' => $title,
                'date' => Carbon::parse($row->cutoff_date)->toDateString(),
                'slab' => $row->slab,
                'score' => (int) $row->score,
                'score_value' => $row->score_value_paise !== null ? $row->score_value_paise / 100 : '',
                'income' => $row->gross_gsb_paise / 100,
                'deduction' => $row->repurchase_deduction_paise / 100,
                'credited' => $row->net_gsb_paise / 100,
                'status' => (string) $row->status,
            ];
        })->all();

        // Grand total across the full filtered set, unchanged in meaning.
        $out[] = [
            'sno' => 'TOTAL', 'adn' => '', 'name' => '', 'title' => '', 'date' => '', 'slab' => '',
            'score' => $totals['score'], 'score_value' => '',
            'income' => $totals['income_paise'] / 100,
            'deduction' => $totals['deduction_paise'] / 100,
            'credited' => $totals['credited_paise'] / 100,
            'status' => '',
        ];

        return ReportExport::respond($request, 'gsb-calculation-'.now()->toDateString(), $columns, $out);
    }
```

Note what changed and what did not:

- `abort_unless(Feature::...)` is **first and unchanged**. Every one of these
  fifteen has one; none may be dropped.
- Money is now `paise / 100` as a **float**, not `number_format(...)` as a
  string (D6) — so it sums in Excel. `Csv::stream()` re-formats floats to two
  decimals (F3), so the CSV output is byte-identical to today's.
- Column headers are **byte-identical** to the old CSV header line, in both
  formats. This is the acceptance test.
- `$this->csvStr()` calls disappear; if a controller's `csvStr()` is then unused,
  delete it. If it is used elsewhere in the file, leave it.
- Return type changes `Response` → `StreamedResponse`; fix the `use` imports.

**Per-file specifics.** For each, take the column labels verbatim from the
existing `$csv = "...\n";` header line in that file — do not retype from
memory, copy the string.

| # | File | Method | Filename stem | TOTAL row? | Feature flag guard |
|---|---|---|---|---|---|
| F5 | `AdminGsbCalculationController` | `export` | `gsb-calculation-{Y-m-d}` | yes | `GenosSalesBonusFeature` |
| F6 | `AdminGsbInputOutputController` | `export` | `gsb-input-output-{Y-m-d}` | check file | check file |
| F7 | `AdminMsbCalculationController` | `export` | `msb-calculation-{Y-m-d}` | check file | check file |
| F8 | `AdminMsbInputOutputController` | `export` | `msb-input-output-{Y-m-d}` | check file | check file |
| F9 | `AdminGbbCalculationController` | `export` | `gbb-calculation-{Y-m}` | check file | check file |
| F10 | `AdminGbbInputOutputController` | `export` | `gbb-input-output-{Y-m-d}` | check file | check file |
| F11 | `AdminRankBonusCalculationController` | `export` | `rank-bonus-{Y-m}` | check file | check file |
| F12 | `AdminRankBonusInputOutputController` | `export` | `rb-input-output-{Y-m-d}` | check file | check file |
| F13 | `AdminFortuneBonusCalculationController` | `export` | `fortune-bonus-{Y-m}` | check file | check file |
| F14 | `AdminAwRwCalculationController` | `export` | `aw-rw-{Y-m}` | check file | check file |
| F15 | `AdminAdcCalculationController` | `export` | `adc-calculation-{Y-m}` | check file | check file |
| F16 | `AdminCarryForwardController` | `export` | `gsb-carry-forwards-{Y-m-d}` | check file | check file |
| F17 | `AdminDailyCutoffController` | `export` | `gsb-cutoff-{date}` | check file | check file |
| F18 | `AdminGsbPersonalBvTopupController` | `export` | `gsb-personal-bv-topups-{date}` | check file | check file |
| F19 | `AdminGenosTransactionsController` | `export` | `genos-bv-reversals-{Y-m-d}` **or** `genos-bv-credits-{Y-m-d}` depending on the request's mode — **preserve the existing branch** | check file | check file |

"check file" means: read what is there and carry it across unchanged. The
implementer must not invent a TOTAL row that does not exist, nor drop one that
does.

### F20 — `AdminDistributorController::export()`

The **Register of Direct Sellers** — a statutory document under the Direct
Selling Rules 2021. Its column set and order are prescribed; carry them across
character-for-character. This file hand-quotes cells
(`'"'.str_replace('"','""', Csv::safe($v)).'"'`); the writer handles quoting, so
drop the manual quoting but **keep `Csv::safe`** (both writers apply it, so pass
raw values in and let the writer guard them — do not double-guard,
which would prefix a legitimate leading `-` twice).

Filename stem: `register-of-direct-sellers-{Y-m-d}`.

### F21 — `AdminBvLedgerController`

Two methods (`export`, `exportShow`) sharing a `$namePrefix`. Convert both.
Filename stems keep `{$namePrefix}-{Y-m-d}`. This is the **largest export on the
platform** — confirm during implementation that the query is already chunked or
lazily iterated; if it calls `->get()` on an unbounded set, **report it and stop**
rather than converting it as-is, because the writer streams but a materialised
Eloquent collection does not.

### F22 — `AdminGrievanceReportController::export()`

Filename stem `grievance-compliance-{Y-m}`. Straight style-B conversion.

### F23 — `IncomeController`

Distributor-facing. Two methods: `exportGsb` (`gsb-history`) and `exportWallet`
(`wallet-ledger`). Both are style C (`streamDownload` + `fputcsv`). Replace the
closure with a `ReportExport::respond()` call, building `$columns` from the
`fputcsv` header array already in the method.

**Check both for `Csv::safe`.** If the current `fputcsv` calls pass raw values,
the guard is currently missing on a distributor-facing export — the writer adds
it, which is a fix; note it in the commit body rather than treating it as a
behaviour change to avoid.

`exportWallet` has a comment at line ~144 about a label map "backing the CSV
export so neither one leaks the raw" value — **read it and preserve whatever it
is protecting.**

### F24 — `TeamRosterController::download()`

Distributor-facing, scoped by `$scope`. Filename is built with
`sprintf('arovolife-%s-%s.csv', ...)` — change to `%s-%s` and pass the stem to
`ReportExport::respond()`, which appends the right extension.

**The team scope is the security boundary here (P9).** Do not touch the scope
resolution or the `TeamStatsService` call; convert only the serialisation.

### F25 — `HandlesPayoutBatchActions::exportNeft()`

**No functional change.** Add above the method:

```php
    /**
     * Deliberately still CSV after the 2026-09-12 XLSX migration: this file is
     * uploaded to the bank's portal, which parses CSV. It is not a report and
     * no person opens it in Excel. Do not "finish the job" by converting it —
     * a rejected bank upload is a stalled payout run.
     */
```

### F26 — `resources/views/admin/inventory/reports/show.blade.php`

Replace the single anchor at lines ~22–23 with the two-button pair. Excel is
primary (filled), CSV secondary (outline), so the default is obvious:

```blade
    <div class="flex items-center gap-2">
        <a href="{{ request()->fullUrlWithQuery(['format' => 'xlsx']) }}"
           class="px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">Export Excel</a>
        <a href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}"
           class="px-4 py-2 rounded-lg border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">CSV</a>
    </div>
```

### F27–F50 — the remaining 24 Blade buttons

Each of these files today has **one** download anchor. It becomes **two**,
side by side. The rule for every file:

1. Keep the existing anchor exactly as it is — same `route(...)` call, same
   query forwarding, same CSS classes — and change only its visible text from
   the CSV wording to the Excel wording (table below).
2. Add `['format' => 'xlsx']` to that anchor's route parameters, and
   immediately after it add a sibling anchor that is identical except for
   `['format' => 'csv']` and the text `CSV`, with the secondary classes
   `px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm text-gray-700 hover:bg-gray-50`.
3. Wrap the pair in `<div class="flex items-center gap-2">` **only if** the
   original anchor was not already inside a flex row — check each file; several
   already sit in one, and adding a nested div there breaks the spacing.

Where the existing anchor forwards the filter query — e.g.
`route('income.gsb-history.export', request()->query())` — the format must be
merged in, not replace it:
`route('income.gsb-history.export', array_merge(request()->query(), ['format' => 'xlsx']))`.
Getting this wrong silently drops the operator's filters from the download,
which is worse than a wrong label, so check every file rather than pattern-matching.

Text for the primary (Excel) anchor:

| Current text | New primary text |
|---|---|
| `↓ Download CSV` | `↓ Download Excel` |
| `⬇ Export CSV` | `⬇ Export Excel` |
| `⬇ CSV` | `⬇ Excel` |
| `&#11015; CSV` | `&#11015; Excel` |
| `Export 12 months (CSV)` | `Export 12 months (Excel)` |
| `↓ Export CSV (DSR Register)` | `↓ Export Excel (DSR Register)` |
| `Download CSV` (`_my-team.blade.php`) | `Download Excel` |

The secondary anchor's text is `CSV` in every file.

Files, exhaustively: the 17 under `resources/views/admin/compensation/*/index.blade.php`
listed in the file table (adc-calculation, aw-rw-calculation, carry-forwards,
daily-cutoffs, fb-calculation, gbb-calculation, gbb-input-output,
genos-transactions, gsb-calculation, gsb-input-output, msb-calculation,
msb-input-output, personal-bv-topups, rb-calculation, rb-input-output — note
this is 15; the remaining two Blade edits are the bv-ledger pair), plus
`admin/commerce/bv-ledger/index.blade.php`, `admin/commerce/bv-ledger/show.blade.php`,
`admin/distributors/index.blade.php`, `admin/grievances/report.blade.php`,
`income/gsb-history.blade.php`, `income/wallet.blade.php`,
`dashboard/_my-team.blade.php`.

**Do not touch** `admin/compensation/monthly-payouts/show.blade.php` or
`weekly-payouts/show.blade.php` — those mention CSV for the bank *response
file upload*, which is correct as written.

Also update the surrounding comments that say "CSV" in
`income/gsb-history.blade.php` (line ~17) and `dashboard/_my-team.blade.php`
(lines ~36–37, ~102) to say "Excel / CSV", so they do not contradict the code.

### F51 — `tests/Feature/SheetWriterTest.php` (new, Pest)

`php artisan make:test --pest SheetWriterTest`. Assert:

- response `Content-Type` is the XLSX media type and `Content-Disposition`
  filename ends `.xlsx`
- the streamed bytes start with `PK` (a valid zip container)
- a cell whose value is `=HYPERLINK("http://evil")` comes back prefixed with `'`
- an `int` cell is written as a number, not a string
- a `Carbon` cell is written as `Y-m-d H:i:s`
- an empty `$rows` produces a workbook with only the header row, no exception

Plus, for the CSV half and the facade:

- `Csv::stream()` with the same `$columns`/`$rows` produces byte-identical
  output to the old `AdminInventoryReportController::exportCsv()` (capture a
  fixture from `git show HEAD~1` before S1 lands)
- a float `1234.50` renders as `1234.50` in CSV, not `1234.5`
- `ReportExport::formatFor()` returns xlsx for a bare request, xlsx for
  `?format=xlsx`, xlsx for `?export=xlsx`, csv for `?format=csv`, csv for
  `?export=csv`, and xlsx for `?format=banana`

### F52 — `tests/Feature/ExportEndpointsTest.php` (new, Pest)

A dataset over **all 32 converted endpoints**, plus the 2 NEFT ones. For each:

- as the role in the permission matrix that may reach it, `?format=xlsx` → 200,
  `Content-Type` is the XLSX type, `Content-Disposition` ends `.xlsx`
- same role, `?format=csv` → 200, `text/csv`, ends `.csv`
- same role, **bare URL, no parameter** → 200 and XLSX (pins D2's default, which
  is the one behaviour change in this plan)
- `?export=csv` → 200 and CSV (pins the legacy-parameter compatibility)
- as a role that may not → 403 / redirect, **exactly as before this change**,
  for both formats
- for the 15 compensation exports, with the feature flag off → 404, both formats

That is 32 × 4 success assertions plus the negatives. Use a Pest dataset over
`[route name, role, filename stem]` rather than 128 hand-written tests.

This file is the regression net for the whole sweep. It is why the permission
matrix above lists gates that are not changing.

### F53 — `tests/Browser/exports.spec.js` (new, Playwright)

See test plan.

---

## Slices

| Slice | Title | Files | Depends on | Model |
|---|---|---|---|---|
| S1 | Dependency + `SheetWriter` + `ReportExport` + `Csv::stream()` + writer unit test | F1, F2, F2b, F3, F51 | — | **Opus** |
| S2 | Inventory reports (10 endpoints, 1 exporter) + its Blade | F4, F26 | S1 | Sonnet |
| S3 | Compensation batch A — the 7 calculation exports | F5, F7, F9, F11, F13, F14, F15 | S1 | Sonnet |
| S4 | Compensation batch B — the 4 input/output exports | F6, F8, F10, F12 | S1 | Sonnet |
| S5 | Compensation batch C — carry-forwards, cutoffs, topups, genos | F16, F17, F18, F19 | S1 | Sonnet |
| S6 | Commerce + compliance exports | F20, F21, F22 | S1 | **Opus** |
| S7 | Distributor-facing exports | F23, F24 | S1 | **Opus** |
| S8 | NEFT comment + all 24 Blade button pairs | F25, F27–F50 | S2–S7 | **Sonnet** |
| S9 | Endpoint + permission regression suite | F52 | S2–S7 | **Opus** |
| S10 | Playwright download spec | F53 | S8 | Sonnet |

**Order.** S1 alone first — nothing compiles without the writer. Then **S2, S3,
S4, S5, S6, S7 all run in parallel** (six agents, disjoint file sets, each
depends only on S1). Then S8 and S9 in parallel. Then S10.

**Model rationale.** S1 is the one piece of real design — a wrong guard, a
memory-hungry writer or a mis-defaulted format poisons all 32 endpoints, so
Opus. S6 and S7 get Opus because they carry the statutory DSR register, the
platform's largest export (with a possible unbounded-query landmine), and the
two distributor-facing endpoints where scope is the security boundary. S3–S5
are fifteen repetitions of one worked example — Sonnet, with the caveat that
each agent must copy column labels from the file rather than infer them.

**S8 moved from Haiku to Sonnet when CSV was kept.** It is no longer 24 string
replacements: each file gains a second anchor, and several forward filter query
parameters that must be merged with the format rather than overwritten. That is
judgement per file, not find-and-replace. S9 is test design against a matrix —
Opus.

## Test plan (Playwright — `tests/Browser/exports.spec.js`)

`import { test } from './fixtures.js'` for `adminPage` / `distributorPage`.
Use `page.waitForEvent('download')` and assert `download.suggestedFilename()`.

| # | Matrix | Scenario | Expect |
|---|---|---|---|
| T1 | P1 | `adminPage` → inventory stock-on-hand → click Export Excel | download, filename `inventory-stock-on-hand.xlsx` |
| T2 | P1 | Same page | Both buttons present: "Export Excel" and "CSV" |
| T3 | P1 | inventory valuation → Export Excel | filename `inventory-valuation.xlsx` |
| T4 | P6 | `adminPage` → GSB calculation → Download Excel | filename matches `/^gsb-calculation-\d{4}-\d{2}-\d{2}\.xlsx$/` |
| T5 | P6 | `adminPage` → rank bonus calculation → Download Excel | `.xlsx` download |
| T6 | P3 | `adminPage` → BV ledger → Export Excel | `.xlsx` download |
| T7 | P2 | `adminPage` → distributors → Export Excel (DSR Register) | filename `register-of-direct-sellers-*.xlsx` |
| T8 | P5 | `adminPage` → grievance report → Export 12 months (Excel) | `.xlsx` download |
| T9 | P7 | `distributorPage` → income GSB history → Excel | `.xlsx` download |
| T10 | P8 | `distributorPage` → wallet → Excel | `.xlsx` download |
| T11 | P9 | `distributorPage` → dashboard My Team → Download Excel | `.xlsx` download |
| T12 | P10 | `adminPage` → payout batch → NEFT export | **still `.csv`** — the negative test that keeps the exemption honest |
| T13 | P1 | `distributorPage` → `/admin/inventory/reports/stock-on-hand?export=xlsx` | 403 or redirect |
| T14 | P6 | `distributorPage` → `/admin/compensation/gsb-calculation/export` | 403 or redirect |
| T15 | edge | `adminPage` → a report filtered to return zero rows → Export Excel | downloads, no 500 |
| T16 | compat | `adminPage` → inventory report with the old `?export=csv` | **still** downloads `inventory-*.csv` — the legacy param keeps working (D2) |
| T17 | P1 | `adminPage` → inventory stock-on-hand → click **CSV** | download, filename `inventory-stock-on-hand.csv` |
| T18 | P6 | `adminPage` → GSB calculation → click **CSV** | `.csv` download |
| T19 | P7 | `distributorPage` → income GSB history → click **CSV** | `.csv` download |
| T20 | D2 | `adminPage` → inventory report, bare export URL with no format param | downloads `.xlsx`, not `.csv` |
| T21 | F27–F50 | `adminPage` → a report page with an active filter → click **CSV** | the download URL still carries the filter query params |

Scoped-role coverage (`admin-finance`, `admin-compliance`) lives in **F52 Pest
tests**, not Playwright — `fixtures.js` has no scoped-role login helper, and
this change does not add one.

## Acceptance criteria

- [ ] `composer show openspout/openspout` resolves; `composer.lock` committed.
- [ ] `grep -rn "text/csv" app/` returns **only** `Csv.php`,
      `HandlesPayoutBatchActions.php`, and the bank-response import validation.
- [ ] `grep -rn "fputcsv\|\$csv \.=" app/` returns only `Csv.php` and
      `HandlesPayoutBatchActions.php` — no controller builds a CSV any more.
- [ ] For each of the 32 endpoints, the XLSX header row **and** the CSV header
      row are byte-identical to the CSV header line before this change
      (compare against `git show HEAD~1`).
- [ ] For a sample of three money-bearing reports, the full CSV body is
      byte-identical to the pre-change CSV — this is what catches the
      `1234.50` → `1234.5` float regression (F3).
- [ ] Every converted endpoint answers both `?format=xlsx` and `?format=csv`,
      and a bare URL returns XLSX (F52).
- [ ] Every report page shows two download buttons, and the CSV one carries the
      same filter query parameters as the Excel one (T21).
- [ ] Every `abort_unless` and `Feature::` guard present before the change is
      present after it — verified by F52, not by eye.
- [ ] The NEFT exports still return `text/csv` (T12 + F52).
- [ ] No Blade file outside the listed 24 changed.
- [ ] `vendor/bin/pint --dirty --format agent` clean.
- [ ] `vendor/bin/phpstan analyse` — no new errors against the baseline.
- [ ] `php artisan test --compact` green.
- [ ] `npx playwright test tests/Browser/exports.spec.js` green.

```
composer require openspout/openspout
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
php artisan test --compact
npx playwright test tests/Browser/exports.spec.js
```

## Risks

| Risk | Mitigation |
|---|---|
| A permission or feature-flag guard silently dropped across 22 files | F52 asserts every gate; the permission matrix lists gates that are *not* changing precisely so they are testable |
| A column header changed during the style-B rewrite, breaking a downstream consumer's import | Acceptance criterion pins headers byte-for-byte against the previous commit |
| BV ledger export materialising an unbounded collection and OOMing | S6 instructed to stop and report if the query is not chunked, rather than convert it |
| openspout API differs from the plan's snippet across major versions | S1 instructed to verify against the installed version and report, not upgrade |
| Formula-injection guard lost in the format change | Guard lives in `SheetWriter::cell()`; F51 asserts it directly |
| Someone converts the NEFT export in a later sweep | F25 comment + T12 negative test |
| A user's saved workflow depended on CSV | CSV kept on every endpoint; `?export=csv` still honoured (D2, F2b) |
| A bare export URL in a script now returns XLSX instead of CSV | Called out in D2 as the plan's one behaviour change; F52 pins the default so it is a decision, not a surprise. One-line flip if you want CSV to stay the bare-URL default |
| Money formatted as `1234.5` in CSV after floats replace `number_format` | `Csv::stream()` re-formats floats to 2dp (F3); acceptance criterion diffs three CSV bodies against the previous commit |
| A CSV button that silently drops the operator's active filters | S8 instructed to merge format into the existing query rather than replace it; T21 asserts it |
| The two formats drifting apart as reports change | Single `$columns`/`$rows` contract through `ReportExport` (D3) — neither format can be edited alone |

---

## Implementation log

### S1 — landed 2026-09-12

Files: `composer.json`, `composer.lock`, `SheetWriter.php` (new),
`ReportExport.php` (new), `Csv.php` (+`stream()`), `tests/Feature/SheetWriterTest.php` (new).

**openspout resolved to v5.11.3. The F2 snippet in this plan is 4.x-era and does
not compile on 5.x.** The shipped `SheetWriter` differs as follows — later
slices and future readers should trust the code over the F2 snippet:

- `Options` is `final readonly`: `new Options(SHOULD_USE_INLINE_STRINGS: true)`,
  not property assignment.
- Header row uses `Row::fromValuesWithStyle($labels, new Style(fontBold: true))`.
  On 5.x `Row::fromValues()`'s second argument is row *height*, not a style, and
  `Style` is readonly so `setFontBold()` no longer exists.
- Freeze pane ships: `SheetView` with immutable `withFreezeRow(2)`, not
  `setFreezeRow`. Bold header, freeze row and column width are all in.

**Deviation to clear in S2:** `SheetWriterTest` currently asserts CSV
byte-identity by invoking `AdminInventoryReportController::exportCsv()` through
reflection and comparing it to `Csv::stream()`. That method is deleted in S2 —
**S2 must either freeze a fixture from `git show HEAD~1` or delete that test
case**, or the suite breaks.

**Not yet verified — the folder bridge has no PHP, Composer or Docker.** The dev
environment runs through `docker exec arovolife-app` on the Mac itself. Before
this is green, KP must run:

```
docker exec arovolife-app composer install    # vendor/openspout is not on disk yet
make pint
make stan
make test
```

`composer require --no-install` resolved cleanly (1 install, no transitive deps,
no advisories) and `php -l` + Pint passed against a PHP 8.4 / Composer 2.8
sandbox, and the three `SheetWriter` assertions were executed standalone against
openspout 5.11.3 and hold. But `composer install`, PHPStan and Pest have not run
against the real image.

### S2–S10 — landed 2026-09-12

**30 of 32 endpoints converted.** All now end in `ReportExport::respond()`;
`grep -rln 'fputcsv|$csv \.=' app/` returns only `Csv.php`,
`HandlesPayoutBatchActions.php` and `AdminBvLedgerController.php`.

| Slice | Outcome |
|---|---|
| S2 | 10 inventory reports + Blade. `exportCsv()` deleted; the `SheetWriterTest` reflection case was frozen as a byte fixture rather than deleted. |
| S3 | 7 calculation exports. `AdminRankBonusCalculationController` has a feature-conditional "Arete Center" column — preserved via `array_merge` so the sheet stays rectangular either way. |
| S4 | 4 input/output exports. All four emit per-day/per-month TOTAL rows, not one grand total; carried across. `AdminRankBonusInputOutputController` also emits 9 rank rows + a conditional AO-GO row per month. |
| S5 | 4 operational exports. `AdminGenosTransactionsController`'s reversals/credits branch kept, both stems and both column sets. |
| S6 | DSR register + grievance report converted. **BV ledger NOT converted — see S6b.** |
| S7 | 3 distributor-facing exports. |
| S8 | NEFT comment + 22 Blade button pairs. |
| S9 | `ExportEndpointsTest` — 30-endpoint dataset × 5 assertions, plus flag-off, NEFT and BV-ledger cases. |
| S10 | `exports.spec.js` — T1–T21. |

**Findings worth keeping:**

- **`IncomeController::exportGsb()` and `exportWallet()` had no formula-injection
  guard.** Both are distributor-facing and passed raw values to `fputcsv`.
  Routing them through `ReportExport` fixes that. `AdminBvLedgerController::csvRow()`
  still has no guard — it is fixed for free whenever S6b lands.
- **`AdminGenosTransactionsController` is not behind a feature flag**, unlike the
  other 14 compensation exports. Pre-existing; not changed.
- **The 10 inventory reports have no bare-URL export** — `render()` only streams
  when `export` or `format` is present, so a bare URL renders HTML. D2's default
  is exercised there via `?export=1`.
- **`TeamRosterController::download()` 404s for a caller with no distributor
  record**, so a signed-in admin gets 404 rather than 403. The honest negative
  is an unauthenticated request.

### S6b — OPEN: BV ledger export (F21)

`AdminBvLedgerController::export()` and `exportShow()` are **unconverted and
still CSV-only**. All three row sets call `->get()` on unbounded queries:

- `export()` summary branch — `summaryQuery($from, $to, $q)->get()`; every filter
  is nullable, so a bare URL groups the entire `bv_ledger_entries` table.
- `export()` entries branch — `BvLedgerEntry::query()->with(['distributor.user','order'])->dateRange(...)->get()`.
  Whole-table Eloquent hydration plus two eager-loaded relations per row.
- `exportShow()` — bounded only by one distributor.

Converting as-is would put a streaming writer on a fully materialised
collection, leaving the OOM latent. Two things make the fix more than a
`lazy()` swap:

1. `auditExport(...)` needs `$rows->count()` *before* the response returns; a
   lazy source is consumed during streaming, so the audit row's `row_count`
   needs a separate `count()` query or a write after the stream.
2. `exportShow()` accumulates a `$running` balance in row order, which makes the
   ordering guarantee load-bearing under a cursor.

The two BV ledger Blade buttons were reverted to **CSV only** so the UI does not
promise an Excel file the controller cannot produce. `ExportEndpointsTest` and
`exports.spec.js` both assert current CSV behaviour with a `TODO(S6b)` marker.

**Needs a decision from KP** — options: (a) `lazy()`/`cursor()` + a separate
count query for the audit row; (b) a hard row cap like the register's 2000, with
the UI saying so; (c) a queued export that emails or stores the file. Not a
blocker for the other 30.

### Verification — NOT DONE

The folder bridge has no PHP, Composer, Docker or Node; the dev environment runs
through `docker exec arovolife-app` on the Mac. Nothing below has been run:

```
docker exec arovolife-app composer install    # vendor/openspout is not on disk
make pint
make stan
make test
npx playwright test tests/Browser/exports.spec.js
```

Until `composer install` runs, `SheetWriter` references classes that do not
exist on disk and every export will fatal. Run it first.
