<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Http\Controllers;

use App\Modules\Grievance\Enums\EscalationLevel;
use App\Modules\Grievance\Models\Ticket;
use App\Modules\Grievance\Services\GrievanceSettingsService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * The distributor-facing "Help" page.
 *
 * It exists because the published Grievance Redressal Policy names it: §1 and
 * §3.1 both tell distributors to go to "Dashboard → Help → Raise a Grievance".
 * It lives in the Grievance module for that reason — if it ever grows into a
 * general support hub with FAQs and mentor calls, it should move.
 *
 * Shows only the complainant's own open grievance count. No aggregate figures,
 * no earnings, nothing forward-looking (hard rule 3).
 */
final class SupportHubController extends Controller
{
    public function __construct(private readonly GrievanceSettingsService $settings) {}

    public function index(Request $request): View
    {
        $distributor = $request->user()?->distributor;

        $openCount = $distributor === null
            ? 0
            : Ticket::where('distributor_id', $distributor->id)->unsettled()->count();

        return view('grievance.help', [
            'openGrievances' => $openCount,
            'officers' => [
                EscalationLevel::GrievanceOfficer->label() => $this->settings->mailboxFor(EscalationLevel::GrievanceOfficer),
                EscalationLevel::NodalOfficer->label() => $this->settings->mailboxFor(EscalationLevel::NodalOfficer),
                EscalationLevel::ComplianceCommittee->label() => $this->settings->mailboxFor(EscalationLevel::ComplianceCommittee),
            ],
        ]);
    }
}
