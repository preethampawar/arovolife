<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Grievance\Enums\TicketCategory;
use App\Modules\Grievance\Services\GrievanceComplianceReport;
use App\Modules\Shared\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The monthly grievance compliance report (T&C §11) and its CSV export.
 *
 * The export exists because this is the artefact handed to the Compliance
 * Committee quarterly (policy §6.6) and, on request, to a regulator — and a
 * screen is not something you can attach to a filing.
 */
final class AdminGrievanceReportController extends Controller
{
    private const TRAILING_MONTHS = 12;

    public function __construct(private readonly GrievanceComplianceReport $report) {}

    public function index(Request $request): View
    {
        $month = $this->resolveMonth($request);
        $hidden = $this->hiddenCategoriesFor($request);

        return view('admin.grievances.report', [
            'month' => $month,
            'summary' => $this->report->forMonth($month, $hidden),
            'trailing' => $this->report->trailing(self::TRAILING_MONTHS, $month, $hidden),
            'scoped' => $hidden !== [],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $month = $this->resolveMonth($request);
        $hidden = $this->hiddenCategoriesFor($request);
        $rows = $this->report->trailing(self::TRAILING_MONTHS, $month, $hidden);

        AuditLog::create([
            'actor_id' => $request->user()?->id,
            'action' => 'grievance.report_exported',
            'subject_type' => 'grievance_report',
            'subject_id' => null,
            'details' => [
                'ending_month' => $month->format('Y-m'),
                'months' => self::TRAILING_MONTHS,
                'scoped' => $hidden !== [],
            ],
        ]);

        $filename = 'grievance-compliance-'.$month->format('Y-m').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, GrievanceComplianceReport::csvColumns());

            foreach ($rows as $row) {
                // The report carries complaint subjects and reporter-supplied
                // text. This file goes to the Compliance Committee and, on
                // request, to a regulator — both of whom open it in a
                // spreadsheet, where a cell starting `=` is a formula.
                fputcsv($handle, array_map(
                    static fn (string $column): string => Csv::safe($row[$column] ?? ''),
                    GrievanceComplianceReport::csvColumns()
                ));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Categories this viewer must not see, even as a count.
     *
     * The queue already hides Ethics and Privacy tickets from anyone without
     * `compliance.discipline`. An unscoped aggregate would hand the same person
     * "Ethics & fraud: 3" on the report page — a smaller disclosure than the
     * ticket, but the same disclosure.
     *
     * @return array<int, string>
     */
    private function hiddenCategoriesFor(Request $request): array
    {
        if ($request->user()?->can('compliance.discipline')) {
            return [];
        }

        return TicketCategory::sensitiveValues();
    }

    private function resolveMonth(Request $request): Carbon
    {
        $raw = (string) $request->query('month', '');

        // Accept only YYYY-MM. Anything else silently falls back to this month
        // rather than throwing — a mistyped URL should not 500 a report page.
        if (preg_match('/^\d{4}-\d{2}$/', $raw) === 1) {
            $parsed = Carbon::createFromFormat('Y-m-d', $raw.'-01');

            if ($parsed !== null) {
                return $parsed->startOfMonth();
            }
        }

        return Carbon::now()->startOfMonth();
    }
}
