<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers\Registration;

use App\Modules\Identity\Models\RegistrationDraft;
use App\Modules\Identity\Services\DraftStateService;
use App\Modules\Identity\Services\WizardStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class DraftResumeController extends Controller
{
    public function __construct(
        private readonly DraftStateService $drafts,
        private readonly WizardStateService $wizard,
    ) {}

    public function show(Request $request, RegistrationDraft $draft): RedirectResponse
    {
        if ($draft->expires_at->isPast()) {
            return redirect()->route('register')
                ->with('status', 'This resume link has expired. Please use your referral link to start a new registration.');
        }

        if (Auth::check() && Auth::id() !== $draft->user_id) {
            return redirect()->route('login')
                ->with('status', 'This registration link belongs to a different account. Please sign in to the correct account first.');
        }

        // Check whether the placement position is still available.
        $takenSlots = (int) DB::table('distributors')
            ->where('placement_parent_id', $draft->placement_id)
            ->whereNotNull('placement_side')
            ->count();

        if ($takenSlots >= 2) {
            $this->wizard->clearIntent();

            return redirect()->route('join.show')
                ->with('status', 'The placement position from your original invitation is no longer available. Please choose a new one.');
        }

        if (Auth::loginUsingId($draft->user_id) === false) {
            return redirect()->route('login')
                ->with('status', 'We could not restore your registration session. Please sign in.');
        }

        $request->session()->regenerate();
        $this->drafts->restoreToWizard($draft, $this->wizard);

        // Re-issue a new raw token so the cookie works on this new device.
        $newRawToken = $this->drafts->create(
            $draft->user_id,
            $draft->sponsor_id,
            $draft->placement_id,
            $draft->side_opt,
            json_decode(Crypt::decryptString($draft->payload_enc), true) ?? [],
            $draft->current_step,
        );

        return redirect()
            ->route(WizardStateService::stepRoute($draft->current_step))
            ->withCookie(cookie('av_draft', $newRawToken, 7 * 24 * 60, '/', null, true, true));
    }
}
