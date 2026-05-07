<?php

declare(strict_types=1);

namespace App\Modules\Genealogy\Http\Controllers;

use App\Modules\Genealogy\Models\LineChangeRequest;
use App\Modules\Genealogy\Services\Exceptions\LineChangeAlreadyRequestedError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeHasDownlineError;
use App\Modules\Genealogy\Services\Exceptions\LineChangeWindowExpiredError;
use App\Modules\Genealogy\Services\RequestLineChange;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class LineChangeController extends Controller
{
    public function __construct(
        private readonly RequestLineChange $request,
    ) {}

    public function show(): View|RedirectResponse
    {
        $self = Auth::user()?->distributor;
        if ($self === null) {
            return redirect()->route('dashboard');
        }

        $businessDaysSince = (int) $self->effective_date->diffInWeekdays(now());
        $existing = LineChangeRequest::query()
            ->where('distributor_id', $self->id)
            ->latest('requested_at')
            ->first();

        return view('genealogy.line-change', [
            'self' => $self,
            'businessDaysSince' => $businessDaysSince,
            'isWithinWindow' => $businessDaysSince <= 5,
            'existing' => $existing,
        ]);
    }

    public function submit(Request $request): RedirectResponse
    {
        // ADN format: ARO + 6 digits = 9 chars for primaries; secondaries
        // (couple half) are <primary>-S = 11 chars and are intentionally
        // NOT valid line-change targets (they don't occupy a tree slot).
        // We allow 6-16 chars so a tightening of the ADN format doesn't
        // re-trip the validator, then reject couple-secondaries explicitly
        // below.
        $request->merge([
            'to_sponsor_adn' => strtoupper(trim((string) $request->input('to_sponsor_adn', ''))),
        ]);
        $validated = $request->validate([
            // 9-digit numeric ADN; secondary `-S` not accepted here because
            // line-change requests must target a primary record.
            'to_sponsor_adn' => ['required', 'string', 'regex:/^[0-9]{9}$/'],
            'reason' => ['nullable', 'string', 'max:512'],
        ], [
            'to_sponsor_adn.regex' => 'New sponsor ADN must be exactly 9 digits, e.g. 111222333.',
        ]);

        $self = Auth::user()?->distributor;
        if ($self === null) {
            return redirect()->route('dashboard');
        }

        $newSponsor = Distributor::query()
            ->where('adn', $validated['to_sponsor_adn'])
            ->first();
        if ($newSponsor === null) {
            return back()->withInput()->withErrors([
                'to_sponsor_adn' => 'No distributor found with that ADN.',
            ]);
        }

        if ($newSponsor->id === $self->id) {
            return back()->withInput()->withErrors([
                'to_sponsor_adn' => 'You cannot request a line-change to yourself.',
            ]);
        }

        // Couple-secondary cannot be a sponsor target — they don't occupy a
        // tree slot. Detected as: spouse_distributor_id non-null AND
        // is_primary_couple = false (i.e. the row is a "secondary").
        if ($newSponsor->spouse_distributor_id !== null && ! $newSponsor->is_primary_couple) {
            return back()->withInput()->withErrors([
                'to_sponsor_adn' => 'That ADN belongs to a couple-secondary record. Use the primary spouse\'s ADN instead.',
            ]);
        }

        try {
            ($this->request)(
                distributorId: $self->id,
                toSponsorId: $newSponsor->id,
                actorUserId: (int) Auth::id(),
                reason: $validated['reason'] ?? null,
            );
        } catch (LineChangeWindowExpiredError) {
            return back()->withErrors(['line_change' => 'The 5-business-day window has ended.']);
        } catch (LineChangeHasDownlineError) {
            return back()->withErrors(['line_change' => 'You already have referrals in your tree; line-change is not available.']);
        } catch (LineChangeAlreadyRequestedError) {
            return back()->withErrors(['line_change' => 'A line-change request is already pending for your account.']);
        }

        return redirect()->route('line-change.show')->with(
            'status',
            'Your line-change request has been submitted for review.',
        );
    }
}
