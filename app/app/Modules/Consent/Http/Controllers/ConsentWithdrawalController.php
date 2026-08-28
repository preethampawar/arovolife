<?php

declare(strict_types=1);

namespace App\Modules\Consent\Http\Controllers;

use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Services\WithdrawConsent;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The distributor's own consent-withdrawal screen (C-06, R-52).
 *
 * DPDP §6(5) requires withdrawal to be as easy as giving, so this is reachable
 * from the profile in one click and needs no approval from anyone. The two
 * steps are a page that explains the consequence and a POST that performs it —
 * not friction for its own sake, but because the consequence is termination of
 * the ADN and somebody has to have read that sentence before acting on it.
 */
final class ConsentWithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawConsent $withdraw) {}

    public function show(Request $request): View
    {
        $distributor = $this->distributor($request);

        return view('consent.withdraw', [
            'distributor' => $distributor,
            'hasLiveConsent' => $this->withdraw->hasLiveConsent($distributor),
            'consents' => Consent::query()
                ->where('distributor_id', $distributor->id)
                ->orderBy('document_type')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $distributor = $this->distributor($request);

        $validated = $request->validate([
            // Typing the word is the confirmation. A checkbox beside a
            // paragraph gets ticked without the paragraph being read, and this
            // action ends the distributorship.
            'confirmation' => ['required', 'string', 'in:WITHDRAW'],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'confirmation.in' => 'Type WITHDRAW in capitals to confirm.',
            'confirmation.required' => 'Type WITHDRAW in capitals to confirm.',
        ]);

        $count = $this->withdraw->execute(
            $distributor,
            $validated['reason'] ?? 'No reason given.',
        );

        if ($count === 0) {
            return redirect()->route('profile.show')
                ->with('status', 'Your consent had already been withdrawn.');
        }

        // Log them out: the ADN is closed, and leaving the session live would
        // show a dashboard for a distributorship that no longer exists.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('status', 'Your consent has been withdrawn and your ADN closed. Records we are '
                .'required by law to keep are retained; everything else is deleted on the schedule set '
                .'out in the Privacy Policy.');
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
