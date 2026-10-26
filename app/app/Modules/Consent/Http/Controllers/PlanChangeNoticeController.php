<?php

declare(strict_types=1);

namespace App\Modules\Consent\Http\Controllers;

use App\Modules\Consent\Services\PlanChangeNotices;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The two buttons of the plan-change pop-up (plan page §12, R-123). The
 * pop-up is no longer rendered (user decision 2026-10-26); these endpoints
 * are kept so it can be restored without rebuilding the consent record.
 *
 * Agreeing is the distributor's own act, so it is refused while staff are
 * impersonating them: a consent recorded from a borrowed session would read
 * as theirs.
 */
final class PlanChangeNoticeController extends Controller
{
    public const LATER_SESSION_KEY = 'plan_change_notice.later';

    public function __construct(
        private readonly PlanChangeNotices $notices,
    ) {}

    public function accept(Request $request): RedirectResponse
    {
        $distributor = $this->distributor($request);

        if ($request->session()->has('impersonator_id')) {
            throw new AccessDeniedHttpException('Only the distributor can agree to plan changes.');
        }

        $validated = $request->validate([
            'hash' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ]);

        if (! $this->notices->enabled()) {
            throw new NotFoundHttpException;
        }

        $consent = $this->notices->accept(
            $distributor,
            (string) $validated['hash'],
            (string) $request->ip(),
            (string) $request->userAgent(),
        );

        if ($consent === null) {
            $request->session()->forget(self::LATER_SESSION_KEY);

            return back()->with('status', 'The Compensation Plan changed again while you were reading it. Please read the new list of changes.');
        }

        $label = $this->notices->published()['label'] ?? null;

        return back()->with('status', 'Thank you. We have recorded that you read and agreed to the Compensation Plan'
            .($label !== null ? ' version '.$label : '')
            .' on '.$consent->accepted_at->copy()->timezone('Asia/Kolkata')->format('d M Y, H:i').' IST. A copy has been emailed to you.');
    }

    public function later(Request $request): RedirectResponse
    {
        $this->distributor($request);

        $validated = $request->validate([
            'hash' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ]);

        $request->session()->put(self::LATER_SESSION_KEY, (string) $validated['hash']);

        return back();
    }

    private function distributor(Request $request): Distributor
    {
        $distributor = $request->user()?->distributor;

        if ($distributor === null) {
            throw new NotFoundHttpException;
        }

        return $distributor;
    }
}
