<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers\Admin;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Services\ComplianceTerminationSettings;
use App\Modules\Compliance\Services\InactivityTerminationService;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Who is dormant, who is under a §21 notice, and who was closed for dormancy.
 *
 * Read-only apart from one action: withdrawing a notice. That exists because
 * the failure mode worth building for is a sale that exists but is attributed
 * to the wrong ADN — the distributor did sell, our records disagree, and
 * somebody has to be able to stop the clock while it is investigated.
 */
final class AdminDormancyController extends Controller
{
    public function __construct(
        private readonly InactivityTerminationService $inactivity,
        private readonly ComplianceTerminationSettings $settings,
    ) {}

    public function index(Request $request): View
    {
        $filter = (string) $request->query('filter', 'under_notice');
        $now = Carbon::now();
        $cutoff = $now->copy()->subMonths($this->settings->inactivityMonths());

        $query = Distributor::query()->with('user');

        match ($filter) {
            'terminated' => $query->whereNotNull('terminated_at')->orderByDesc('terminated_at'),
            'dormant' => $query->whereNull('inactivity_notice_at')
                ->whereNull('terminated_at')
                ->where('status', 'active')
                ->where('effective_date', '<=', $cutoff)
                ->orderBy('effective_date'),
            default => $query->whereNotNull('inactivity_notice_at')
                ->whereNull('terminated_at')
                ->orderBy('inactivity_notice_expires_at'),
        };

        $distributors = $query->paginate(25)->withQueryString();

        // The "dormant" filter pre-selects on join date only — the real test
        // needs each distributor's last sale, so assess the page we are about
        // to render rather than the whole table.
        $assessments = [];

        foreach ($distributors as $distributor) {
            $assessments[$distributor->id] = $this->inactivity->assess($distributor, $now);
        }

        return view('admin.dormancy.index', [
            'distributors' => $distributors,
            'assessments' => $assessments,
            'filter' => $filter,
            'sweepEnabled' => $this->settings->sweepEnabled(),
            'inactivityMonths' => $this->settings->inactivityMonths(),
            'noticeDays' => $this->settings->noticeDays(),
        ]);
    }

    /**
     * Withdraw a §21 notice by hand.
     *
     * Requires a reason: withdrawing a notice moves a statutory clock, and
     * "why" is the first thing an auditor asks.
     */
    public function withdrawNotice(Request $request, int $id): RedirectResponse
    {
        $distributor = Distributor::find($id);

        if ($distributor === null) {
            throw new NotFoundHttpException;
        }

        if ($distributor->inactivity_notice_at === null) {
            return back()->withErrors(['notice' => 'This distributor is not under a dormancy notice.']);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->inactivity->clearNotice($distributor, $validated['reason']);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'distributor.inactivity_notice_withdrawn',
            'subject_type' => 'distributor',
            'subject_id' => $distributor->id,
            'details' => ['adn' => $distributor->adn, 'reason' => $validated['reason']],
            'ip' => $request->ip(),
        ]);

        return back()->with('status', 'Dormancy notice withdrawn for '.$distributor->adn.'.');
    }
}
